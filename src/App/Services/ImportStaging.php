<?php

namespace Keel\App\Services;

use RuntimeException;

/**
 * Holds an uploaded CSV between the steps of the import wizard.
 *
 * Upload never commits. The file is parked here through preview, mapping and
 * validation, and is only read for writes once the user confirms. Files live
 * under a per-tenant directory and are addressed by an unguessable token that
 * is checked against the tenant on every read, so one tenant cannot reach
 * another's staged upload even with a stolen token.
 *
 * Keel's Storage helper is not used: it enforces an image/PDF allowlist with a
 * MIME map, and browsers report CSV as anything from text/csv to
 * application/vnd.ms-excel to application/octet-stream.
 */
class ImportStaging
{
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const MAX_AGE_SECONDS = 6 * 3600;

    /**
     * Accept an upload and park it. Returns the token that addresses it.
     *
     * @param array{tmp_name?:string, name?:string, size?:int, error?:int} $file
     */
    public static function store(int $tenantId, array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadErrorMessage($error));
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');

        if ($tmpName === '' || !is_readable($tmpName)) {
            throw new RuntimeException('The upload did not arrive intact. Try again.');
        }

        // is_uploaded_file is the real check in a web request; tests move files
        // into place directly, so a readable temp path is accepted there.
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmpName)) {
            throw new RuntimeException('That file was not uploaded through the form.');
        }

        $size = (int) ($file['size'] ?? filesize($tmpName));

        if ($size <= 0) {
            throw new RuntimeException('That file is empty.');
        }

        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('That file is larger than ' . (self::MAX_BYTES / 1024 / 1024) . 'MB.');
        }

        $originalName = (string) ($file['name'] ?? 'import.csv');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, ['csv', 'txt', 'tsv'], true)) {
            throw new RuntimeException('Upload a .csv file. Most spreadsheets can export one from File → Download.');
        }

        $contents = (string) file_get_contents($tmpName);

        if (!self::looksLikeText($contents)) {
            throw new RuntimeException('That does not look like a CSV. If it is an .xlsx, export it as CSV first.');
        }

        // Global sweep: any active upload clears every tenant's stale files.
        self::sweep();

        $token = bin2hex(random_bytes(16));
        $path = self::pathFor($tenantId, $token);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not prepare the import directory.');
        }

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Could not save the uploaded file.');
        }

        file_put_contents($path . '.meta', json_encode([
            'original_name' => $originalName,
            'size' => $size,
            'uploaded_at' => date('c'),
        ]));

        return [
            'token' => $token,
            'original_name' => $originalName,
            'size' => $size,
        ];
    }

    /**
     * Read a staged file back. The tenant id is part of the path, so a token
     * from another tenant simply does not resolve.
     */
    public static function contents(int $tenantId, string $token): string
    {
        $path = self::pathFor($tenantId, self::assertToken($token));

        if (!is_file($path)) {
            throw new RuntimeException('That upload has expired. Upload the file again.');
        }

        return (string) file_get_contents($path);
    }

    public static function exists(int $tenantId, string $token): bool
    {
        return is_file(self::pathFor($tenantId, self::assertToken($token)));
    }

    /**
     * @return array{original_name:string, size:int, uploaded_at:string}|null
     */
    public static function meta(int $tenantId, string $token): ?array
    {
        $path = self::pathFor($tenantId, self::assertToken($token)) . '.meta';

        if (!is_file($path)) {
            return null;
        }

        $meta = json_decode((string) file_get_contents($path), true);

        return is_array($meta) ? $meta : null;
    }

    public static function discard(int $tenantId, string $token): void
    {
        $path = self::pathFor($tenantId, self::assertToken($token));

        @unlink($path);
        @unlink($path . '.meta');
    }

    // ------------------------------------------------------------- internals

    /**
     * Remove stale uploads across every tenant, not just the caller's.
     *
     * An abandoned wizard leaves a file holding client names, emails and
     * amounts. Sweeping only the uploading tenant's directory would mean a
     * tenant who abandons an import and never returns keeps that data on disk
     * forever. Sweeping globally costs one directory scan and makes retention
     * depend on the install being used at all, rather than on that particular
     * tenant coming back.
     */
    public static function sweep(?int $tenantId = null): int
    {
        $root = dirname(__DIR__, 3) . '/storage/app/imports';

        if (!is_dir($root)) {
            return 0;
        }

        $directories = $tenantId === null
            ? (glob($root . '/*', GLOB_ONLYDIR) ?: [])
            : [self::directoryFor($tenantId)];

        $cutoff = time() - self::MAX_AGE_SECONDS;
        $removed = 0;

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            foreach (glob($directory . '/*') ?: [] as $file) {
                if (is_file($file) && filemtime($file) < $cutoff) {
                    @unlink($file);
                    $removed++;
                }
            }

            // Drop the directory too once it has emptied out.
            if ((glob($directory . '/*') ?: []) === []) {
                @rmdir($directory);
            }
        }

        return $removed;
    }

    private static function assertToken(string $token): string
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            throw new RuntimeException('That upload reference is not valid.');
        }

        return $token;
    }

    private static function directoryFor(int $tenantId): string
    {
        return dirname(__DIR__, 3) . '/storage/app/imports/' . $tenantId;
    }

    private static function pathFor(int $tenantId, string $token): string
    {
        return self::directoryFor($tenantId) . '/' . $token . '.csv';
    }

    /**
     * Reject binary payloads renamed to .csv (an .xlsx is a zip, for instance).
     */
    private static function looksLikeText(string $contents): bool
    {
        $sample = substr($contents, 0, 4096);

        if ($sample === '') {
            return false;
        }

        // A NUL byte in the first block means this is not a text file.
        if (str_contains($sample, "\0")) {
            return false;
        }

        return true;
    }

    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is too large to upload.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE => 'Choose a CSV file to upload.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not store the upload.',
            default => 'The upload failed. Try again.',
        };
    }
}
