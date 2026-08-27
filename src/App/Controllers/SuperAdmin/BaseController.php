<?php

namespace Keel\App\Controllers\SuperAdmin;

use Keel\App\Services\SupportAccessLog;
use Keel\App\Support\SuperAdminNav;
use Keel\Core\Controller;
use Keel\Core\Request;

/**
 * Shared behaviour for every panel controller.
 *
 * It exists mainly so `audit()` is one call rather than a thing each action
 * remembers. Read access is the access that matters in a support tool — nobody
 * worries about the operator editing an invoice number, they worry about the
 * operator reading it — so a page view that forgets to log is the failure mode
 * worth engineering against.
 */
abstract class BaseController extends Controller
{
    protected SupportAccessLog $audit;

    public function __construct(?SupportAccessLog $audit = null)
    {
        $this->audit = $audit ?? new SupportAccessLog();
    }

    /**
     * Render a panel page and record that it was opened.
     */
    protected function panel(string $template, string $action, array $data, ?int $tenantId = null): void
    {
        $this->audit->recordView($action, $tenantId);

        $this->view($template, array_merge([
            'title' => 'Operator — Duely',
            'superAdminNav' => $this->nav(),
        ], $data));
    }

    /**
     * The panel's own navigation. Deliberately not in `$navLinks`: this is not
     * a product surface and must never appear for an ordinary user.
     */
    protected function nav(): array
    {
        return (new SuperAdminNav())->links();
    }

    /**
     * A destructive action requires the organization's name typed out.
     *
     * Not a confirm dialog. A dialog is dismissed by muscle memory; typing the
     * name requires having read which account is about to be changed, which is
     * the mistake actually worth preventing — acting on the wrong tenant.
     */
    protected function confirmedByName(Request $request, array $organization): bool
    {
        $typed = trim((string) $request->input('confirm_name', ''));

        return $typed !== '' && $typed === trim((string) $organization['name']);
    }
}
