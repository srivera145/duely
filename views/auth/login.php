<?php
/**
 * Sign in.
 *
 * Built entirely on the shared design tokens rather than a palette of its own.
 * The version this replaced carried thirty-odd hardcoded --keel-* colours, so
 * rebranding the app left this one screen behind — which is exactly the failure
 * a token system exists to prevent. Change the tokens, this changes with them.
 */
$authMethod = $authMethod ?? 'both';
$csrfToken = \Keel\Core\Csrf::token();
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
<style>
    /* The faint grid behind the card. Drawn from the border token so it
       lightens with the theme instead of staying a dark-mode artefact. */
    .auth-grid {
        background-image:
            linear-gradient(var(--color-card-border) 1px, transparent 1px),
            linear-gradient(90deg, var(--color-card-border) 1px, transparent 1px);
        background-size: 44px 44px;
        opacity: 0.35;
        mask-image: linear-gradient(180deg, rgba(0, 0, 0, 0.9), transparent 88%);
    }

    .auth-glow {
        background: radial-gradient(circle at 50% 0%, var(--color-success-soft), transparent 62%);
    }
</style>
</head>
<body class="min-h-screen bg-surface text-text antialiased">
    <div class="relative isolate min-h-screen overflow-hidden px-4 py-10 sm:px-6 lg:px-8">
        <div class="auth-grid absolute inset-0 -z-10"></div>
        <div class="auth-glow absolute inset-x-0 top-0 -z-10 h-96"></div>

        <div class="mx-auto flex min-h-screen w-full max-w-sm items-center justify-center py-6">
            <section class="w-full rounded-2xl border border-card-border bg-card p-7 shadow-2xl sm:p-8">

                <div class="relative mb-7">
                    <button type="button"
                            class="theme-toggle-button absolute right-0 top-0"
                            data-theme-toggle
                            aria-label="Switch theme">
                        <span data-theme-toggle-icon aria-hidden="true"></span>
                    </button>
                    <div class="flex justify-center">
                        <?php
                        $variant = 'lockup';
                        $size = 'lg';
                        $link = false;
                        require __DIR__ . '/../partials/logo.php';
                        ?>
                    </div>
                </div>

                <h1 class="text-3xl font-semibold tracking-tight text-text-strong">Sign in</h1>
                <p class="mt-1 text-sm text-text-muted">No password needed.</p>

                <?php if (!empty($_GET['notice']) && $_GET['notice'] === 'signed_out'): ?>
                <div class="mt-5 rounded-lg border border-success-border bg-success-soft px-3 py-2 text-sm text-success-text">
                    You are signed out.
                </div>
                <?php endif; ?>

                <?php if (!empty($_GET['error']) && $_GET['error'] === 'invalid_invite'): ?>
                <div class="mt-5 rounded-lg border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-text">
                    That invite link is invalid, expired, or already used.
                </div>
                <?php endif; ?>

                <?php if ($authMethod === 'both'): ?>
                <div class="mt-7 border-b border-card-border">
                    <div class="grid grid-cols-2 gap-4"
                         data-tabs
                         data-tabs-active-class="border-brand text-text-strong"
                         data-tabs-inactive-class="border-transparent text-text-muted">
                        <button type="button" id="tab-otp" data-tab-target="panel-otp" aria-selected="true"
                                class="border-b-2 border-brand px-1 pb-2 text-left text-sm font-semibold text-text-strong transition">
                            Code
                        </button>
                        <button type="button" id="tab-magic" data-tab-target="panel-magic" aria-selected="false"
                                class="border-b-2 border-transparent px-1 pb-2 text-left text-sm font-semibold text-text-muted transition">
                            Magic link
                        </button>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($authMethod === 'otp' || $authMethod === 'both'): ?>
                <div id="panel-otp" data-tab-panel="panel-otp" class="mt-6 space-y-4">
                    <div id="otp-step-email">
                        <label for="otp-email" class="block text-sm font-medium text-text-muted">Email</label>
                        <input type="email" id="otp-email" autocomplete="email" inputmode="email"
                               placeholder="you@example.com"
                               class="mt-2 w-full rounded-lg border border-input-border bg-surface-muted px-4 py-3 text-base text-text placeholder:text-text-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40">
                        <button type="button" id="otp-send"
                                class="mt-3 w-full rounded-lg bg-brand px-4 py-3 font-semibold text-brand-contrast transition hover:bg-brand-hover focus:outline-none focus:ring-2 focus:ring-brand/50">
                            Send code
                        </button>
                    </div>
                    <div id="otp-step-code" class="hidden">
                        <label for="otp-code" class="block text-sm font-medium text-text-muted">
                            Enter the 6-digit code
                        </label>
                        <input type="text" id="otp-code" maxlength="6" inputmode="numeric" autocomplete="one-time-code"
                               placeholder="000000"
                               class="mt-2 w-full rounded-lg border border-input-border bg-surface-muted px-4 py-3 text-center text-base tracking-[0.4em] text-text placeholder:text-text-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40">
                        <button type="button" id="otp-verify"
                                class="mt-3 w-full rounded-lg bg-brand px-4 py-3 font-semibold text-brand-contrast transition hover:bg-brand-hover focus:outline-none focus:ring-2 focus:ring-brand/50">
                            Verify
                        </button>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($authMethod === 'magic_link' || $authMethod === 'both'): ?>
                <div id="panel-magic" data-tab-panel="panel-magic"
                     class="mt-6 space-y-4 <?= $authMethod === 'both' ? 'hidden' : '' ?>">
                    <label for="magic-email" class="block text-sm font-medium text-text-muted">Email</label>
                    <input type="email" id="magic-email" autocomplete="email" inputmode="email"
                           placeholder="you@example.com"
                           class="mt-2 w-full rounded-lg border border-input-border bg-surface-muted px-4 py-3 text-base text-text placeholder:text-text-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40">
                    <button type="button" id="magic-send"
                            class="mt-3 w-full rounded-lg bg-brand px-4 py-3 font-semibold text-brand-contrast transition hover:bg-brand-hover focus:outline-none focus:ring-2 focus:ring-brand/50">
                        Send magic link
                    </button>
                    <p id="magic-sent" class="mt-3 hidden text-sm text-brand">
                        Check your email for the sign-in link.
                    </p>
                </div>
                <?php endif; ?>

                <p id="auth-error" role="alert" class="mt-4 hidden text-sm text-danger-text"></p>

                <p class="mt-7 border-t border-card-border pt-5 text-center text-sm text-text-muted">
                    Not signed up yet?
                    <a href="/signup" class="text-brand underline underline-offset-4 hover:text-brand-hover">
                        Create an account
                    </a>
                </p>
            </section>
        </div>
    </div>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>';

        const showError = (msg) => {
            const el = document.getElementById('auth-error');
            el.textContent = msg;
            el.classList.remove('hidden');
        };

        <?php if ($authMethod === 'otp' || $authMethod === 'both'): ?>
        document.getElementById('otp-send').addEventListener('click', async () => {
            const email = document.getElementById('otp-email').value;
            const res = await fetch('/auth/otp/request', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ email }),
            });
            const data = await res.json();
            if (data.success) {
                document.getElementById('otp-step-email').classList.add('hidden');
                document.getElementById('otp-step-code').classList.remove('hidden');
            } else {
                showError(data.message || 'Something went wrong.');
            }
        });

        document.getElementById('otp-verify').addEventListener('click', async () => {
            const email = document.getElementById('otp-email').value;
            const code = document.getElementById('otp-code').value;
            const res = await fetch('/auth/otp/verify', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ email, code }),
            });
            const data = await res.json();
            if (data.success) {
                window.location.href = data.redirect || '/dashboard';
            } else {
                showError(data.message || 'Invalid code.');
            }
        });
        <?php endif; ?>

        <?php if ($authMethod === 'magic_link' || $authMethod === 'both'): ?>
        document.getElementById('magic-send').addEventListener('click', async () => {
            const email = document.getElementById('magic-email').value;
            const res = await fetch('/auth/magic/request', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ email }),
            });
            const data = await res.json();
            if (data.success) {
                document.getElementById('magic-sent').classList.remove('hidden');
            } else {
                showError(data.message || 'Something went wrong.');
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
