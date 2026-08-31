<?php

declare(strict_types=1);

/**
 * Duely's worker.
 *
 * Does two jobs on one loop:
 *
 *   1. Drains Keel's `jobs` table, so anything queued through Keel\Core\Queue
 *      still runs. That logic lives in database/queue-work.php and is reused
 *      here rather than reimplemented.
 *   2. Ticks the cadence engine on an interval: find chases that are due,
 *      render the next reminder, and send it.
 *   3. Polls connected inboxes for replies and bounces, so a client who
 *      answers is never sent the next rung of the ladder.
 *
 * Usage:
 *
 *   php bin/worker.php                 run forever
 *   php bin/worker.php --once          one pass, then exit (for cron)
 *   php bin/worker.php --chases-only   skip the Keel job queue
 *   php bin/worker.php --no-poll       skip inbox polling
 *   php bin/worker.php --tenant=7      restrict the cadence tick to one tenant
 *   php bin/worker.php --no-sleep      do not pause between sends (testing)
 *
 * The process handles SIGTERM and SIGINT where pcntl is available, finishing
 * the send in flight before exiting. That matters: killing the process between
 * handing a message to SMTP and recording the outcome is exactly the case the
 * dispatched_at column exists to make safe, and a clean shutdown avoids it.
 */

use Keel\App\Jobs\PollInboxesJob;
use Keel\App\Jobs\ProcessDueChasesJob;
use Keel\App\Jobs\ReleaseExpiredFoundingSlotsJob;
use Keel\Core\Env;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$basePath = dirname(__DIR__);
Env::load($basePath);

// Reuse Keel's queue draining rather than duplicating it. The file exposes
// processAvailableJobs()/processOneJob() as plain functions and only runs a
// loop of its own when invoked directly, which is why it is guarded.
define('DUELY_WORKER_EMBEDDED', true);
require $basePath . '/database/queue-work.php';

$options = parseArguments($argv);

$running = true;

// Finish what is in flight, then stop.
// pcntl is absent on Windows, where the constants are undefined too.
if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')
    && defined('SIGTERM') && defined('SIGINT')) {
    pcntl_async_signals(true);

    foreach ([SIGTERM, SIGINT] as $signal) {
        pcntl_signal($signal, static function () use (&$running): void {
            $running = false;
            fwrite(STDOUT, "\n[duely] Shutdown requested; finishing the current send.\n");
        });
    }
}

$sleeper = $options['no_sleep']
    ? null
    : static function (int $seconds): void {
        sleep($seconds);
    };

