<?php

namespace Keel\App\Services;

/**
 * Maps an email address to the SMTP/IMAP settings its provider expects.
 *
 * The user types their address, the form prefills, and every field stays
 * editable — a preset is a head start, never a constraint. Custom domains that
 * match no preset fall back to an MX lookup, which resolves the large share of
 * cPanel and Google/Microsoft-hosted domains that do not use a known suffix.
 */
class ProviderPresets
{
    public const PROVIDER_SMTP = 'smtp';
    public const PROVIDER_GMAIL = 'gmail';
    public const PROVIDER_OUTLOOK = 'outlook';

    /**
     * Known consumer and business mail hosts, keyed by the domain the user types.
     *
     * `app_password` marks providers that reject a normal account password over
     * SMTP/IMAP outright — the single biggest source of onboarding failure, so
     * MailAccountService can warn before the user even hits Test.
     */
    private const PRESETS = [
        'gmail.com' => [
            'label' => 'Gmail',
            'provider' => self::PROVIDER_GMAIL,
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'imap_host' => 'imap.gmail.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'app_password' => true,
            'help_url' => 'https://myaccount.google.com/apppasswords',
        ],
        'googlemail.com' => ['alias_of' => 'gmail.com'],

        'outlook.com' => [
            'label' => 'Outlook',
            'provider' => self::PROVIDER_OUTLOOK,
            'smtp_host' => 'smtp-mail.outlook.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'imap_host' => 'outlook.office365.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'app_password' => true,
            'help_url' => 'https://account.live.com/proofs/AppPassword',
        ],
        'hotmail.com' => ['alias_of' => 'outlook.com'],
        'live.com' => ['alias_of' => 'outlook.com'],
        'msn.com' => ['alias_of' => 'outlook.com'],
        'office365.com' => ['alias_of' => 'outlook.com'],

        'yahoo.com' => [
            'label' => 'Yahoo Mail',
            'provider' => self::PROVIDER_SMTP,
            'smtp_host' => 'smtp.mail.yahoo.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'imap_host' => 'imap.mail.yahoo.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'app_password' => true,
            'help_url' => 'https://login.yahoo.com/account/security/app-passwords',
        ],
        'ymail.com' => ['alias_of' => 'yahoo.com'],

        'fastmail.com' => [
            'label' => 'Fastmail',
            'provider' => self::PROVIDER_SMTP,
            'smtp_host' => 'smtp.fastmail.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'imap_host' => 'imap.fastmail.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'app_password' => true,
            'help_url' => 'https://app.fastmail.com/settings/security/devicekeys',
        ],
        'fastmail.fm' => ['alias_of' => 'fastmail.com'],

        'zoho.com' => [
            'label' => 'Zoho Mail',
            'provider' => self::PROVIDER_SMTP,
            'smtp_host' => 'smtp.zoho.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'imap_host' => 'imap.zoho.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'app_password' => true,
            'help_url' => 'https://accounts.zoho.com/home#security/app_password',
        ],
        'zohomail.com' => ['alias_of' => 'zoho.com'],

        'icloud.com' => [
            'label' => 'iCloud Mail',
            'provider' => self::PROVIDER_SMTP,
            'smtp_host' => 'smtp.mail.me.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'imap_host' => 'imap.mail.me.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'app_password' => true,
            'help_url' => 'https://account.apple.com/account/manage',
        ],
        'me.com' => ['alias_of' => 'icloud.com'],
        'mac.com' => ['alias_of' => 'icloud.com'],

        'proton.me' => [
            'label' => 'Proton Mail (Bridge required)',
            'provider' => self::PROVIDER_SMTP,
            'smtp_host' => '127.0.0.1',
            'smtp_port' => 1025,
            'smtp_encryption' => 'none',
            'imap_host' => '127.0.0.1',
            'imap_port' => 1143,
            'imap_encryption' => 'none',
            'app_password' => true,
            'help_url' => 'https://proton.me/mail/bridge',
            'note' => 'Proton only exposes SMTP and IMAP through Proton Bridge running on the same machine as Duely.',
        ],
        'protonmail.com' => ['alias_of' => 'proton.me'],
    ];

    /**
     * MX hostname fragments that identify a provider behind a custom domain.
     * Ordered most specific first; the first fragment found in any MX record wins.
     */
    private const MX_SIGNATURES = [
        'google.com' => 'gmail.com',
        'googlemail.com' => 'gmail.com',
        'outlook.com' => 'outlook.com',
        'protection.outlook.com' => 'outlook.com',
        'messagingengine.com' => 'fastmail.com',
        'zoho.com' => 'zoho.com',
        'zoho.eu' => 'zoho.com',
        'icloud.com' => 'icloud.com',
        'yahoodns.net' => 'yahoo.com',
    ];

