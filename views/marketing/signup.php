<?php
/**
 * Create an account.
 *
 * One field. No password, no organization name, no "confirm your email"
 * second box — every field on this form costs conversions, and none of those
 * three is needed to start. The workspace name is derived from the address and
 * renamed in onboarding, by which point the person has seen the product.
 *
 * It posts to `/auth/otp/request` and `/auth/otp/verify` — the same two
 * endpoints the sign-in form uses. There is no signup-specific authentication
 * anywhere in the application, which is deliberate: two OTP implementations
 * means two rate limiters and two expiry windows, and the one nobody is looking
 * at is the one that stays unpatched.
 *
 * Which also gives the disclosure property for free. A new address and one that
 * already has an account take exactly the same route and see exactly the same
 * words. Duely never says "that email is already registered", because that
 * answers the question "does this person use Duely?" for anybody who asks.
 */
$founding = $founding ?? null;
$prefilledEmail = $prefilledEmail ?? '';
$csrfToken = \Keel\Core\Csrf::token();
$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="min-h-screen bg-surface text-text">
    <div class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-4 py-12">
        <div class="mb-8 flex justify-center">
            <?php
            $variant = 'lockup';
            $size = 'sm';
            $link = true;
            require __DIR__ . '/../partials/logo.php';
            ?>
        </div>

        <section class="rounded-2xl border border-card-border bg-card p-8">
            <h1 class="text-3xl font-semibold tracking-tight text-text-strong">Start chasing</h1>

            <!--
                The same sentence the homepage and the pricing page use. Three
                pages describing one offer in three ways is how a promise turns
                into a dispute.
            -->
            <div class="mt-2">
                <?php $foundingTone = 'line'; require __DIR__ . '/partials/founding-note.php'; ?>
            </div>

            <!-- Step one: the address. -->
            <div id="signup-step-email" class="mt-7">
                <label for="signup-email" class="block text-sm font-medium text-text">Your email</label>
                <input type="email" id="signup-email" name="email" required autocomplete="email"
                       value="<?= $e($prefilledEmail) ?>"
                       placeholder="you@yourstudio.com" class="form-input mt-2 w-full">
                <p class="mt-2 text-xs text-text-muted">
                    No password. We send a six-digit code to confirm the address.
                </p>

                <button type="button" id="signup-send" class="btn btn-primary mt-4 w-full">
                    Send my code
                </button>
            </div>

            <!-- Step two: the code. -->
            <div id="signup-step-code" class="mt-7 hidden">
                <label for="signup-code" class="block text-sm font-medium text-text">Six-digit code</label>
                <input type="text" id="signup-code" name="code" inputmode="numeric" autocomplete="one-time-code"
                       maxlength="6" placeholder="000000"
                       class="form-input mt-2 w-full text-center font-mono text-2xl tracking-[0.4em]">
                <p class="mt-2 text-xs text-text-muted" id="signup-sent-to"></p>

                <button type="button" id="signup-verify" class="btn btn-primary mt-4 w-full">
                    Create my workspace
                </button>

                <button type="button" id="signup-back"
                        class="mt-3 w-full text-sm text-text-muted hover:text-text-strong">
                    Use a different address
                </button>
            </div>

            <p id="signup-error" role="alert" class="mt-4 hidden text-sm text-danger-text"></p>

            <p class="mt-7 border-t border-card-border pt-5 text-center text-sm text-text-muted">
                Already have an account?
                <a href="/login" class="text-brand underline underline-offset-4 hover:text-brand-hover">Sign in</a>
            </p>
        </section>

        <p class="mt-6 text-center text-xs text-text-muted">
            Reminders go out from your own inbox, not ours.
            <a href="/privacy" class="underline underline-offset-4 hover:text-text">How your data is handled</a>
        </p>
    </div>

    <script>
        (function () {
            const csrfToken = <?= json_encode($csrfToken) ?>;

            const emailStep = document.getElementById('signup-step-email');
            const codeStep = document.getElementById('signup-step-code');
            const errorBox = document.getElementById('signup-error');

            const fail = function (message) {
                errorBox.textContent = message;
                errorBox.classList.remove('hidden');
            };

            const clearError = function () {
                errorBox.textContent = '';
                errorBox.classList.add('hidden');
            };

            const post = function (url, body) {
                return fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify(body),
                }).then(function (response) { return response.json(); });
            };

            document.getElementById('signup-send').addEventListener('click', function (event) {
                const button = event.currentTarget;
                const email = document.getElementById('signup-email').value.trim();

                if (email === '') { fail('Enter your email address.'); return; }

                clearError();
                button.disabled = true;
                button.textContent = 'Sending…';

                post('/auth/otp/request', { email: email })
                    .then(function (data) {
                        button.disabled = false;
                        button.textContent = 'Send my code';

                        if (!data.success) {
                            fail(data.message || 'Something went wrong.');
                            return;
                        }

                        document.getElementById('signup-sent-to').textContent = 'Sent to ' + email + '.';
                        emailStep.classList.add('hidden');
                        codeStep.classList.remove('hidden');
                        document.getElementById('signup-code').focus();
                    })
                    .catch(function () {
                        button.disabled = false;
                        button.textContent = 'Send my code';
                        fail('Could not reach Duely. Try again in a moment.');
                    });
            });

            document.getElementById('signup-verify').addEventListener('click', function (event) {
                const button = event.currentTarget;
                const email = document.getElementById('signup-email').value.trim();
                const code = document.getElementById('signup-code').value.trim();

                if (code === '') { fail('Enter the code from your email.'); return; }

                clearError();
                button.disabled = true;
                button.textContent = 'Setting up…';

                post('/auth/otp/verify', { email: email, code: code })
                    .then(function (data) {
                        if (data.success && data.redirect) {
                            // Wherever the server says. A brand-new account goes
                            // to onboarding; an address that already had one goes
                            // to its dashboard, and this page never learns which.
                            window.location.href = data.redirect;
                            return;
                        }

                        button.disabled = false;
                        button.textContent = 'Create my workspace';
                        fail(data.message || 'That code did not work.');
                    })
                    .catch(function () {
                        button.disabled = false;
                        button.textContent = 'Create my workspace';
                        fail('Could not reach Duely. Try again in a moment.');
                    });
            });

            // Arrived from a marketing form with the address already typed:
            // put the cursor on the button rather than making them retype it.
            if (document.getElementById('signup-email').value.trim() !== '') {
                document.getElementById('signup-send').focus();
            }

            document.getElementById('signup-back').addEventListener('click', function () {
                clearError();
                codeStep.classList.add('hidden');
                emailStep.classList.remove('hidden');
                document.getElementById('signup-email').focus();
            });
        })();
    </script>
</body>
</html>