try {
    if ($options['once']) {
        runOnce($options, $sleeper);
        exit(0);
    }

    $lastChaseTick = 0;
    $lastPollTick = 0;
    // Daily housekeeping. Starts at 0 so it runs once on boot rather than
    // waiting a day, which also means a restart is a way to force it.
    $lastDailyTick = 0;

    while ($running) {
        $didJobWork = false;

        if (!$options['chases_only']) {
            $didJobWork = processOneJob('default');
        }

        // The cadence tick is time-based rather than per-iteration, so an idle
        // worker is not hammering the database looking for due chases.
        if (time() - $lastChaseTick >= $options['interval']) {
            $lastChaseTick = time();
            $totals = tickChases($options, $sleeper);

            if ($totals['sent'] > 0 || $totals['failed'] > 0) {
                report($totals);
            }
        }

        // Replies are checked more often than they are strictly needed,
        // because noticing one late is the failure that matters most.
        if (!$options['no_poll'] && time() - $lastPollTick >= PollInboxesJob::INTERVAL_SECONDS) {
            $lastPollTick = time();
            $inbox = pollInboxes($options);

            if ($inbox['recorded'] > 0 || $inbox['errors'] !== []) {
                reportInbox($inbox);
            }
        }

        // Founding holds. Once a day is plenty: the window is thirty days and
        // the warning goes out a week ahead, so a few hours of drift changes
        // nothing about who keeps their place.
        if (time() - $lastDailyTick >= 86400) {
            $lastDailyTick = time();
            $founding = releaseExpiredFoundingSlots();

            if ($founding['warned'] > 0 || $founding['released'] > 0 || $founding['errors'] !== []) {
                reportFounding($founding);
            }
        }

        if (!$didJobWork) {
            sleep(2);
        }
    }

    fwrite(STDOUT, "[duely] Stopped cleanly.\n");
    exit(0);
} catch (\Throwable $exception) {
    fwrite(STDERR, '[duely] ' . $exception->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------- functions

function runOnce(array $options, ?callable $sleeper): void
{
    if (!$options['chases_only']) {
        processAvailableJobs('default');
    }

    // Poll before sending, so a reply that arrived since the last run pauses
    // the chase before this run can send the next rung.
    if (!$options['no_poll']) {
        reportInbox(pollInboxes($options));
    }

    report(tickChases($options, $sleeper));

    // --once is what a cron-driven deployment calls, so the daily work has to
    // happen on that path too. The job is idempotent -- a slot already released
    // matches nothing on the second pass -- so running it more often than daily
    // is harmless.
    reportFounding(releaseExpiredFoundingSlots());
}

/**
 * @return array{warned:int, released:int, errors:string[]}
 */
function releaseExpiredFoundingSlots(): array
{
    return (new ReleaseExpiredFoundingSlotsJob())->run();
}

function reportFounding(array $totals): void
{
    if ($totals['warned'] === 0 && $totals['released'] === 0 && $totals['errors'] === []) {
        return;
    }

    fwrite(STDOUT, sprintf(
        "[duely] %s  founding: warned=%d released=%d\n",
        date('Y-m-d H:i:s'),
        $totals['warned'],
        $totals['released']
    ));

    foreach ($totals['errors'] as $error) {
        fwrite(STDERR, '[duely] founding: ' . $error . "\n");
    }
}

/**
 * @return array{tenants:int, accounts:int, examined:int, recorded:int, paused:int, stopped:int, errors:string[]}
 */
function pollInboxes(array $options): array
{
    return (new PollInboxesJob())->run($options['tenant']);
}

function reportInbox(array $totals): void
{
    // `examined` is everything the poller looked at; `attached` is what belonged
    // to a chase and was kept; `ignored` is unrelated mail that was counted and
    // then deliberately not stored. The gap between the first two is the number
    // worth watching, and keeping `examined` is what lets the operations page
    // still show throughput.
    fwrite(STDOUT, sprintf(
        "[duely] %s  inbox: accounts=%d examined=%d attached=%d ignored=%d paused=%d stopped=%d\n",
        date('Y-m-d H:i:s'),
        $totals['accounts'],
        $totals['examined'],
        $totals['recorded'],
        $totals['unmatched'],
        $totals['paused'],
        $totals['stopped']
    ));

    foreach ($totals['errors'] as $error) {
        fwrite(STDERR, '[duely] inbox: ' . $error . "\n");
    }
}

/**
 * @return array{tenants:int, sent:int, skipped:int, failed:int}
 */
function tickChases(array $options, ?callable $sleeper): array
{
    return (new ProcessDueChasesJob())->run($options['tenant'], null, $sleeper);
}

function report(array $totals): void
{
    fwrite(STDOUT, sprintf(
        "[duely] %s  tenants=%d sent=%d skipped=%d failed=%d\n",
        date('Y-m-d H:i:s'),
        $totals['tenants'],
        $totals['sent'],
        $totals['skipped'],
        $totals['failed']
    ));
}

/**
 * @param string[] $argv
 * @return array{once:bool, chases_only:bool, no_poll:bool, no_sleep:bool, tenant:?int, interval:int}
 */
function parseArguments(array $argv): array
{
    $tenant = null;
    $interval = 60;

    foreach ($argv as $argument) {
        if (preg_match('/^--tenant=(\d+)$/', $argument, $matches) === 1) {
            $tenant = (int) $matches[1];
        }

        if (preg_match('/^--interval=(\d+)$/', $argument, $matches) === 1) {
            $interval = max(5, (int) $matches[1]);
        }
    }

    return [
        'once' => in_array('--once', $argv, true),
        'chases_only' => in_array('--chases-only', $argv, true),
        'no_poll' => in_array('--no-poll', $argv, true),
        'no_sleep' => in_array('--no-sleep', $argv, true),
        'tenant' => $tenant,
        'interval' => $interval,
    ];
}
