<?php

namespace Keel\Core;

/**
 * Reads Vite's manifest.json to output <script>/<link> tags for built assets.
 * Falls back to the dev server automatically when public_html/hot exists
 * (created by `npm run dev`, removed when the dev server stops).
 */
class Vite
{
    public static function assets(array $entries): string
    {
        $basePath = dirname(__DIR__, 2);
        $hotFile = $basePath . '/public_html/hot';

        if (file_exists($hotFile)) {
            $devServerUrl = rtrim(trim(file_get_contents($hotFile)), '/');
            $tags = '<script type="module" src="' . $devServerUrl . '/@vite/client"></script>' . "\n";
            foreach ($entries as $entry) {
                $tags .= '<script type="module" src="' . $devServerUrl . '/' . $entry . '"></script>' . "\n";
            }
            return $tags;
        }

        $manifestPath = $basePath . '/public_html/assets/.vite/manifest.json';

        if (!file_exists($manifestPath)) {
            return '<!-- Vite manifest not found. Run `npm run build`. -->';
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $tags = '';

        // Stylesheets and preloads are collected across the whole import graph,
        // not just the entry. The moment two entries share a module, Vite hoists
        // it into a common chunk and moves the CSS with it — at which point an
        // entry-only reading of the manifest emits no stylesheet at all and
        // every page renders unstyled.
        $styles = [];
        $preloads = [];

        foreach ($entries as $entry) {
            if (!isset($manifest[$entry])) {
                continue;
            }

            self::collect($manifest, $entry, $styles, $preloads, [], true);
        }

        // Before the scripts, so the browser has the stylesheet in flight early.
        foreach (array_keys($styles) as $cssFile) {
            $tags .= '<link rel="stylesheet" href="/assets/' . $cssFile . '">' . "\n";
        }

        foreach (array_keys($preloads) as $preload) {
            $tags .= '<link rel="modulepreload" href="/assets/' . $preload . '">' . "\n";
        }

        foreach ($entries as $entry) {
            if (!isset($manifest[$entry])) {
                continue;
            }

            $tags .= '<script type="module" src="/assets/' . $manifest[$entry]['file'] . '"></script>' . "\n";
        }

        return $tags;
    }

    /**
     * Walk one chunk and everything it imports, gathering CSS and the chunks
     * worth preloading.
     *
     * Keys are used as a set so a module imported by several entries is listed
     * once, and $seen makes a cyclic graph terminate rather than recurse
     * forever.
     *
     * @param array<string, mixed> $manifest
     * @param array<string, bool>  $styles
     * @param array<string, bool>  $preloads
     * @param array<string, bool>  $seen
     */
    private static function collect(
        array $manifest,
        string $key,
        array &$styles,
        array &$preloads,
        array $seen = [],
        bool $isEntry = false
    ): void {
        if (isset($seen[$key]) || !isset($manifest[$key])) {
            return;
        }

        $seen[$key] = true;
        $chunk = $manifest[$key];

        foreach ($chunk['css'] ?? [] as $cssFile) {
            $styles[$cssFile] = true;
        }

        // The entry itself is loaded by a <script> tag, so preloading it too
        // would only duplicate the request.
        if (!$isEntry && isset($chunk['file'])) {
            $preloads[$chunk['file']] = true;
        }

        foreach ($chunk['imports'] ?? [] as $import) {
            self::collect($manifest, $import, $styles, $preloads, $seen);
        }
    }
}
