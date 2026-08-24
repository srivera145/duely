<?php

/**
 * Claim one founding slot, in its own process.
 *
 * The cap of fifty is the one piece of Phase 9 that a single-threaded test
 * cannot honestly exercise: counting rows and then inserting passes every
 * sequential test and still produces a fifty-first member the first time two
 * signups land together. This script exists so the test can start real
 * processes that genuinely race for the last slots.
 *
 * It waits on a start file before claiming, so the parent can line the
 * contenders up and release them at once rather than letting the first one
 * finish before the last has booted.
 *
 * Run: php claim-founding-slot.php <tenantId> <startFile> <resultFile>
 */
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Keel\App\Services\PlanService;
use Keel\Core\Database;

$tenantId = (int) ($argv[1] ?? 0);
$startFile = (string) ($argv[2] ?? '');
$resultFile = (string) ($argv[3] ?? '');

$basePath = dirname(__DIR__, 2);

if (file_exists($basePath . '/.env')) {
    Dotenv::createImmutable($basePath, '.env')->safeLoad();
}

if (file_exists($basePath . '/.env.testing')) {
    Dotenv::createImmutable($basePath, '.env.testing')->safeLoad();
}

$database = trim((string) ($_ENV['DB_DATABASE_TEST'] ?? ''));

if ($database === '' || $tenantId <= 0 || $resultFile === '') {
    fwrite(STDERR, "usage: claim-founding-slot.php <tenantId> <startFile> <resultFile>\n");
    exit(2);
}

$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
$_ENV['DB_DATABASE'] = $database;
$_SERVER['DB_DATABASE'] = $database;

Database::resetConnection();

// Connect and prepare before the starting gun, so what races is the claim
// itself and not PHP's startup.
Database::connection()->query('SELECT 1');

$deadline = microtime(true) + 10.0;

while ($startFile !== '' && !file_exists($startFile) && microtime(true) < $deadline) {
    usleep(200);
}

try {
    $result = (new PlanService())->claimFoundingSlot($tenantId);
} catch (Throwable $exception) {
    $result = ['claimed' => false, 'slot' => null, 'reason' => 'error: ' . $exception->getMessage()];
}

file_put_contents($resultFile, (string) json_encode([
    'tenant_id' => $tenantId,
    'claimed' => (bool) $result['claimed'],
    'slot' => $result['slot'],
    'reason' => $result['reason'],
]));

exit(0);
