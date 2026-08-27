<?php
$tokens = $tokens ?? [];
$newToken = $newToken ?? null;
$error = $error ?? null;
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="min-h-screen bg-surface text-text">
    <div class="mx-auto max-w-5xl px-4 py-10">
        <?php
        $pageEyebrow = 'Developer access';
        $pageTitle = 'API tokens';
        require __DIR__ . '/../partials/app-nav.php';
        ?>

        <?php if (is_string($newToken) && $newToken !== ''): ?>
        <div class="alert mb-6 border-amber-500/30 bg-amber-500/10 p-4 text-amber-400">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-sm font-semibold">Copy this token now. You will not be able to see it again.</p>
                    <div id="new-token-value" class="mt-2 overflow-x-auto rounded border border-amber-500/30 bg-card px-3 py-2 font-mono text-sm"><?= htmlspecialchars($newToken) ?></div>
                </div>
                <button type="button" class="btn btn-sm border border-amber-500/30 bg-card text-amber-400 hover:bg-amber-500/100/20" data-copy-source="new-token-value" data-label-default="Copy token" data-label-success="Copied!" data-label-error="Copy failed">Copy token</button>
            </div>
        </div>
        <?php endif; ?>

        <?php if (is_string($error) && $error !== ''): ?>
        <div class="alert alert-error mb-6 px-4 py-3"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(320px,0.8fr)]">
            <div class="card">
                <h2 class="text-lg font-semibold text-text-strong">Issued tokens</h2>
                <div class="mt-4 overflow-x-auto rounded-xl border border-card-border">
                    <?php if ($tokens === []): ?>
                    <div class="p-6">
                        <div class="empty-state">
                            <span class="empty-state-icon" aria-hidden="true">k</span>
                            <p class="empty-state-title">No API tokens yet</p>
                            <p class="empty-state-text">Create a token to authenticate scripts or integrations.</p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($tokens !== []): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Abilities</th>
                                <th>Last used</th>
                                <th>Expires</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                    <?php foreach ($tokens as $token): ?>
                    <tr>
                        <td class="font-medium text-text-strong"><?= htmlspecialchars((string) $token['name']) ?></td>
                        <td class="text-xs"><?= htmlspecialchars((string) $token['abilities']) ?></td>
                        <td><?= htmlspecialchars((string) ($token['last_used_at'] ?? 'Never')) ?></td>
                        <td><?= htmlspecialchars((string) ($token['expires_at'] ?? 'Never')) ?></td>
                        <td class="text-right">
                            <button
                                type="button"
                                class="btn btn-danger btn-sm"
                                data-modal-open="revoke-token-modal-<?= (int) $token['id'] ?>"
                            >
                                Revoke
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>

                <?php foreach ($tokens as $token): ?>
                <div id="revoke-token-modal-<?= (int) $token['id'] ?>" class="modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="revoke-token-title-<?= (int) $token['id'] ?>">
                    <div class="modal-panel" data-modal-panel>
                        <h3 id="revoke-token-title-<?= (int) $token['id'] ?>" class="text-lg font-semibold text-text-strong">Revoke token?</h3>
                        <p class="mt-2 text-sm text-text-muted">This will immediately disable <strong><?= htmlspecialchars((string) $token['name']) ?></strong>. Existing clients using it will fail authentication.</p>
                        <div class="mt-5 flex justify-end gap-2">
                            <button type="button" class="btn btn-md border border-card-border bg-card text-text hover:bg-surface-muted" data-modal-close>Cancel</button>
                            <form method="POST" action="/settings/api-tokens/<?= (int) $token['id'] ?>/revoke">
                                <?= \Keel\Core\Csrf::field() ?>
                                <button type="submit" class="btn btn-danger btn-md">Revoke token</button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="card">
                <h2 class="text-lg font-semibold text-text-strong">Create token</h2>
                <form method="POST" action="/settings/api-tokens" class="mt-4 space-y-4">
                    <?= \Keel\Core\Csrf::field() ?>
                    <div>
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-input" placeholder="CI integration" required>
                    </div>
                    <div>
                        <label class="form-label">Abilities</label>
                        <input type="text" name="abilities" value="*" class="form-input" placeholder="read,write">
                    </div>
                    <div>
                        <label class="form-label">Expires at (optional)</label>
                        <input type="datetime-local" name="expires_at" class="form-input">
                    </div>
                    <button type="submit" class="btn btn-secondary btn-md w-full">Create token</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
