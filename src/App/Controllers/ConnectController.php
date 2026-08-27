<?php

namespace Keel\App\Controllers;

use Keel\App\Models\Invoice;
use Keel\App\Services\ConnectService;
use Keel\App\Services\PaymentLinkService;
use Keel\App\Services\TenantContext;
use Keel\Core\Activity;
use Keel\Core\Controller;
use Keel\Core\Env;
use Keel\Core\Request;

/**
 * Connecting, and disconnecting, a user's own Stripe account.
 *
 * Off by default. A workspace that never visits this page behaves exactly as it
 * did before payments existed — no links generated, no Stripe calls made, no
 * copy changed.
 */
class ConnectController extends Controller
{
    public function __construct(
        private readonly ConnectService $connect = new ConnectService(),
        private readonly PaymentLinkService $links = new PaymentLinkService(),
    ) {
    }

    /**
     * GET /settings/payments
     */
    public function show(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $status = $this->connect->status($tenantId);

        $this->view('settings.payments', [
            'title' => 'Payments - Duely',
            'pageTitle' => 'Payments',
            'pageSubtitle' => 'Let clients pay an invoice from the reminder itself.',
            'status' => $status,
            'configured' => ConnectService::isConfigured(),
            'notice' => $request->query['notice'] ?? null,
            'error' => $request->query['error'] ?? null,
        ]);
    }

    /**
     * POST /settings/payments/connect — start the OAuth handshake.
     */
    public function connect(Request $request): never
    {
        $tenantId = TenantContext::requireId();

        if (!ConnectService::isConfigured()) {
            $this->redirect('/settings/payments?error=not_configured');
        }

        Activity::log('connect.started', 'Organization', $tenantId);

        $this->redirect($this->connect->authorizeUrl($tenantId));
    }

    /**
     * GET /settings/payments/callback — where Stripe sends the user back.
     */
    public function callback(Request $request): never
    {
        $tenantId = TenantContext::requireId();

        // The user pressed cancel in Stripe. Not an error, and not worth
        // showing them one.
        if ((string) ($request->query['error'] ?? '') !== '') {
            $this->redirect('/settings/payments?notice=cancelled');
        }

        $result = $this->connect->completeConnection(
            $tenantId,
            (string) ($request->query['code'] ?? ''),
            (string) ($request->query['state'] ?? '')
        );

        if (!$result['ok']) {
            Activity::log('connect.failed', 'Organization', $tenantId, ['error' => $result['error']]);

            $this->redirect('/settings/payments?error=' . rawurlencode((string) $result['error']));
        }

        Activity::log('connect.completed', 'Organization', $tenantId, ['account_id' => $result['account_id']]);

        // Not straight back to the settings page. Connecting Stripe on Tuesday
        // should not mean a client gets a pay button on Wednesday that the user
        // never actually chose -- the default is right, but it should be picked
        // rather than discovered.
        $this->redirect('/settings/payments/choose');
    }

    /**
     * GET /settings/payments/choose — what happens next, and a chance to say.
     *
     * Shown once, immediately after connecting. It does not block: the column
     * already holds `always`, so a user who reads this and closes the tab is in
     * exactly the state the page describes, not an undefined one.
     */
    public function choose(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $status = $this->connect->status($tenantId);

        if (!$status['connected']) {
            $this->redirect('/settings/payments');
        }

        $this->view('settings.payments-choose', [
            'title' => 'How should Duely use Stripe? - Duely',
            'status' => $status,
            'openInvoices' => Invoice::countWithFilters($tenantId, ['status' => Invoice::STATUS_OPEN]),
        ]);
    }

    /**
     * POST /settings/payments/mode — set the workspace default.
     */
    public function setMode(Request $request): never
    {
        $tenantId = TenantContext::requireId();
        $mode = (string) $request->input('payment_link_mode', '');

        if (!$this->connect->setPaymentMode($tenantId, $mode)) {
            $this->redirect('/settings/payments?error=' . rawurlencode('That is not a setting Duely recognises.'));
        }

        Activity::log('connect.mode_set', 'Organization', $tenantId, ['mode' => $mode]);

        $this->redirect('/settings/payments?notice=mode_' . $mode);
    }

    /**
     * POST /settings/payments/refresh — re-read the account's standing.
     */
    public function refresh(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $this->connect->refreshAccount($tenantId);

        $this->json(['status' => $this->connect->status($tenantId)]);
    }

    /**
     * POST /settings/payments/disconnect
     */
    public function disconnect(Request $request): never
    {
        $tenantId = TenantContext::requireId();
        $result = $this->connect->disconnect($tenantId);

        Activity::log('connect.disconnected', 'Organization', $tenantId, ['revoked' => $result['revoked']]);

        $this->redirect('/settings/payments?notice=' . ($result['revoked'] ? 'disconnected' : 'disconnected_unconfirmed'));
    }

    /**
     * POST /api/invoices/{id}/payment-link — make one on request.
     *
     * The other way links get made is lazily, on the first reminder. This is
     * for the user who wants one now, before any reminder has gone out.
     */
    public function createLink(Request $request, string $id): void
    {
        $tenantId = TenantContext::requireId();
        $invoice = Invoice::find($tenantId, (int) $id);

        if ($invoice === null) {
            $this->json(['error' => 'No such invoice.'], 404);
        }

        $result = $this->links->generate($tenantId, $invoice);

        if (!$result['ok']) {
            $this->json(['error' => $result['error'], 'reason' => $result['reason']], 422);
        }

        Activity::log('invoice.payment_link_created', 'Invoice', (int) $invoice['id']);

        $this->json(['url' => $result['url'], 'reason' => $result['reason']]);
    }

    /**
     * The Connect webhook URL to paste into Stripe. Shown on the settings page
     * so the user does not have to guess it — and so a self-hoster gets the
     * right one for their own domain rather than Duely's.
     */
    public static function webhookUrl(): string
    {
        return rtrim((string) Env::get('APP_URL', ''), '/') . '/webhooks/stripe-connect';
    }
}
