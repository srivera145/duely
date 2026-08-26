<?php
/**
 * The Duely logo — the only place brand artwork is referenced.
 *
 * Nothing else in the application may hardcode an <img> to a file in
 * images/brand. When the artwork changes, it changes here.
 *
 * Parameters (all optional):
 *   $variant   'lockup' (default) — mark, wordmark and tagline stacked
 *              'mark'             — the three-dot rail alone, no type
 *   $size      'sm' | 'md' | 'lg'
 *   $link      bool, default true — wraps the logo in a link to /
 *   $class     extra classes on the wrapper
 *   $wordmark  bool, default false — only meaningful with 'mark'. Renders the
 *              word "Duely" beside the rail, for horizontal slots too short to
 *              carry the stacked lockup legibly.
 *
 * Sizes are per variant because the two shapes are not interchangeable. The
 * lockup is 420x257 and its tagline stops resolving below about 56px tall —
 * measured, not guessed — so `sm` for the lockup is that floor rather than a
 * nav-bar height. The mark is 118x40 and stays crisp down to 16px.
 *
 * Theme: the lockup ships as two files, so both are rendered and CSS picks one.
 * Swapping a single <img> src from script paints the wrong variant for a frame
 * first. The .logo-light-mode / .logo-dark-mode classes already exist in
 * app.css and key off [data-theme="light"], which the inline script in
 * partials/head.php sets before first paint.
 *
 * Every <img> carries explicit width and height so the header reserves its box
 * before the SVG arrives and nothing shifts.
 */

// These names are generic and the enclosing scope is shared, so each one is
// normalised rather than trusted. views/docs/_layout.php, for instance, also
// uses $link — as a foreach variable over nav items. It happens to render the
// logo before that loop today, and this file unsets everything on the way out,
// but neither fact should be load-bearing.
$variant = in_array($variant ?? null, ['lockup', 'mark'], true) ? $variant : 'lockup';
$size = in_array($size ?? null, ['sm', 'md', 'lg'], true) ? $size : 'md';
$link = (bool) ($link ?? true);
$class = is_string($class ?? null) ? $class : '';
$wordmark = (bool) ($wordmark ?? false);

// Real aspect ratios from the source files: 420x257 and 118x40.
$dimensions = [
    'lockup' => ['sm' => 56, 'md' => 72, 'lg' => 96],
    'mark' => ['sm' => 20, 'md' => 28, 'lg' => 40],
];

$height = $dimensions[$variant][$size] ?? $dimensions[$variant]['md'];
$ratio = $variant === 'lockup' ? 420 / 257 : 118 / 40;
$width = (int) round($height * $ratio);

$heightClass = [
    'lockup' => ['sm' => 'h-14', 'md' => 'h-[72px]', 'lg' => 'h-24'],
    'mark' => ['sm' => 'h-5', 'md' => 'h-7', 'lg' => 'h-10'],
][$variant][$size] ?? 'h-7';

// A link to home names itself. A logo sitting beside the word "Duely" is
// decoration, and announcing both makes a screen reader say it twice.
$decorative = $variant === 'mark' && $wordmark;
$imgAlt = $decorative ? '' : ($link ? 'Duely home' : 'Duely');

$imgAttrs = 'width="' . $width . '" height="' . $height . '"'
    . ' loading="eager" decoding="async"'
    . ' alt="' . htmlspecialchars($imgAlt, ENT_QUOTES, 'UTF-8') . '"'
    . ($decorative ? ' aria-hidden="true"' : '');

$imgClass = $heightClass . ' w-auto';

$wrapperClass = trim(
    ($decorative ? 'inline-flex items-center gap-2 ' : 'inline-flex ')
    . htmlspecialchars((string) $class, ENT_QUOTES, 'UTF-8')
);
?>
<?php if ($link): ?>
<a href="/" class="<?= $wrapperClass ?>"<?= $decorative ? ' aria-label="Duely home"' : '' ?>>
<?php else: ?>
<span class="<?= $wrapperClass ?>">
<?php endif; ?>
<?php if ($variant === 'mark'): ?>
    <img src="/images/brand/duely-mark.svg" class="<?= $imgClass ?>" <?= $imgAttrs ?>>
    <?php if ($wordmark): ?>
    <span class="text-lg font-semibold text-text-strong">Duely</span>
    <?php endif; ?>
<?php else: ?>
    <img src="/images/brand/duely-logo-dark.svg" class="logo-dark-mode <?= $imgClass ?>" <?= $imgAttrs ?>>
    <img src="/images/brand/duely-logo.svg" class="logo-light-mode <?= $imgClass ?>" <?= $imgAttrs ?>>
<?php endif; ?>
<?php if ($link): ?>
</a>
<?php else: ?>
</span>
<?php endif; ?>
<?php
// PHP includes share the enclosing scope, so a value left behind here would
// leak into the next call and silently resize a later logo.
unset($variant, $size, $link, $class, $wordmark, $dimensions, $height, $ratio,
    $width, $heightClass, $decorative, $imgAlt, $imgAttrs, $imgClass, $wrapperClass);
