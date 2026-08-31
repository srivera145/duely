<?php
/**
 * Tier three. Administering an account.
 *
 * Converted from Keel's inherited grey literals to theme tokens: the old page
 * rendered as a white card with near-black text in dark mode and looked like a
 * different product.
 *
 * Every destructive action here asks for the organization's name typed out. Not
 * a confirm dialog — the mistake worth preventing is not "meant to click
 * cancel", it is acting on the wrong tenant, and only re-reading the name
 * catches that.
 */
$organizations = $organizations ?? [];
$selected = $selectedOrganization ?? null;
$members = $selectedMembers ?? [];
$plans = $plans ?? ['free', 'solo', 'studio'];
$planStatus = $planStatus ?? null;
$notice = $_GET['notice'] ?? null;

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$csrf = \Keel\Core\Csrf::field();

$panelTitle = 'Accounts';
$panelSubtitle = count($organizations) . ' workspaces';
require __DIR__ . '/_layout.php';
?>

        <?php if ($notice !== null): ?>
        <div class="mb-4 rounded-lg border border-card-border bg-surface-muted px-4 py-3 text-sm text-text">
            <?= $e($notice) ?>
        </div>
        <?php endif; ?>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(360px,1.2fr)]">
            <section class="rounded-lg border border-card-border bg-card p-0">
                <div class="max-h-[70vh] overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-card">
                            <tr class="border-b border-card-border text-left text-xs uppercase tracking-wide text-text-muted">
                                <th class="p-3 font-medium">Workspace</th>
                                <th class="p-3 font-medium">Plan</th>
                                <th class="p-3 font-medium">Since</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($organizations as $organization): ?>
                            <?php $isSelected = $selected !== null && (int) $selected['id'] === (int) $organization['id']; ?>
                            <tr class="border-b border-card-border <?= $isSelected ? 'bg-surface-muted' : '' ?>">
                                <td class="p-3">
                                    <a href="/super-admin/organizations/<?= (int) $organization['id'] ?>"
                                       class="<?= $isSelected ? 'font-semibold text-brand' : 'text-text-strong hover:text-brand' ?>">
                                        <?= $e($organization['name']) ?>
                                    </a>
                                    <?php if (!empty($organization['disabled_at'])): ?>
                                    <span class="ml-2 rounded bg-danger-soft px-1.5 py-0.5 text-xs font-semibold text-danger-text">
                                        disabled
                                    </span>
                                    <?php endif; ?>
                                    <?php if (!empty($organization['is_founding'])): ?>
                                    <span class="ml-1 rounded bg-success-soft px-1.5 py-0.5 text-xs font-semibold text-success-text">
                                        founding
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3 text-text-muted"><?= $e($organization['plan'] ?? '') ?></td>
                                <td class="p-3 font-mono text-xs text-text-muted">
                                    <?= $e(\Keel\App\Services\Dates::short($organization['created_at'] ?? null)) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <?php if ($selected === null): ?>
            <section class="rounded-lg border border-card-border bg-card p-6">
                <p class="text-sm text-text-muted">Pick a workspace.</p>
            </section>
            <?php else: ?>
            <?php $tenantId = (int) $selected['id']; ?>
            <div class="space-y-4">
                <section class="rounded-lg border border-card-border bg-card p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-text-strong"><?= $e($selected['name']) ?></h2>
                            <p class="mt-1 font-mono text-xs text-text-muted">
                                #<?= $tenantId ?> &middot; <?= $e($selected['slug'] ?? '') ?>
                            </p>
                        </div>
                        <!--
                            Support access is a separate door with its own reason
                            prompt. Reaching a tenant's data from an admin screen
                            without stating why is exactly what the audit exists
                            to prevent.
                        -->
                        <a href="/super-admin/support" class="btn btn-sm border border-card-border">Support access</a>
                    </div>

                    <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                        <dt class="text-text-muted">Plan</dt>
                        <dd class="text-text-strong"><?= $e($selected['plan'] ?? '') ?></dd>
                        <dt class="text-text-muted">Trial ends</dt>
                        <dd class="text-text-strong"><?= $e(
                            \Keel\App\Services\Dates::medium($selected['trial_ends_at'] ?? null) ?: '—'
                        ) ?></dd>
                        <dt class="text-text-muted">Founding slot</dt>
                        <dd class="text-text-strong"><?= $e($selected['founding_slot'] ?? '—') ?></dd>
                        <dt class="text-text-muted">Stripe account</dt>
                        <dd class="font-mono text-xs text-text-strong"><?= $e($selected['stripe_account_id'] ?? '—') ?></dd>
                        <dt class="text-text-muted">Pay buttons</dt>
                        <dd class="text-text-strong"><?= $e($selected['payment_link_mode'] ?? 'always') ?></dd>
                        <dt class="text-text-muted">Disabled</dt>
                        <dd class="text-text-strong">
                            <?= empty($selected['disabled_at']) ? 'no' : $e($selected['disabled_at']) ?>
                        </dd>
                    </dl>
                </section>

                <section class="rounded-lg border border-card-border bg-card p-4">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-text-muted">Members</h3>
                    <table class="mt-3 w-full text-sm">
                        <tbody>
                            <?php foreach ($members as $member): ?>
                            <tr class="border-b border-card-border">
                                <td class="py-2 pr-2 text-text-strong"><?= $e($member['name'] ?? $member['email']) ?></td>
                                <td class="py-2 pr-2 font-mono text-xs text-text-muted"><?= $e($member['email']) ?></td>
                                <td class="py-2 pr-2 text-text-muted"><?= $e($member['role'] ?? '') ?></td>
                                <td class="py-2 text-right">
                                    <a href="/super-admin/impersonate/<?= (int) $member['id'] ?>"
                                       class="text-xs text-brand hover:underline">Sign in as</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>

                <section class="rounded-lg border border-card-border bg-card p-4">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-text-muted">Non-destructive</h3>

                    <form method="post" action="/super-admin/organizations/<?= $tenantId ?>/trial"
                          class="mt-3 flex flex-wrap items-end gap-2">
                        <?= $csrf ?>
                        <label class="text-sm text-text-muted">
                            Extend trial
                            <input type="number" name="days" value="14" min="1" max="365"
                                   class="form-input ml-2 w-20 py-1 text-sm">
                        </label>
                        <input type="text" name="reason" placeholder="Reason"
                               class="form-input w-48 py-1 text-sm">
                        <button type="submit" class="btn btn-sm border border-card-border">Extend</button>
                    </form>

                    <form method="post" action="/super-admin/organizations/<?= $tenantId ?>/plan"
                          class="mt-3 flex flex-wrap items-end gap-2">
                        <?= $csrf ?>
                        <label class="text-sm text-text-muted">
                            Set plan
                            <select name="plan" class="form-input ml-2 w-28 py-1 text-sm">
                                <?php foreach ($plans as $plan): ?>
                                <option value="<?= $e($plan) ?>" <?= ($selected['plan'] ?? '') === $plan ? 'selected' : '' ?>>
                                    <?= $e($plan) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <input type="text" name="reason" placeholder="Reason" class="form-input w-48 py-1 text-sm">
                        <button type="submit" class="btn btn-sm border border-card-border">Set</button>
                    </form>
                    <p class="mt-1 text-xs text-text-muted">Sets the column directly. Stripe is not touched.</p>

                    <form method="post" action="/super-admin/organizations/<?= $tenantId ?>/founding"
                          class="mt-3 flex flex-wrap items-end gap-2">
                        <?= $csrf ?>
                        <input type="hidden" name="grant" value="<?= empty($selected['is_founding']) ? 'yes' : 'no' ?>">
                        <input type="text" name="reason" placeholder="Reason" class="form-input w-48 py-1 text-sm">
                        <button type="submit" class="btn btn-sm border border-card-border">
                            <?= empty($selected['is_founding']) ? 'Grant founding slot' : 'Release founding slot' ?>
                        </button>
                    </form>
                    <p class="mt-1 text-xs text-text-muted">
                        Granting goes through the atomic claim, so it cannot produce slot 51.
                    </p>
                </section>

                <!--
                    Destructive. Each one wants the name typed, which is the only
                    check that catches acting on the wrong workspace.
                -->
                <section class="rounded-lg border border-danger-border bg-card p-4">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-danger-text">
                        Destructive &mdash; type
                        <span class="font-mono"><?= $e($selected['name']) ?></span> to confirm
                    </h3>

                    <?php foreach ([
                        ['pause-chases', 'Pause every chase', 'Stops all reminders for this workspace.'],
                        ['reset-sessions', 'Force sign-out', 'Ends every session for every member.'],
                        empty($selected['disabled_at'])
                            ? ['disable', 'Disable account', 'Blocks access and pauses all reminders.']
                            : ['enable', 'Re-enable account', 'Restores access. Reminders stay paused.'],
                    ] as [$path, $label, $hint]): ?>
                    <form method="post" action="/super-admin/organizations/<?= $tenantId ?>/<?= $e($path) ?>"
                          class="mt-3 border-t border-card-border pt-3">
                        <?= $csrf ?>
                        <p class="text-sm font-medium text-text-strong"><?= $e($label) ?></p>
                        <p class="mb-2 text-xs text-text-muted"><?= $e($hint) ?></p>
                        <div class="flex flex-wrap items-end gap-2">
                            <?php if ($path !== 'enable'): ?>
                            <input type="text" name="confirm_name" placeholder="Organization name"
                                   autocomplete="off" class="form-input w-52 py-1 text-sm">
                            <?php endif; ?>
                            <input type="text" name="reason" placeholder="Reason" class="form-input w-48 py-1 text-sm">
                            <button type="submit" class="btn btn-sm text-danger-text hover:underline">
                                <?= $e($label) ?>
                            </button>
                        </div>
                    </form>
                    <?php endforeach; ?>
                </section>
            </div>
            <?php endif; ?>
        </div>
<?php require __DIR__ . '/_layout-end.php'; ?>
