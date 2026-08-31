<?php
/**
 * One account, opened with a stated reason.
 *
 * The mailbox block below is the sensitive one, and what it *omits* is the
 * feature: host, port, username, status, last error. No password field exists on
 * this page in any form, because the controller never selects the encrypted
 * columns. There is nothing here to mask, which is stronger than masking.
 */
$organization = $organization ?? [];
$reason = $reason ?? '';
$members = $members ?? [];
$mailboxes = $mailboxes ?? [];
$counts = $counts ?? [];
$access = $access ?? [];

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$panelTitle = (string) ($organization['name'] ?? 'Account');
$panelSubtitle = 'Opened for: ' . $reason;
require __DIR__ . '/_layout.php';
?>

        <div class="mb-4 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3">
            <p class="text-sm text-amber-400">
                This visit is recorded and visible to the account owner, with the reason above.
            </p>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-lg border border-card-border bg-card p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-text-muted">Counts</h2>
                <p class="mt-1 text-xs text-text-muted">How much of what. Never what it says.</p>
                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <?php foreach ([
                        'clients' => 'Clients',
                        'invoices_open' => 'Open invoices',
                        'invoices_paid' => 'Paid invoices',
                        'chases_live' => 'Live chases',
                        'chases_paused' => 'Paused chases',
                        'messages_sent' => 'Messages sent',
                        'messages_failed' => 'Messages failed',
                    ] as $key => $label): ?>
                    <dt class="text-text-muted"><?= $e($label) ?></dt>
                    <dd class="text-text-strong"><?= (int) ($counts[$key] ?? 0) ?></dd>
                    <?php endforeach; ?>
                </dl>
            </section>

            <section class="rounded-lg border border-card-border bg-card p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-text-muted">Mailboxes</h2>
                <!--
                    No credential appears here in any form. The controller's
                    column allowlist is what guarantees it: the encrypted columns
                    are never selected, so there is nothing on this page to leak,
                    mask or accidentally dump.
                -->
                <p class="mt-1 text-xs text-text-muted">
                    Configuration and status. Passwords are never read.
                </p>

                <?php foreach ($mailboxes as $mailbox): ?>
                <div class="mt-3 border-t border-card-border pt-3 text-sm">
                    <p class="font-medium text-text-strong"><?= $e($mailbox['from_email']) ?></p>
                    <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                        <dt class="text-text-muted">Status</dt>
                        <dd class="<?= $mailbox['status'] === 'active' ? 'text-success-text' : 'text-danger-text' ?>">
                            <?= $e($mailbox['status']) ?>
                        </dd>
                        <dt class="text-text-muted">SMTP</dt>
                        <dd class="font-mono text-text">
                            <?= $e($mailbox['smtp_host']) ?>:<?= (int) $mailbox['smtp_port'] ?>
                            (<?= $e($mailbox['smtp_encryption'] ?? '') ?>)
                        </dd>
                        <dt class="text-text-muted">SMTP user</dt>
                        <dd class="font-mono text-text"><?= $e($mailbox['smtp_username'] ?? '') ?></dd>
                        <dt class="text-text-muted">IMAP</dt>
                        <dd class="font-mono text-text">
                            <?= $e($mailbox['imap_host'] ?? '—') ?>:<?= (int) ($mailbox['imap_port'] ?? 0) ?>
                        </dd>
                        <dt class="text-text-muted">Last polled</dt>
                        <dd class="text-text"><?= $e($mailbox['imap_last_polled_at'] ?? 'never') ?></dd>
                        <dt class="text-text-muted">Last verified</dt>
                        <dd class="text-text"><?= $e($mailbox['last_verified_at'] ?? 'never') ?></dd>
                    </dl>
                    <?php if (!empty($mailbox['last_error'])): ?>
                    <p class="mt-2 rounded bg-danger-soft px-2 py-1 font-mono text-xs text-danger-text">
                        <?= $e($mailbox['last_error']) ?>
                    </p>
                    <?php endif; ?>
                    <?php if (!empty($mailbox['imap_last_error'])): ?>
                    <p class="mt-1 rounded bg-danger-soft px-2 py-1 font-mono text-xs text-danger-text">
                        IMAP: <?= $e($mailbox['imap_last_error']) ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <?php if ($mailboxes === []): ?>
                <p class="mt-3 text-sm text-text-muted">No mailbox connected.</p>
                <?php endif; ?>
            </section>
        </div>

        <section class="mt-4 rounded-lg border border-card-border bg-card p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-text-muted">Members</h2>
            <table class="mt-3 w-full text-sm">
                <tbody>
                    <?php foreach ($members as $member): ?>
                    <tr class="border-b border-card-border">
                        <td class="py-2 pr-2 text-text-strong"><?= $e($member['name'] ?? $member['email']) ?></td>
                        <td class="py-2 pr-2 font-mono text-xs text-text-muted"><?= $e($member['email']) ?></td>
                        <td class="py-2 pr-2 text-text-muted"><?= $e($member['role'] ?? '') ?></td>
                        <td class="py-2 text-right">
                            <?php if ((int) ($member['is_super_admin'] ?? 0) === 1): ?>
                            <span class="text-xs text-text-muted">operator</span>
                            <?php else: ?>
                            <a href="/super-admin/impersonate/<?= (int) $member['id'] ?>"
                               class="text-xs text-brand hover:underline">Sign in as</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="mt-4 rounded-lg border border-card-border bg-card p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-text-muted">
                Previous access to this account
            </h2>
            <p class="mt-1 text-xs text-text-muted">The same list the account owner can see.</p>
            <table class="mt-3 w-full text-sm">
                <tbody>
                    <?php foreach ($access as $entry): ?>
                    <tr class="border-b border-card-border">
                        <td class="py-2 pr-3 font-mono text-xs text-text-muted"><?= $e(
                            \Keel\App\Services\Dates::shortWithTime($entry['created_at'], $timezone ?? 'UTC')
                        ) ?></td>
                        <td class="py-2 pr-3 text-text-muted"><?= $e($entry['super_admin_email']) ?></td>
                        <td class="py-2 pr-3 font-mono text-xs text-text"><?= $e($entry['action']) ?></td>
                        <td class="py-2 text-text-muted"><?= $e($entry['reason'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
<?php require __DIR__ . '/_layout-end.php'; ?>
