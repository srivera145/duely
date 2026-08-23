<?php

namespace Keel\App\Services;

use Keel\Core\Env;

/**
 * Authenticated encryption for credentials at rest.
 *
 * AES-256-GCM. The key comes from APP_ENCRYPTION_KEY in the environment and is
 * never read from, written to, or derivable from the database — a dump of the
 * `email_accounts` table on its own is inert.
 *
 * Envelope layout, stored as one BLOB:
 *
 *     byte 0        format version (0x01)
 *     bytes 1-12    IV / nonce (12 bytes, random per value)
 *     bytes 13-28   GCM authentication tag (16 bytes)
 *     bytes 29..    ciphertext
 *
 * The tag is verified on every decrypt. A wrong key or a tampered byte raises
 * CryptoException rather than returning garbage.
 */
class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const VERSION = "\x01";
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;
    private const KEY_LENGTH = 32;

    public static function encrypt(string $plaintext): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new CryptoException('Encryption failed.');
        }

        return self::VERSION . $iv . $tag . $ciphertext;
    }

    public static function decrypt(string $envelope): string
    {
        $header = 1 + self::IV_LENGTH + self::TAG_LENGTH;

        if (strlen($envelope) <= $header || $envelope[0] !== self::VERSION) {
            throw new CryptoException('Ciphertext envelope is malformed or written by an unknown version.');
        }

        $iv = substr($envelope, 1, self::IV_LENGTH);
        $tag = substr($envelope, 1 + self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($envelope, $header);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        // openssl_decrypt returns false when the GCM tag does not verify. That
        // means a wrong key or modified ciphertext, and it must never be
        // swallowed into a null/empty credential.
        if ($plaintext === false) {
            throw new CryptoException(
                'Decryption failed: the authentication tag did not verify. '
                . 'APP_ENCRYPTION_KEY may have changed, or the stored value was tampered with.'
            );
        }

        return $plaintext;
    }

    public static function encryptNullable(?string $plaintext): ?string
    {
        return ($plaintext === null || $plaintext === '') ? null : self::encrypt($plaintext);
    }

    public static function decryptNullable(?string $envelope): ?string
    {
        return ($envelope === null || $envelope === '') ? null : self::decrypt($envelope);
    }

    /**
     * Generate a key suitable for APP_ENCRYPTION_KEY.
     */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(self::KEY_LENGTH));
    }

    private static function key(): string
    {
        $encoded = trim((string) Env::get('APP_ENCRYPTION_KEY', ''));

        if ($encoded === '') {
            throw new CryptoException(
                'APP_ENCRYPTION_KEY is not set. Generate one with: '
                . 'php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"'
            );
        }

        $key = base64_decode($encoded, true);

        if ($key === false || strlen($key) !== self::KEY_LENGTH) {
            throw new CryptoException('APP_ENCRYPTION_KEY must be a base64-encoded 32-byte key.');
        }

        return $key;
    }
}
