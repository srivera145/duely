<?php
/**
 * Duely — email account settings.
 *
 * Nothing on this page renders a credential. Password inputs are seeded with a
 * mask when a value is stored, and the mask is what gets submitted back unless
 * the user types a replacement.
 */
$account = $account ?? [];
$providers = $providers ?? [];
$user = $user ?? ['name' => '', 'email' => ''];

$status = (string) ($account['status'] ?? 'unverified');
$exists = (bool) ($account['exists'] ?? false);
$notice = $account['app_password_notice'] ?? null;

$statusStyles = [
    'active' => ['label' => 'Connected', 'class' => 'border-success-border bg-success-soft text-success-text'],
    'needs_reauth' => ['label' => 'Needs attention', 'class' => 'border-amber-500/30 bg-amber-500/10 text-amber-400'],
    'disabled' => ['label' => 'Disabled', 'class' => 'border-gray-500/30 bg-gray-500/10 text-gray-400'],
    'unverified' => ['label' => 'Not connected', 'class' => 'border-gray-500/30 bg-gray-500/10 text-gray-400'],
];
$badge = $statusStyles[$status] ?? $statusStyles['unverified'];

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="min-h-screen bg-surface text-text">
    <div class="mx-auto max-w-4xl px-4 py-10">

        <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm text-text-muted">Settings</p>
                <h1 class="text-3xl font-bold text-text-strong">Email account</h1>
                <p class="mt-2 max-w-xl text-sm text-text-muted">
                    Reminders go out from your address, not ours, so replies land in your inbox and your client
                    sees a normal email from you.
                </p>
            </div>
            <a href="/dashboard" class="text-sm text-text-muted hover:text-text-strong">Back to dashboard</a>
        </div>

        <!-- Status banner: prominent when the connection is broken. -->
        <?php if ($status === 'needs_reauth'): ?>
        <div class="mb-6 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4">
            <div class="flex items-start gap-3">
                <span aria-hidden="true" class="mt-0.5 text-lg">!</span>
                <div>
                    <p class="font-semibold text-amber-400">Duely can no longer sign in to this mailbox</p>
                    <p class="mt-1 text-sm text-text-muted">
                        Reminders are paused until it is reconnected.
                        <?php if (!empty($account['last_error'])): ?>
                        The server said: <span class="text-text"><?= $e($account['last_error']) ?></span>
                        <?php endif; ?>
                    </p>
                    <p class="mt-2 text-sm text-text-muted">Enter a fresh password below and test the connection again.</p>
                </div>
            </div>
        </div>
        <?php elseif ($status === 'disabled'): ?>
        <div class="mb-6 rounded-xl border border-gray-500/30 bg-gray-500/10 p-4">
            <p class="font-semibold text-text-strong">This mailbox is disabled</p>
            <p class="mt-1 text-sm text-text-muted">No reminders will be sent until you reconnect it.</p>
        </div>
        <?php endif; ?>

        <form id="email-account-form" class="space-y-6" autocomplete="off" novalidate>
            <input type="hidden" name="account_id" value="<?= $e($account['id'] ?? '') ?>">
            <input type="hidden" name="provider" id="provider" value="<?= $e($account['provider'] ?? 'smtp') ?>">

            <!-- Identity -->
            <section class="rounded-xl border border-card-border bg-card p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold text-text-strong">How your reminders appear</h2>
                    <span class="rounded-full border px-3 py-1 text-xs font-semibold <?= $e($badge['class']) ?>" data-status-badge>
                        <?= $e($badge['label']) ?>
                    </span>
                </div>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="from_email" class="block text-sm font-medium text-text">Your email address</label>
                        <input type="email" id="from_email" name="from_email" required
                               value="<?= $e($account['from_email'] ?? '') ?>"
                               placeholder="you@yourstudio.com"
                               class="form-input mt-1 w-full">
                        <p class="mt-1 text-xs text-text-muted">We fill in the server settings from this.</p>
                    </div>
                    <div>
                        <label for="from_name" class="block text-sm font-medium text-text">Send as</label>
                        <input type="text" id="from_name" name="from_name" required
                               value="<?= $e($account['from_name'] ?? '') ?>"
                               placeholder="<?= $e($user['name'] ?: 'Your name') ?>"
                               class="form-input mt-1 w-full">
                        <p class="mt-1 text-xs text-text-muted">The name your client sees in their inbox.</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label for="reply_to" class="block text-sm font-medium text-text">Reply-to address</label>
                        <input type="email" id="reply_to" name="reply_to"
                               value="<?= $e($account['reply_to'] ?? '') ?>"
                               placeholder="<?= $e($user['email']) ?>"
                               class="form-input mt-1 w-full">
                        <p class="mt-1 text-xs text-text-muted">Where replies go. Defaults to your own address.</p>
                    </div>
                </div>
            </section>

            <!-- App password notice, shown before the user can fail. -->
            <div id="app-password-notice" class="<?= $notice ? '' : 'hidden' ?> rounded-xl border border-amber-500/30 bg-amber-500/10 p-5">
                <p class="font-semibold text-amber-400" data-notice-title><?= $e($notice['title'] ?? '') ?></p>
                <p class="mt-1 text-sm text-text-muted" data-notice-body><?= $e($notice['body'] ?? '') ?></p>
                <ol class="mt-3 hidden list-decimal space-y-1 pl-5 text-sm text-text-muted" data-notice-steps></ol>
                <a href="<?= $e($notice['link_url'] ?? '#') ?>" target="_blank" rel="noopener noreferrer"
                   class="btn btn-sm btn-primary mt-4" data-notice-link>
                    <?= $e($notice['link_label'] ?? 'Create an app password') ?>
                </a>
                <p class="mt-3 hidden text-xs text-text-muted" data-notice-footnote></p>
            </div>

            <!-- Sending -->
            <section class="rounded-xl border border-card-border bg-card p-6">
                <h2 class="text-lg font-semibold text-text-strong">Sending (SMTP)</h2>
                <p class="mt-1 text-sm text-text-muted">Used to deliver each reminder.</p>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="smtp_host" class="block text-sm font-medium text-text">Outgoing server</label>
                        <input type="text" id="smtp_host" name="smtp_host" required
                               value="<?= $e($account['smtp_host'] ?? '') ?>"
                               class="form-input mt-1 w-full font-mono text-sm">
                    </div>
                    <div>
                        <label for="smtp_port" class="block text-sm font-medium text-text">Port</label>
                        <input type="number" id="smtp_port" name="smtp_port" min="1" max="65535"
                               value="<?= $e($account['smtp_port'] ?? 587) ?>"
                               class="form-input mt-1 w-full font-mono text-sm">
                    </div>
                    <div>
                        <label for="smtp_encryption" class="block text-sm font-medium text-text">Encryption</label>
                        <select id="smtp_encryption" name="smtp_encryption" class="form-input mt-1 w-full">
                            <?php foreach (['tls' => 'STARTTLS (usually 587)', 'ssl' => 'SSL/TLS (usually 465)', 'none' => 'None'] as $value => $label): ?>
                            <option value="<?= $e($value) ?>" <?= ($account['smtp_encryption'] ?? 'tls') === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="smtp_username" class="block text-sm font-medium text-text">Username</label>
                        <input type="text" id="smtp_username" name="smtp_username" autocomplete="off"
                               value="<?= $e($account['smtp_username'] ?? '') ?>"
                               class="form-input mt-1 w-full font-mono text-sm">
                    </div>
                    <div>
                        <label for="smtp_password" class="block text-sm font-medium text-text">Password</label>
                        <input type="password" id="smtp_password" name="smtp_password" autocomplete="new-password"
                               value="<?= ($account['has_smtp_password'] ?? false) ? $e($account['masked_placeholder']) : '' ?>"
                               class="form-input mt-1 w-full font-mono text-sm">
                        <?php if ($account['has_smtp_password'] ?? false): ?>
                        <p class="mt-1 text-xs text-text-muted">A password is saved. Leave this as-is to keep it.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div data-result="smtp" class="mt-4 hidden rounded-lg border p-3 text-sm"></div>
            </section>

            <!-- Receiving -->
            <section class="rounded-xl border border-card-border bg-card p-6">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-lg font-semibold text-text-strong">Receiving (IMAP)</h2>
                    <label class="flex items-center gap-2 text-xs text-text-muted">
                        <input type="checkbox" id="same-credentials" checked class="rounded border-input-border">
                        Same sign-in as sending
                    </label>
                </div>
                <p class="mt-1 text-sm text-text-muted">
                    Used to spot replies, so a chase stops the moment your client answers. Required.
                </p>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="imap_host" class="block text-sm font-medium text-text">Incoming server</label>
                        <input type="text" id="imap_host" name="imap_host" required
                               value="<?= $e($account['imap_host'] ?? '') ?>"
                               class="form-input mt-1 w-full font-mono text-sm">
                    </div>
                    <div>
                        <label for="imap_port" class="block text-sm font-medium text-text">Port</label>
                        <input type="number" id="imap_port" name="imap_port" min="1" max="65535"
                               value="<?= $e($account['imap_port'] ?? 993) ?>"
                               class="form-input mt-1 w-full font-mono text-sm">
                    </div>
                    <div>
                        <label for="imap_encryption" class="block text-sm font-medium text-text">Encryption</label>
                        <select id="imap_encryption" name="imap_encryption" class="form-input mt-1 w-full">
                            <?php foreach (['ssl' => 'SSL/TLS (usually 993)', 'tls' => 'STARTTLS (usually 143)', 'none' => 'None'] as $value => $label): ?>
                            <option value="<?= $e($value) ?>" <?= ($account['imap_encryption'] ?? 'ssl') === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="imap_username" class="block text-sm font-medium text-text">Username</label>
                        <input type="text" id="imap_username" name="imap_username" autocomplete="off"
                               value="<?= $e($account['imap_username'] ?? '') ?>"
                               class="form-input mt-1 w-full font-mono text-sm">
                    </div>
                    <div>
                        <label for="imap_password" class="block text-sm font-medium text-text">Password</label>
                        <input type="password" id="imap_password" name="imap_password" autocomplete="new-password"
                               value="<?= ($account['has_imap_password'] ?? false) ? $e($account['masked_placeholder']) : '' ?>"
                               class="form-input mt-1 w-full font-mono text-sm">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="imap_folder" class="block text-sm font-medium text-text">Folder to watch</label>
                        <input type="text" id="imap_folder" name="imap_folder"
                               value="<?= $e($account['imap_folder'] ?? 'INBOX') ?>"
                               class="form-input mt-1 w-full font-mono text-sm">
                    </div>
                </div>

                <div data-result="imap" class="mt-4 hidden rounded-lg border p-3 text-sm"></div>
            </section>

            <!-- Failure guidance rendered from a live test. -->
            <div id="test-guidance" class="hidden rounded-xl border border-amber-500/30 bg-amber-500/10 p-5">
                <p class="font-semibold text-amber-400" data-guidance-title></p>
                <p class="mt-1 text-sm text-text-muted" data-guidance-summary></p>
                <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-text-muted" data-guidance-steps></ol>
                <a href="#" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-primary mt-4" data-guidance-link></a>
                <p class="mt-3 text-xs text-text-muted" data-guidance-footnote></p>
            </div>

            <div id="form-error" class="hidden rounded-lg border border-danger-border bg-danger-soft p-3 text-sm text-danger-text"></div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="button" id="test-button" class="btn btn-secondary border border-card-border">
                    Test connection
                </button>
                <button type="submit" id="save-button" class="btn btn-primary">
                    <?= $exists ? 'Test and save changes' : 'Test and connect' ?>
                </button>

                <?php if ($exists && $status === 'active'): ?>
                <button type="button" id="send-test-button" class="btn btn-secondary border border-card-border">
                    Send myself a test email
                </button>
                <?php endif; ?>

                <?php if ($exists): ?>
                <button type="button" id="delete-button" class="btn ml-auto text-sm text-danger-text hover:underline">
                    Disconnect
                </button>
                <?php endif; ?>
            </div>

            <?php if (!empty($account['last_verified_at'])): ?>
            <p class="text-xs text-text-muted">Last verified <?= $e($account['last_verified_at']) ?>.</p>
            <?php endif; ?>
        </form>
    </div>

    <script>
        window.duelyEmailAccount = {
            masked: <?= json_encode($account['masked_placeholder'] ?? '', JSON_UNESCAPED_SLASHES) ?>,
            exists: <?= $exists ? 'true' : 'false' ?>
        };
    </script>
</body>
</html>
