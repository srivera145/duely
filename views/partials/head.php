<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php
$serverTheme = \Keel\Core\Theme::serverPreference();
$isAuthenticated = \Keel\Core\Theme::isAuthenticated();

$appUrl = rtrim((string) \Keel\Core\Env::get('APP_URL', ''), '/');
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$requestPath = (string) (parse_url($requestUri, PHP_URL_PATH) ?? '/');
$requestPath = $requestPath !== '' ? $requestPath : '/';
$canonicalUrl = $appUrl !== ''
	? $appUrl . ($requestPath === '/' ? '/' : $requestPath)
	: ($requestPath === '' ? '/' : $requestPath);

$defaultTitle = 'Duely - Get paid without writing the awkward follow-up';
$resolvedTitle = trim((string) ($title ?? $defaultTitle));
if ($resolvedTitle === '' || strtolower($resolvedTitle) === 'duely') {
	$resolvedTitle = $defaultTitle;
}

$defaultDescription = 'Duely chases your overdue invoices from your own inbox, and stops the moment a client replies or pays.';
$resolvedDescription = trim((string) ($metaDescription ?? $defaultDescription));
$siteName = 'Duely';
$socialImage = $appUrl !== '' ? $appUrl . '/images/brand/duely-og.png' : '/images/brand/duely-og.png';

// Pages that are reached from an email link, or that exist only as the result
// of an action, are not search results.
$isNoindex = (bool) ($noindex ?? false);

// The marketing pages load a much smaller bundle than the application: the
// landing page has one form on it, and shipping the whole app to read it is
// how a static page ends up failing Core Web Vitals.
$scriptEntries = (array) ($entrypoints ?? ['resources/js/app.js']);

// Structured data. Accepts one node or several; anything invalid is dropped
// rather than emitted, because a malformed graph is worse than none.
$structuredData = $jsonLd ?? null;
if ($structuredData !== null && array_is_list($structuredData) === false) {
	$structuredData = [$structuredData];
}
$structuredData = array_values(array_filter((array) $structuredData, 'is_array'));
?>
<script>
	(function () {
		var serverTheme = <?= json_encode($serverTheme, JSON_UNESCAPED_SLASHES) ?>;
		var isAuthenticated = <?= $isAuthenticated ? 'true' : 'false' ?>;
		var theme = serverTheme;

		if (!theme && !isAuthenticated) {
			try {
				var storedTheme = localStorage.getItem('keel-theme');
				if (storedTheme === 'light' || storedTheme === 'dark') {
					theme = storedTheme;
				}
			} catch (error) {
				// Ignore storage access errors and continue to system/default resolution.
			}
		}

		if (!theme && window.matchMedia) {
			try {
				theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
			} catch (error) {
				theme = null;
			}
		}

		if (!theme) {
			theme = 'dark';
		}

		document.documentElement.setAttribute('data-theme', theme);
	})();
</script>
<meta name="csrf-token" content="<?= htmlspecialchars(\Keel\Core\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
<meta name="keel-authenticated" content="<?= $isAuthenticated ? '1' : '0' ?>">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#0f172a">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars(\Keel\Core\Env::get('APP_NAME', 'Keel')) ?>">
<meta name="description" content="<?= htmlspecialchars($resolvedDescription, ENT_QUOTES, 'UTF-8') ?>">
<link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>">
<?php if ($isNoindex): ?>
<meta name="robots" content="noindex, follow">
<?php endif; ?>

<meta property="og:title" content="<?= htmlspecialchars($resolvedTitle, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:description" content="<?= htmlspecialchars($resolvedDescription, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:type" content="website">
<meta property="og:url" content="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:image" content="<?= htmlspecialchars($socialImage, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:site_name" content="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= htmlspecialchars($resolvedTitle, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($resolvedDescription, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($socialImage, ENT_QUOTES, 'UTF-8') ?>">
<!-- Favicons are generated from resources/images/brand/duely-mark.svg. The
     16px and 32px variants carry a simplified two-dot rail: at those sizes
     three dots and two connecting lines collapse into a smear. -->
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
<title><?= htmlspecialchars($resolvedTitle, ENT_QUOTES, 'UTF-8') ?></title>
<?php foreach ($structuredData as $node): ?>
<script type="application/ld+json"><?= json_encode($node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endforeach; ?>
<?= \Keel\Core\Vite::assets($scriptEntries) ?>
