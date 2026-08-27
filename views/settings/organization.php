<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="min-h-screen bg-surface text-text">
    <div class="mx-auto max-w-4xl px-4 py-10">
        <?php
        $pageEyebrow = 'Settings';
        $pageTitle = htmlspecialchars((string) ($organization['name'] ?? 'Organization'));
        require __DIR__ . '/../partials/app-nav.php';
        ?>

        <div class="card">
            <dl class="grid gap-6 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-text-muted">Organization name</dt>
                    <dd class="mt-1 text-base font-medium text-text-strong"><?= htmlspecialchars((string) ($organization['name'] ?? '')) ?></dd>
                </div>
                <div>
                    <dt class="text-sm text-text-muted">Slug</dt>
                    <dd class="mt-1 text-base font-medium text-text-strong"><?= htmlspecialchars((string) ($organization['slug'] ?? '')) ?></dd>
                </div>
                <div>
                    <dt class="text-sm text-text-muted">Your role</dt>
                    <dd class="mt-1 text-base font-medium text-text-strong"><?= htmlspecialchars((string) ($user['role'] ?? '')) ?></dd>
                </div>
                <div>
                    <dt class="text-sm text-text-muted">Members</dt>
                    <dd class="mt-1 text-base font-medium text-text-strong"><a href="/settings/members" class="text-text-strong underline">Manage members</a></dd>
                </div>
            </dl>
        </div>
    </div>
</body>
</html>