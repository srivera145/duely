<?php

namespace Keel\Core;

class View
{
    private static string $viewsPath;

    /**
     * Rendered into every page during an impersonated session.
     *
     * Injected here rather than included by each view, because "every page"
     * has to mean every page. A banner each template opts into is a banner
     * that will be missing from the one screen where its absence matters, and
     * an operator who has forgotten which session they are in is precisely the
     * failure it exists to prevent.
     */
    private const IMPERSONATION_BANNER = 'partials/impersonation-banner';

    public static function setPath(string $path): void
    {
        self::$viewsPath = rtrim($path, '/');
    }

    public static function render(string $template, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        $file = self::$viewsPath . '/' . str_replace('.', '/', $template) . '.php';

        if (!file_exists($file)) {
            throw new \RuntimeException("View not found: {$template}");
        }

        $banner = self::impersonationBanner();

        if ($banner === '') {
            require $file;

            return;
        }

        // Buffered so the banner can be placed immediately after <body>, which
        // is the only position that makes it the first thing on the page in
        // both source order and reading order.
        ob_start();
        require $file;
        $html = (string) ob_get_clean();

        echo self::injectAfterBodyTag($html, $banner);
    }

    /**
     * The banner's markup, or an empty string when nothing is being
     * impersonated — which is every request except a support session.
     */
    private static function impersonationBanner(): string
    {
        $file = self::$viewsPath . '/' . self::IMPERSONATION_BANNER . '.php';

        if (!file_exists($file)) {
            return '';
        }

        ob_start();

        try {
            // The partial returns early when there is no live session, so the
            // cost on an ordinary request is one session read.
            require $file;
        } catch (\Throwable $exception) {
            // A banner that throws must not take the page with it. It failing
            // open — page renders, banner missing — is bad; the page not
            // rendering at all is worse, and both get logged.
            ob_end_clean();
            error_log('[Duely] Impersonation banner failed: ' . $exception->getMessage());

            return '';
        }

        return trim((string) ob_get_clean());
    }

    /**
     * Place the banner directly after the opening <body> tag.
     *
     * A fragment with no <body> — a partial rendered on its own, or a JSON-ish
     * view — gets it prepended instead. Never dropped: a support session with
     * no banner is the state this whole mechanism exists to make impossible.
     */
    private static function injectAfterBodyTag(string $html, string $banner): string
    {
        if (preg_match('/<body\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $banner . $html;
        }

        $insertAt = (int) $matches[0][1] + strlen((string) $matches[0][0]);

        return substr($html, 0, $insertAt) . "\n" . $banner . substr($html, $insertAt);
    }
}