    /**
     * Best-guess settings for an address.
     *
     * Resolution order: exact domain preset, then MX signature, then a
     * conventional `mail.<domain>` guess that matches most cPanel hosts.
     *
     * @return array{
     *     provider:string, label:string, source:string, domain:string,
     *     smtp_host:string, smtp_port:int, smtp_encryption:string,
     *     imap_host:string, imap_port:int, imap_encryption:string,
     *     smtp_username:string, imap_username:string,
     *     app_password:bool, help_url:?string, note:?string, confident:bool
     * }
     */
    public static function forEmail(string $email): array
    {
        $email = strtolower(trim($email));
        $domain = self::domainOf($email);

        if ($domain === '') {
            return self::fallback($email, '', 'unknown');
        }

        $preset = self::lookup($domain);

        if ($preset !== null) {
            return self::hydrate($preset, $email, $domain, 'preset');
        }

        $mxDomain = self::resolveViaMx($domain);

        if ($mxDomain !== null) {
            $preset = self::lookup($mxDomain);

            if ($preset !== null) {
                return self::hydrate($preset, $email, $domain, 'mx');
            }
        }

        return self::fallback($email, $domain, 'guess');
    }

    /**
     * Exact-domain preset lookup, following alias chains.
     */
    public static function lookup(string $domain): ?array
    {
        $domain = strtolower(trim($domain));
        $preset = self::PRESETS[$domain] ?? null;

        if ($preset === null) {
            return null;
        }

        if (isset($preset['alias_of'])) {
            $preset = self::PRESETS[$preset['alias_of']] ?? null;
        }

        return $preset;
    }

    /**
     * Providers offered as pick-from-a-list options in the UI.
     *
     * @return array<int, array{domain:string, label:string, provider:string}>
     */
    public static function catalogue(): array
    {
        $catalogue = [];

        foreach (self::PRESETS as $domain => $preset) {
            if (isset($preset['alias_of'])) {
                continue;
            }

            $catalogue[] = [
                'domain' => $domain,
                'label' => $preset['label'],
                'provider' => $preset['provider'],
            ];
        }

        return $catalogue;
    }

    public static function domainOf(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? '' : strtolower(trim(substr($email, $at + 1)));
    }

    /**
     * Does this provider refuse ordinary account passwords over SMTP/IMAP?
     */
    public static function requiresAppPassword(string $email): bool
    {
        return (bool) self::forEmail($email)['app_password'];
    }

    // --------------------------------------------------------------- internals

    /**
     * Follow the domain's MX records back to a known provider.
     */
    private static function resolveViaMx(string $domain): ?string
    {
        if (!function_exists('getmxrr')) {
            return null;
        }

        $hosts = [];

        // getmxrr emits a warning for domains with no MX record; the boolean
        // return is the signal we actually want.
        if (!@getmxrr($domain, $hosts) || $hosts === []) {
            return null;
        }

        foreach ($hosts as $host) {
            $host = strtolower((string) $host);

            foreach (self::MX_SIGNATURES as $fragment => $presetDomain) {
                if (str_contains($host, $fragment)) {
                    return $presetDomain;
                }
            }
        }

        return null;
    }

    private static function hydrate(array $preset, string $email, string $domain, string $source): array
    {
        return [
            'provider' => $preset['provider'],
            'label' => $preset['label'],
            'source' => $source,
            'domain' => $domain,
            'smtp_host' => $preset['smtp_host'],
            'smtp_port' => (int) $preset['smtp_port'],
            'smtp_encryption' => $preset['smtp_encryption'],
            'imap_host' => $preset['imap_host'],
            'imap_port' => (int) $preset['imap_port'],
            'imap_encryption' => $preset['imap_encryption'],
            'smtp_username' => $email,
            'imap_username' => $email,
            'app_password' => (bool) ($preset['app_password'] ?? false),
            'help_url' => $preset['help_url'] ?? null,
            'note' => $preset['note'] ?? null,
            'confident' => true,
        ];
    }

    /**
     * The cPanel / custom-domain convention: mail.<domain> on the standard
     * submission and IMAPS ports. Flagged `confident => false` so the UI can
     * tell the user these are guesses worth checking with their host.
     */
    private static function fallback(string $email, string $domain, string $source): array
    {
        $host = $domain === '' ? '' : 'mail.' . $domain;

        return [
            'provider' => self::PROVIDER_SMTP,
            'label' => $domain === '' ? 'Custom' : $domain,
            'source' => $source,
            'domain' => $domain,
            'smtp_host' => $host,
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'imap_host' => $host,
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_username' => $email,
            'imap_username' => $email,
            'app_password' => false,
            'help_url' => null,
            'note' => 'We could not identify this provider, so these are the most common settings for a custom domain. Your host can confirm them.',
            'confident' => false,
        ];
    }
}
