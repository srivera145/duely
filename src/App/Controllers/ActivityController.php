<?php

namespace Keel\App\Controllers;

use Keel\App\Models\User;
use Keel\Core\Auth;
use Keel\App\Services\SupportAccessLog;
use Keel\App\Services\TenantContext;
use Keel\App\Support\SuperAdminNav;
use Keel\Core\Controller;
use Keel\Core\Database;
use Keel\Core\Request;

class ActivityController extends Controller
{
    public function orgIndex(Request $request): void
    {
        // Through TenantContext rather than reading the column directly: a
        // single-tenant user gets their personal workspace provisioned on first
        // use, and reading organization_id raw would bounce them off their own
        // activity page before that had happened.
        $organizationId = TenantContext::requireId();

        [$logs, $currentPage, $totalPages] = $this->paginatedLogs(
            'SELECT * FROM activity_log WHERE organization_id = :organization_id ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset',
            'SELECT COUNT(*) FROM activity_log WHERE organization_id = :organization_id',
            ['organization_id' => $organizationId],
            (int) $request->input('page', 1)
        );

        $this->view('settings.activity', [
            'title' => 'Organization Activity',
            'logs' => $logs,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
        ]);
    }

    public function platformIndex(Request $request): void
    {
        [$logs, $currentPage, $totalPages] = $this->paginatedLogs(
            'SELECT * FROM activity_log ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset',
            'SELECT COUNT(*) FROM activity_log',
            [],
            (int) $request->input('page', 1)
        );

        // Logged like every other panel page: read access is the access that
        // matters in an operator tool.
        (new SupportAccessLog())->recordView('activity.platform');

        $this->view('super-admin.activity', [
            'title' => 'Platform Activity',
            'logs' => $logs,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'superAdminNav' => (new SuperAdminNav())->links(),
        ]);
    }

    private function currentUser(): array
    {
        $user = User::find((int) Auth::id());

        if (!$user) {
            throw new \RuntimeException('Authenticated user could not be loaded.');
        }

        return $user;
    }

    private function paginatedLogs(
        string $dataSql,
        string $countSql,
        array $params,
        int $requestedPage,
        int $perPage = 50
    ): array {
        $connection = Database::connection();
        $countStatement = $connection->prepare($countSql);

        foreach ($params as $name => $value) {
            $countStatement->bindValue(':' . $name, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }

        $countStatement->execute();
        $total = (int) $countStatement->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $currentPage = max(1, min($requestedPage, $totalPages));
        $offset = ($currentPage - 1) * $perPage;

        $dataStatement = $connection->prepare($dataSql);

        foreach ($params as $name => $value) {
            $dataStatement->bindValue(':' . $name, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }

        $dataStatement->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $dataStatement->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $dataStatement->execute();

        return [$dataStatement->fetchAll(), $currentPage, $totalPages];
    }
}
