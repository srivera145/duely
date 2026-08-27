<?php
/**
 * Sign out.
 *
 * A form rather than a link, for two reasons. `/logout` sits inside the CSRF
 * group, so a bare `<a href="/logout">` would be rejected — and it should be:
 * sign-out is a state change, and a GET that ends a session can be triggered by
 * any image tag on any page the user happens to visit.
 *
 * It appears on every signed-in page. A session the user cannot end is not a
 * session they control, and the one place people look for it is the top right
 * of the header they are already reading.
 */
?>
<form method="post" action="/logout" class="contents">
    <?= \Keel\Core\Csrf::field() ?>
    <button type="submit" class="whitespace-nowrap text-sm text-text-muted transition hover:text-text-strong">
        Sign out
    </button>
</form>
