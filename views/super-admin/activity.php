<?php
$logs = $logs ?? [];
$currentPage = $currentPage ?? 1;
$totalPages = $totalPages ?? 1;
?>
<?php
$panelTitle = 'Platform activity';
$superAdminNav = $superAdminNav ?? [];
require __DIR__ . '/_layout.php';
?>

        <div class="card overflow-x-auto p-0">
            <table class="table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Action</th>
                        <th>Org</th>
                        <th>Subject</th>
                        <th>Actor</th>
                        <th>Metadata</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($logs === []): ?>
                    <tr>
                        <td colspan="6" class="p-6">
                            <div class="empty-state">
                                <span class="empty-state-icon" aria-hidden="true">i</span>
                                <p class="empty-state-title">No activity yet</p>
                                <p class="empty-state-text">Platform-level events will appear here after activity is recorded.</p>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php foreach ($logs as $log): ?>
                    <?php $metadata = $log['metadata'] ? json_decode((string) $log['metadata'], true) : null; ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $log['created_at']) ?></td>
                        <td class="font-medium text-text-strong"><?= htmlspecialchars((string) $log['action']) ?></td>
                        <td><?= htmlspecialchars((string) ($log['organization_id'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string) ($log['subject_type'] ?? '-')) ?>#<?= htmlspecialchars((string) ($log['subject_id'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string) ($log['user_id'] ?? '-')) ?></td>
                        <td class="text-xs text-text-muted"><?= htmlspecialchars($metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="pagination">
            <span>Page <?= (int) $currentPage ?> of <?= (int) $totalPages ?></span>
            <div class="pagination-links">
                <?php if ($currentPage > 1): ?>
                <a href="/super-admin/activity?page=<?= (int) $currentPage - 1 ?>" class="pagination-link">Previous</a>
                <?php endif; ?>
                <?php if ($currentPage < $totalPages): ?>
                <a href="/super-admin/activity?page=<?= (int) $currentPage + 1 ?>" class="pagination-link">Next</a>
                <?php endif; ?>
            </div>
        </div>
<?php require __DIR__ . '/_layout-end.php'; ?>
