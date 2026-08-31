<?php

declare(strict_types=1);

/**
 * Tell the waitlist that signup is open.
 *
 * Deliberately its own command rather than a step in the worker loop. A job that
 * mails an entire list should run because somebody decided to run it, not
 * because a process restarted.
 *
 *   php bin/announce-signup.php --dry-run    count who would be mailed
 *   php bin/announce-signup.php              send
 *
 * Safe to run twice: `announced_at` is claimed conditionally before each send,
 * so a second pass finds nobody.
 */

use Keel\App\Jobs\AnnounceSignupToWaitlistJob;
use Keel\Core\Env;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

Env::load(dirname(__DIR__));

$dryRun = in_array('--dry-run', $argv, true);

$result = (new AnnounceSignupToWaitlistJob())->run($dryRun);

fwrite(STDOUT, sprintf(
    "[duely] waitlist announcement%s: eligible=%d sent=%d failed=%d\n",
    $dryRun ? ' (dry run, nothing sent)' : '',
    $result['eligible'],
    $result['sent'],
    $result['failed']
));

foreach ($result['errors'] as $error) {
    fwrite(STDERR, '[duely] ' . $error . "\n");
}

exit($result['failed'] > 0 ? 1 : 0);
