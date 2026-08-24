<?php
/**
 * The public site's head.
 *
 * Marketing pages load `marketing.js` rather than the application bundle: the
 * only interactive thing on them is one form, and shipping the whole app to
 * render a static page is how a landing page ends up failing Core Web Vitals.
 */
$entrypoints = ['resources/js/marketing.js'];

require __DIR__ . '/../../partials/head.php';
