<?php

namespace Keel\App\Services;

/**
 * Copy for the single highest-drop-off moment in onboarding.
 *
 * When a Gmail or Outlook user pastes their normal account password, the server
 * rejects it and most apps answer with "authentication failed" — which reads as
 * "you typed it wrong", so the user retypes the same password and fails again.
 *
 * The fix is to name the real cause in the first sentence, then give numbered
 * steps and a direct link to the page that issues the credential. Everything
 * here is written to be read once, while frustrated, and acted on immediately.
 */
class AppPasswordGuidance
{
    /**
     * Providers that reject ordinary account passwords over SMTP and IMAP.
     */
    private const REQUIRES_APP_PASSWORD = [
        ProviderPresets::PROVIDER_GMAIL,
        ProviderPresets::PROVIDER_OUTLOOK,
    ];

    public static function providerRequiresAppPassword(string $provider): bool
    {
        return in_array($provider, self::REQUIRES_APP_PASSWORD, true);
    }

    /**
     * Inline instructions to render beside the failed password field.
     *
     * @return array{
     *     provider:string, title:string, summary:string,
     *     steps:string[], link_url:string, link_label:string, footnote:?string
     * }|null
     */
    public static function forProvider(string $provider): ?array
    {
        return match ($provider) {
            ProviderPresets::PROVIDER_GMAIL => [
                'provider' => $provider,
                'title' => 'Gmail needs an app password, not your Google password',
                'summary' => 'Google blocks apps from signing in with your normal password. You need a 16-character app password instead. It takes about a minute to create, and you can revoke it any time without changing your Google password.',
                'steps' => [
                    'Make sure 2-Step Verification is turned on for your Google account — app passwords do not exist without it.',
                    'Open Google\'s app passwords page using the button below.',
                    'Type "Duely" as the app name and select Create.',
                    'Copy the 16-character password Google shows you and paste it here. Spaces do not matter.',
                ],
                'link_url' => 'https://myaccount.google.com/apppasswords',
                'link_label' => 'Create a Google app password',
                'footnote' => 'If that page says the option is unavailable, 2-Step Verification is not on yet. Turn it on at myaccount.google.com/security, then come back.',
            ],

            ProviderPresets::PROVIDER_OUTLOOK => [
                'provider' => $provider,
                'title' => 'Outlook needs an app password, not your Microsoft password',
                'summary' => 'Microsoft blocks apps from signing in with your normal password. You need an app password instead. It takes about a minute to create, and you can revoke it any time without changing your Microsoft password.',
                'steps' => [
                    'Make sure two-step verification is turned on for your Microsoft account — app passwords require it.',
                    'Open the Microsoft app passwords page using the button below.',
                    'Select Create a new app password.',
                    'Copy the password Microsoft shows you and paste it here.',
                ],
                'link_url' => 'https://account.live.com/proofs/AppPassword',
                'link_label' => 'Create a Microsoft app password',
                'footnote' => 'On a work or school account, your IT administrator may need to enable SMTP AUTH before any password will work.',
            ],

            default => null,
        };
    }

    /**
     * A failure diagnosis carrying the app-password message.
     */
    public static function diagnosis(string $channel, string $provider, ?string $detail = null): ConnectionDiagnosis
    {
        $guidance = self::forProvider($provider);

        if ($guidance === null) {
            return ConnectionDiagnosis::failure(
                $channel,
                ConnectionDiagnosis::APP_PASSWORD_REQUIRED,
                'This provider requires an app-specific password rather than your normal account password.',
                $detail,
                'Look for "app passwords" in your mail provider\'s security settings.'
            );
        }

        return ConnectionDiagnosis::failure(
            $channel,
            ConnectionDiagnosis::APP_PASSWORD_REQUIRED,
            $guidance['title'],
            $detail,
            $guidance['summary']
        );
    }

    /**
     * Shown before the user has failed anything, when the preset already tells
     * us an app password will be required. Cheaper than letting them fail first.
     *
     * @return array{title:string, body:string, link_url:string, link_label:string}|null
     */
    public static function preflightNotice(string $provider): ?array
    {
        $guidance = self::forProvider($provider);

        if ($guidance === null) {
            return null;
        }

        return [
            'title' => str_contains($guidance['title'], 'Gmail')
                ? 'Gmail requires an app password'
                : 'Outlook requires an app password',
            'body' => 'Your normal account password will not work here. Create an app password first, then paste it into the password field.',
            'link_url' => $guidance['link_url'],
            'link_label' => $guidance['link_label'],
        ];
    }
}
