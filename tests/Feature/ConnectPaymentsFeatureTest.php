<?php

declare(strict_types=1);

namespace Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Keel\App\Models\Chase;
use Keel\App\Models\Client;
use Keel\App\Models\EmailAccount;
use Keel\App\Models\Invoice;
use Keel\App\Models\Sequence;
use Keel\App\Services\ChaseScheduler;
use Keel\App\Services\ConnectService;
use Keel\App\Services\PaymentLinkService;
use Keel\App\Services\TenantContext;
use Keel\Core\Database;
use Keel\Core\Session;
use Tests\TestCase;

/**
 * Collecting payment through a user's own Stripe account.
 *
 * The invariants worth breaking a build over: an unconnected workspace behaves
 * exactly as it did before this feature existed; a callback with the wrong
 * state links nothing; a replayed webhook applies once; a webhook for one
 * workspace can never touch another's invoice; a full payment stops the chase
 * and a partial one does not; and a link the user typed is never overwritten.
 */
class ConnectPaymentsFeatureTest extends TestCase
{
    private const CONNECT_SECRET = 'whsec_connect_feature_test';

    private int $tenantId;
    private int $userId;
    private int $accountId;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['STRIPE_CONNECT_WEBHOOK_SECRET'] = self::CONNECT_SECRET;
        $_SERVER['STRIPE_CONNECT_WEBHOOK_SECRET'] = self::CONNECT_SECRET;

        // Connect is configured on this install, so the settings page renders
        // the real states rather than "not available yet".
        $_ENV['STRIPE_CONNECT_CLIENT_ID'] = 'ca_feature_test';
        $_SERVER['STRIPE_CONNECT_CLIENT_ID'] = 'ca_feature_test';
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_feature';
        $_SERVER['STRIPE_SECRET_KEY'] = 'sk_test_feature';

        $user = $this->createUser(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $this->userId = (int) $user['id'];
        $this->tenantId = TenantContext::forUser($this->userId);

        $this->accountId = EmailAccount::create($this->tenantId, [
            'from_name' => 'Ada Lovelace',
            'from_email' => 'ada@studio.test',
            'smtp_host' => 'smtp.test',
            'smtp_port' => 587,
            'smtp_username' => 'ada@studio.test',
            'smtp_password' => 'app-password',
            'status' => EmailAccount::STATUS_ACTIVE,
            'is_default' => 1,
        ]);

        $this->now = new DateTimeImmutable('2026-08-19 11:00:00', new DateTimeZone('UTC'));
    }

    // ------------------------------- self-check 1: unconnected changes nothing

    public function testAnUnconnectedWorkspaceIsUntouchedByAnyOfIt(): void
    {
        $status = (new ConnectService())->status($this->tenantId);

        self::assertFalse($status['connected']);
        self::assertFalse($status['can_take_payments']);
        self::assertNull($status['account_id']);

        // An invoice with no link keeps no link. Nothing is generated, and
        // nothing reaches out to Stripe to try.
        $invoiceId = $this->invoice('INV-2001', 18, null);
        $result = (new PaymentLinkService())->generate($this->tenantId, Invoice::find($this->tenantId, $invoiceId));

        self::assertFalse($result['ok']);
        self::assertSame('not_connected', $result['reason']);
        self::assertNull(Invoice::find($this->tenantId, $invoiceId)['payment_url']);

        // And an invoice with the user's own link keeps exactly that.
        $manualId = $this->invoice('INV-2002', 18, 'https://pay.me/inv-2002');
        self::assertSame(
            'https://pay.me/inv-2002',
            (new PaymentLinkService())->linkFor($this->tenantId, Invoice::find($this->tenantId, $manualId))
        );
    }

    // --------------------------------- self-check 2: a mismatched state is dead

    public function testACallbackWithTheWrongStateLinksNothing(): void
    {
        Session::put('stripe_connect_state', 'the-real-nonce|' . $this->tenantId);

        $result = (new ConnectService())->completeConnection(
            $this->tenantId,
            'ac_a_real_looking_code',
            'a-nonce-the-attacker-chose'
        );

        self::assertFalse($result['ok']);
        self::assertNull($result['account_id']);
        self::assertFalse((new ConnectService())->status($this->tenantId)['connected']);
    }

    public function testACallbackCarryingAnotherWorkspacesStateLinksNothing(): void
    {
        // The nonce is right, but it was minted for a different workspace. A
        // nonce alone would let the connection land in the wrong place.
        $other = $this->createUser(['email' => 'other@studio.test']);
        $otherTenant = TenantContext::forUser((int) $other['id']);

        Session::put('stripe_connect_state', 'shared-nonce|' . $otherTenant);

        $result = (new ConnectService())->completeConnection($this->tenantId, 'ac_code', 'shared-nonce');

        self::assertFalse($result['ok']);
        self::assertFalse((new ConnectService())->status($this->tenantId)['connected']);
        self::assertFalse((new ConnectService())->status($otherTenant)['connected']);
    }

    public function testTheStateIsConsumedSoTheSameCallbackCannotBeReplayed(): void
    {
        Session::put('stripe_connect_state', 'one-shot|' . $this->tenantId);

        // The first attempt fails at the token exchange (no live Stripe here),
        // but it must still have burned the nonce on the way through.
        (new ConnectService())->completeConnection($this->tenantId, '', 'one-shot');

        $second = (new ConnectService())->completeConnection($this->tenantId, '', 'one-shot');

        self::assertFalse($second['ok']);
        self::assertStringContainsString('expired', (string) $second['error']);
    }

    // ------------------------------ self-check 3: a full payment settles it all

    public function testAFullPaymentMarksTheInvoicePaidAndStopsTheChase(): void
    {
        $this->connectStripe('acct_studio_ada');
        $invoiceId = $this->invoice('INV-2100', 18, null);
        $chaseId = $this->startChase($invoiceId);

        $response = $this->deliver($this->paymentEvent(
            'evt_full_payment',
            'acct_studio_ada',
            $invoiceId,
            $this->tenantId,
            320000
        ));

        self::assertSame(200, $response->status);
        self::assertTrue((bool) ($response->json()['handled'] ?? false));

        $invoice = Invoice::find($this->tenantId, $invoiceId);
        self::assertSame(Invoice::STATUS_PAID, $invoice['status']);
        self::assertNotEmpty($invoice['paid_at']);
        self::assertSame('stripe', $invoice['paid_source']);

        $chase = Chase::find($this->tenantId, $chaseId);
        self::assertSame(Chase::STATUS_PAUSED, $chase['status']);
        self::assertSame(Chase::PAUSE_INVOICE_PAID, $chase['paused_reason']);

        // And it shows up where the user looks for it.
        $payment = $this->paymentRow('evt_full_payment');
        self::assertSame('settled', $payment['outcome']);
        self::assertSame(320000, (int) $payment['amount_cents']);
    }

    // ------------------------- self-check 4: a partial payment settles nothing

    public function testAPartialPaymentNeitherMarksPaidNorStopsTheChase(): void
    {
        $this->connectStripe('acct_studio_ada');
        $invoiceId = $this->invoice('INV-2200', 18, null);
        $chaseId = $this->startChase($invoiceId);

        $response = $this->deliver($this->paymentEvent(
            'evt_part_payment',
            'acct_studio_ada',
            $invoiceId,
            $this->tenantId,
            160000
        ));

        self::assertSame(200, $response->status);

        $invoice = Invoice::find($this->tenantId, $invoiceId);
        self::assertSame(Invoice::STATUS_OPEN, $invoice['status'], 'A part payment must not mark an invoice paid.');
        self::assertEmpty($invoice['paid_at']);

        $chase = Chase::find($this->tenantId, $chaseId);
        self::assertNotSame(Chase::STATUS_PAUSED, $chase['status'], 'A part payment must not stop the chase.');

        // It is recorded, and it is recorded as partial rather than settled.
        $payment = $this->paymentRow('evt_part_payment');
        self::assertSame('partial', $payment['outcome']);
        self::assertSame(160000, (int) $payment['amount_cents']);

        // And the user is told, because only they can decide what it means.
        self::assertStringContainsString('INV-2200', $this->latestMailLog());
    }

    // -------------------------------- self-check 5: a replay applies only once

    public function testAReplayedWebhookAppliesExactlyOnce(): void
    {
        $this->connectStripe('acct_studio_ada');
        $invoiceId = $this->invoice('INV-2300', 18, null);
        $this->startChase($invoiceId);

        $event = $this->paymentEvent('evt_replayed', 'acct_studio_ada', $invoiceId, $this->tenantId, 320000);

        $first = $this->deliver($event);
        $second = $this->deliver($event);

        self::assertSame(200, $first->status);
        self::assertTrue((bool) ($first->json()['handled'] ?? false));

        // A duplicate is a success from Stripe's point of view: the work is
        // done, and a non-2xx would only make it retry forever.
        self::assertSame(200, $second->status);
        self::assertTrue((bool) ($second->json()['duplicate'] ?? false));
        self::assertFalse((bool) ($second->json()['handled'] ?? true));

        $count = Database::connection()->prepare(
            'SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?'
        );
        $count->execute([$invoiceId]);
        self::assertSame(1, (int) $count->fetchColumn());
    }

    // ------------------------ self-check 6: one workspace cannot reach another

    public function testAWebhookCannotMarkAnotherWorkspacesInvoicePaid(): void
    {
        $this->connectStripe('acct_studio_ada');

        $victim = $this->createUser(['email' => 'victim@studio.test']);
        $victimTenant = TenantContext::forUser((int) $victim['id']);
        $victimInvoice = $this->invoice('INV-VICTIM', 18, null, $victimTenant);

        // The event arrives on Ada's connected account but names the victim's
        // invoice and tenant in its metadata. The account is what decides.
        $response = $this->deliver($this->paymentEvent(
            'evt_cross_tenant',
            'acct_studio_ada',
            $victimInvoice,
            $victimTenant,
            320000
        ));

        self::assertSame(200, $response->status);
        self::assertFalse((bool) ($response->json()['handled'] ?? true));

        self::assertSame(
            Invoice::STATUS_OPEN,
            Invoice::find($victimTenant, $victimInvoice)['status'],
            'A payment on one workspace\'s account must never settle another workspace\'s invoice.'
        );
    }

    public function testAnInvoiceIdFromAnotherWorkspaceIsRejectedEvenWithMatchingTenantMetadata(): void
    {
        $this->connectStripe('acct_studio_ada');

        $victim = $this->createUser(['email' => 'victim2@studio.test']);
        $victimTenant = TenantContext::forUser((int) $victim['id']);
        $victimInvoice = $this->invoice('INV-VICTIM-2', 18, null, $victimTenant);

        // Metadata claims Ada's tenant — which matches the account — but points
        // at an invoice Ada does not own. The tenant-scoped lookup catches it.
        $response = $this->deliver($this->paymentEvent(
            'evt_foreign_invoice',
            'acct_studio_ada',
            $victimInvoice,
            $this->tenantId,
            320000
        ));

        self::assertSame(200, $response->status);
        self::assertFalse((bool) ($response->json()['handled'] ?? true));
        self::assertSame(Invoice::STATUS_OPEN, Invoice::find($victimTenant, $victimInvoice)['status']);
    }

    // ---------------------------------- self-check 7: a manual link is sacred

    public function testAManuallyPastedLinkIsNeverOverwritten(): void
    {
        $this->connectStripe('acct_studio_ada');
        $invoiceId = $this->invoice('INV-2400', 18, 'https://pay.me/my-own-link');

        $result = (new PaymentLinkService())->generate($this->tenantId, Invoice::find($this->tenantId, $invoiceId));

        self::assertFalse($result['ok']);
        self::assertSame('manual_link', $result['reason']);
        self::assertSame('https://pay.me/my-own-link', Invoice::find($this->tenantId, $invoiceId)['payment_url']);

        // And disconnecting clears only what Duely made, never the user's own.
        (new ConnectService())->clearConnection($this->tenantId);
        self::assertSame('https://pay.me/my-own-link', Invoice::find($this->tenantId, $invoiceId)['payment_url']);
    }

    public function testDisconnectingRemovesGeneratedLinksOnly(): void
    {
        $this->connectStripe('acct_studio_ada');

        $generated = $this->invoice('INV-2500', 18, 'https://buy.stripe.com/generated');
        Database::connection()
            ->prepare('UPDATE invoices SET payment_url_is_generated = 1 WHERE id = ?')
            ->execute([$generated]);

        $manual = $this->invoice('INV-2501', 18, 'https://pay.me/mine');

        (new ConnectService())->clearConnection($this->tenantId);

        self::assertNull(Invoice::find($this->tenantId, $generated)['payment_url']);
        self::assertSame('https://pay.me/mine', Invoice::find($this->tenantId, $manual)['payment_url']);
    }

    // ------------------------ self-check 8: charges disabled means no link at all

    public function testAnAccountThatCannotChargeProducesNoLink(): void
    {
        $this->connectStripe('acct_studio_ada', chargesEnabled: false);

        $invoiceId = $this->invoice('INV-2600', 18, null);
        $result = (new PaymentLinkService())->generate($this->tenantId, Invoice::find($this->tenantId, $invoiceId));

        self::assertFalse($result['ok']);
        self::assertSame('charges_disabled', $result['reason']);
        self::assertNull(Invoice::find($this->tenantId, $invoiceId)['payment_url']);
        self::assertFalse((new ConnectService())->status($this->tenantId)['can_take_payments']);
    }

    public function testAnAccountUpdatedWebhookChangesWhetherLinksCanBeMade(): void
    {
        $this->connectStripe('acct_studio_ada', chargesEnabled: false);

        $response = $this->deliver([
            'id' => 'evt_account_updated',
            'object' => 'event',
            'type' => 'account.updated',
            'account' => 'acct_studio_ada',
            'data' => [
                'object' => [
                    'id' => 'acct_studio_ada',
                    'object' => 'account',
                    'charges_enabled' => true,
                    'payouts_enabled' => true,
                ],
            ],
        ]);

        self::assertSame(200, $response->status);

        $status = (new ConnectService())->status($this->tenantId);
        self::assertTrue($status['charges_enabled']);
        self::assertTrue($status['can_take_payments']);
    }

    // ------------------------------- self-check 9: the endpoint is authenticated

    public function testAnUnsignedWebhookIsRejected(): void
    {
        $payload = (string) json_encode([
            'id' => 'evt_unsigned',
            'type' => 'payment_intent.succeeded',
            'account' => 'acct_studio_ada',
            'data' => ['object' => []],
        ]);

        $response = $this->postRawJson('/webhooks/stripe-connect', $payload, [
            'Stripe-Signature' => 't=' . time() . ',v1=not-a-real-signature',
        ]);

        self::assertSame(400, $response->status);
        self::assertSame('Webhook signature verification failed.', (string) ($response->json()['error'] ?? ''));
    }

    public function testTheSubscriptionSecretDoesNotSignConnectEvents(): void
    {
        // Separate endpoints, separate secrets. If this ever passes with the
        // subscription secret, an event captured on one endpoint can be
        // replayed against the other.
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_subscription_only';
        $_SERVER['STRIPE_WEBHOOK_SECRET'] = 'whsec_subscription_only';

        $payload = (string) json_encode([
            'id' => 'evt_wrong_secret',
            'type' => 'payment_intent.succeeded',
            'account' => 'acct_studio_ada',
            'data' => ['object' => []],
        ]);

        $timestamp = time();
        $hash = hash_hmac('sha256', $timestamp . '.' . $payload, 'whsec_subscription_only');

        $response = $this->postRawJson('/webhooks/stripe-connect', $payload, [
            'Stripe-Signature' => 't=' . $timestamp . ',v1=' . $hash,
        ]);

        self::assertSame(400, $response->status);
    }

    public function testAnEventForAnAccountNobodyIsConnectedToIsIgnoredNotFailed(): void
    {
        $response = $this->deliver($this->paymentEvent(
            'evt_orphan_account',
            'acct_nobody_here',
            1,
            $this->tenantId,
            320000
        ));

        // 200 so Stripe stops retrying. Nothing was applied, and nothing is wrong.
        self::assertSame(200, $response->status);
        self::assertFalse((bool) ($response->json()['handled'] ?? true));
    }

    // ------------------------------- the page itself renders in every state

    public function testTheUnconnectedPageOffersTheChoiceAndPromisesNothingFalse(): void
    {
        $this->signInAsAda();

        $response = $this->get('/settings/payments');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Connect your Stripe account', $response->body);
        // The claim has to be the true one: into their account, not "we never
        // see your money".
        self::assertStringContainsString('directly into your own Stripe', $response->body);
        self::assertStringContainsString('merchant of record', $response->body);
    }

    public function testAnAccountThatCannotChargeSaysSoAndOffersNoPayLink(): void
    {
        $this->signInAsAda();
        $this->connectStripe('acct_studio_ada', chargesEnabled: false);

        $response = $this->get('/settings/payments');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('not letting this account take payments yet', $response->body);
        self::assertStringNotContainsString('buy.stripe.com', $response->body);
    }

    // ================================================================
    // The pay-button choice: a workspace default and a per-invoice override.
    //
    // Every one of these asserts that a decision the user made is honoured. The
    // bug being prevented is a client receiving a pay button nobody chose.
    // ================================================================

    // ---------------------- self-check: unconnected is unchanged in all modes

    public function testAnUnconnectedWorkspaceBehavesTheSameInEveryMode(): void
    {
        foreach (PaymentLinkService::WORKSPACE_MODES as $mode) {
            $this->setWorkspaceMode($mode);

            $invoiceId = $this->invoice('INV-MODE-' . $mode, 18, null);
            $result = (new PaymentLinkService())->generate(
                $this->tenantId,
                Invoice::find($this->tenantId, $invoiceId)
            );

            self::assertFalse($result['ok'], $mode . ' generated a link with no Stripe account.');
            self::assertNull(Invoice::find($this->tenantId, $invoiceId)['payment_url']);
        }
    }

    // --------------------------- self-check: manual_only generates nothing

    public function testManualOnlyMakesNoLinkAndNoStripeCall(): void
    {
        $this->connectStripe('acct_studio_ada');
        $this->setWorkspaceMode(PaymentLinkService::WORKSPACE_MANUAL_ONLY);

        $invoiceId = $this->invoice('INV-MANUAL-ONLY', 18, null);
        $result = (new PaymentLinkService())->generate(
            $this->tenantId,
            Invoice::find($this->tenantId, $invoiceId)
        );

        self::assertFalse($result['ok']);
        // The reason proves it stopped at the local gate. A 'stripe_error' here
        // would mean it reached out with a fake key before deciding.
        self::assertSame('workspace_manual_only', $result['reason']);
        self::assertNull(Invoice::find($this->tenantId, $invoiceId)['payment_url']);
    }

    // ------------------------------- self-check: never spares a pasted link

    public function testNeverStillSendsAManuallyPastedLink(): void
    {
        $this->connectStripe('acct_studio_ada');
        $this->setWorkspaceMode(PaymentLinkService::WORKSPACE_NEVER);

        $invoiceId = $this->invoice('INV-NEVER-MANUAL', 18, 'https://pay.me/my-own-link');
        $invoice = Invoice::find($this->tenantId, $invoiceId);

        // `never` governs links Duely generates. The user's own URL is not
        // Duely's to suppress.
        self::assertSame(
            'https://pay.me/my-own-link',
            (new PaymentLinkService())->linkFor($this->tenantId, $invoice)
        );

        $plan = (new PaymentLinkService())->plan($this->tenantId, $invoice);
        self::assertTrue($plan['will_send']);
        self::assertSame('manual', $plan['kind']);
    }

    public function testNeverSuppressesALinkDuelyGeneratedEarlier(): void
    {
        $this->connectStripe('acct_studio_ada');

        $invoiceId = $this->invoice('INV-NEVER-GENERATED', 18, 'https://buy.stripe.com/made-earlier');
        Database::connection()
            ->prepare('UPDATE invoices SET payment_url_is_generated = 1 WHERE id = ?')
            ->execute([$invoiceId]);

        $this->setWorkspaceMode(PaymentLinkService::WORKSPACE_NEVER);

        // Otherwise turning the feature off would only affect invoices that had
        // not been chased yet, which is not what "off" means.
        self::assertNull(
            (new PaymentLinkService())->linkFor($this->tenantId, Invoice::find($this->tenantId, $invoiceId))
        );
    }

    // ---------------------------------- self-check: the per-invoice override

    public function testInvoiceNoneSuppressesTheButtonInAnAlwaysWorkspace(): void
    {
        $this->connectStripe('acct_studio_ada');
        $this->setWorkspaceMode(PaymentLinkService::WORKSPACE_ALWAYS);

        $invoiceId = $this->invoice('INV-OPTOUT', 18, null, null, PaymentLinkService::INVOICE_NONE);
        $result = (new PaymentLinkService())->generate(
            $this->tenantId,
            Invoice::find($this->tenantId, $invoiceId)
        );

        self::assertFalse($result['ok']);
        self::assertSame('invoice_none', $result['reason']);
    }

    public function testInvoiceGenerateWinsOverAManualOnlyWorkspace(): void
    {
        $this->connectStripe('acct_studio_ada');
        $this->setWorkspaceMode(PaymentLinkService::WORKSPACE_MANUAL_ONLY);

        // Decided locally: the Stripe key here is fake, so actually reaching the
        // API would fail. Getting past the gate is what is being asserted.
        $decision = PaymentLinkService::decide(
            PaymentLinkService::WORKSPACE_MANUAL_ONLY,
            PaymentLinkService::INVOICE_GENERATE
        );

        self::assertTrue($decision['generate']);
        self::assertSame('invoice_generate', $decision['reason']);

        $invoiceId = $this->invoice('INV-FORCED', 18, null, null, PaymentLinkService::INVOICE_GENERATE);
        $plan = (new PaymentLinkService())->plan($this->tenantId, Invoice::find($this->tenantId, $invoiceId));

        self::assertTrue($plan['will_send']);
        self::assertSame('pending', $plan['kind']);
    }

    public function testNeverBeatsAnInvoiceAskingForALink(): void
    {
        // `never` means no pay button on any reminder. An invoice-level
        // `generate` exists to escape `manual_only`, not to escape `never`.
        $decision = PaymentLinkService::decide(
            PaymentLinkService::WORKSPACE_NEVER,
            PaymentLinkService::INVOICE_GENERATE
        );

        self::assertFalse($decision['generate']);
        self::assertSame('workspace_never', $decision['reason']);
    }

    // ------------------------------ self-check: a pasted link beats them all

    public function testAPastedLinkBeatsEveryModeAndEveryOverride(): void
    {
        $this->connectStripe('acct_studio_ada');
        $service = new PaymentLinkService();
        $number = 0;

        $overrides = [
            null,
            PaymentLinkService::INVOICE_DEFAULT,
            PaymentLinkService::INVOICE_GENERATE,
            PaymentLinkService::INVOICE_NONE,
        ];

        foreach (PaymentLinkService::WORKSPACE_MODES as $workspaceMode) {
            $this->setWorkspaceMode($workspaceMode);

            foreach ($overrides as $invoiceMode) {
                $invoiceId = $this->invoice(
                    'INV-PASTED-' . (++$number),
                    18,
                    'https://pay.me/mine-' . $number,
                    null,
                    $invoiceMode
                );

                self::assertSame(
                    'https://pay.me/mine-' . $number,
                    $service->linkFor($this->tenantId, Invoice::find($this->tenantId, $invoiceId)),
                    'workspace=' . $workspaceMode . ' invoice=' . var_export($invoiceMode, true)
                );
            }
        }
    }

    // ------------------------- self-check: the two settings are independent

    public function testDisconnectingStripeLeavesTheModeAlone(): void
    {
        $this->connectStripe('acct_studio_ada');
        $this->setWorkspaceMode(PaymentLinkService::WORKSPACE_MANUAL_ONLY);

        (new ConnectService())->clearConnection($this->tenantId);

        // Reconnecting should find the setting the user chose, not a silent
        // reset to `always`.
        self::assertSame(
            PaymentLinkService::WORKSPACE_MANUAL_ONLY,
            (new PaymentLinkService())->workspaceMode($this->tenantId)
        );
    }

    public function testSettingModeToNeverLeavesStripeConnected(): void
    {
        $this->connectStripe('acct_studio_ada');

        self::assertTrue(
            (new ConnectService())->setPaymentMode($this->tenantId, PaymentLinkService::WORKSPACE_NEVER)
        );

        // Pausing the buttons and revoking the OAuth grant were the same action
        // before this column existed. They must not be.
        $status = (new ConnectService())->status($this->tenantId);
        self::assertTrue($status['connected']);
        self::assertSame('acct_studio_ada', $status['account_id']);
        self::assertSame(PaymentLinkService::WORKSPACE_NEVER, $status['payment_link_mode']);
    }

    public function testAnUnrecognisedModeIsRefusedRatherThanStored(): void
    {
        self::assertFalse((new ConnectService())->setPaymentMode($this->tenantId, 'sometimes'));
        self::assertSame(
            PaymentLinkService::WORKSPACE_ALWAYS,
            (new PaymentLinkService())->workspaceMode($this->tenantId)
        );
    }

    // ------------------- self-check: the SQL filters, not a loop afterwards

    /**
     * Asserted on the query, not on the outcome.
     *
     * Every row `ensurePaymentLinks()` selects costs a Stripe round trip, so an
     * invoice that was never going to get a link must not be selected at all.
     * Testing the outcome would pass just as happily with a filter after the
     * query, which is exactly the implementation being ruled out.
     */
    public function testEnsurePaymentLinksNeverSelectsAnInvoiceThatWillNotGetALink(): void
    {
        $this->connectStripe('acct_studio_ada');

        $followsDefault = $this->invoice('INV-Q-DEFAULT', 18, null);
        $optedOut = $this->invoice('INV-Q-NONE', 18, null, null, PaymentLinkService::INVOICE_NONE);
        $forced = $this->invoice('INV-Q-GENERATE', 18, null, null, PaymentLinkService::INVOICE_GENERATE);

        foreach ([$followsDefault, $optedOut, $forced] as $invoiceId) {
            $this->startChase($invoiceId);
        }

        // always: everything except the invoice that opted out.
        $this->setWorkspaceMode(PaymentLinkService::WORKSPACE_ALWAYS);
        $selected = $this->selectedForGeneration();
        self::assertContains($followsDefault, $selected);
        self::assertContains($forced, $selected);
        self::assertNotContains($optedOut, $selected, 'An invoice set to `none` was queued for a Stripe call.');

        // manual_only: only the invoice that explicitly asked.
        $this->setWorkspaceMode(PaymentLinkService::WORKSPACE_MANUAL_ONLY);
        $selected = $this->selectedForGeneration();
        self::assertSame([$forced], $selected);

        // never: nothing at all, including the invoice that asked.
        $this->setWorkspaceMode(PaymentLinkService::WORKSPACE_NEVER);
        self::assertSame([], $this->selectedForGeneration());
    }

    public function testTheQueryAndTheDecisionFunctionAgree(): void
    {
        // Two implementations of one rule -- the SQL and decide() -- drift the
        // moment somebody edits one. This walks every combination through both.
        $this->connectStripe('acct_studio_ada');

        $overrides = [null, PaymentLinkService::INVOICE_GENERATE, PaymentLinkService::INVOICE_NONE];
        $number = 0;
        $invoices = [];

        foreach ($overrides as $override) {
            $id = $this->invoice('INV-AGREE-' . (++$number), 18, null, null, $override);
            $this->startChase($id);
            $invoices[$id] = $override;
        }

        foreach (PaymentLinkService::WORKSPACE_MODES as $workspaceMode) {
            $this->setWorkspaceMode($workspaceMode);
            $selected = $this->selectedForGeneration();

            foreach ($invoices as $id => $override) {
                $decision = PaymentLinkService::decide($workspaceMode, $override);

                self::assertSame(
                    $decision['generate'],
                    in_array($id, $selected, true),
                    'SQL and decide() disagree for workspace=' . $workspaceMode
                        . ' invoice=' . var_export($override, true)
                );
            }
        }
    }

    /**
     * Run the private pre-pass query and report which invoice ids it picked.
     */
    private function selectedForGeneration(): array
    {
        // A stand-in for the links service that records what it was handed and
        // makes no Stripe call. What is under test is which rows the query
        // returned, so the generation step itself must not be involved.
        $spy = new class () extends PaymentLinkService {
            /** @var int[] */
            public array $seen = [];

            public function generate(int $tenantId, array $invoice): array
            {
                $this->seen[] = (int) $invoice['id'];

                return ['ok' => false, 'url' => null, 'error' => null, 'reason' => 'spy'];
            }
        };

        $sender = new \Keel\App\Services\ChaseSender(
            new \Tests\Support\RecordingTransport(),
            new \Keel\App\Services\TemplateRenderer(),
            new \Keel\App\Services\ChaseScheduler(),
            new \Keel\App\Services\SendRateLimiter(),
            $spy
        );

        $method = (new \ReflectionClass(\Keel\App\Services\ChaseSender::class))
            ->getMethod('ensurePaymentLinks');
        $method->setAccessible(true);
        $method->invoke($sender, $this->tenantId, $this->now);

        $seen = $spy->seen;
        sort($seen);

        return $seen;
    }

    // ------------------------------- self-check: the pages say what is true

    public function testTheSettingsPageOffersThreeRadiosNotACheckbox(): void
    {
        $this->signInAsAda();
        $this->connectStripe('acct_studio_ada');

        $body = $this->get('/settings/payments')->body;

        // Three states, and the middle one is the interesting one. A checkbox
        // cannot say "keep Stripe connected but decide per invoice".
        foreach (PaymentLinkService::WORKSPACE_MODES as $mode) {
            self::assertStringContainsString(
                'name="payment_link_mode" value="' . $mode . '"',
                $body,
                'No radio for ' . $mode . '.'
            );
        }

        self::assertStringNotContainsString('type="checkbox" name="payment_link_mode"', $body);
    }

    public function testAWorkspaceWithPayButtonsOffReadsAsASettingNotAFault(): void
    {
        $this->signInAsAda();
        $this->connectStripe('acct_studio_ada');
        $this->setWorkspaceMode(PaymentLinkService::WORKSPACE_NEVER);

        $body = $this->get('/settings/payments')->body;

        self::assertStringContainsString('because that is what you asked for', $body);
        self::assertStringContainsString('Pay buttons off', $body);
        // And it must not borrow the vocabulary of a broken connection.
        self::assertStringNotContainsString('not letting this account take payments yet', $body);
    }

    public function testManualOnlySaysSoRatherThanLookingLikeNothingIsHappening(): void
    {
        $this->signInAsAda();
        $this->connectStripe('acct_studio_ada');
        $this->setWorkspaceMode(PaymentLinkService::WORKSPACE_MANUAL_ONLY);

        $body = $this->get('/settings/payments')->body;

        self::assertStringContainsString('not generating links on its own', $body);
        self::assertStringContainsString('Your links only', $body);
    }

    public function testConnectingLandsOnTheChoiceScreenRatherThanSilentlySwitchingOn(): void
    {
        $this->signInAsAda();
        $this->connectStripe('acct_studio_ada');

        $body = $this->get('/settings/payments/choose')->body;

        self::assertStringContainsString('What happens next', $body);
        self::assertStringContainsString('a button your client can', $body);
        // The fee position, where somebody deciding will actually read it.
        self::assertStringContainsString('adds nothing on top of Stripe', $body);

        foreach (PaymentLinkService::WORKSPACE_MODES as $mode) {
            self::assertStringContainsString('value="' . $mode . '"', $body);
        }

        // And it does not block: the column already holds the default the page
        // describes, so leaving is a valid answer rather than an undefined state.
        self::assertStringContainsString('Leave it as it is', $body);
        self::assertSame(
            PaymentLinkService::WORKSPACE_ALWAYS,
            (new PaymentLinkService())->workspaceMode($this->tenantId)
        );
    }

    public function testTheInvoicePageStatesWhetherTheNextReminderCarriesAPayButton(): void
    {
        $this->signInAsAda();
        $this->connectStripe('acct_studio_ada');

        $withOwnLink = $this->invoice('INV-SHOW-MANUAL', 18, 'https://pay.me/mine');
        $this->startChase($withOwnLink);

        $body = $this->get('/invoices/' . $withOwnLink)->body;

        self::assertStringContainsString('Carries your own payment link', $body);
        self::assertStringContainsString('https://pay.me/mine', $body);

        $optedOut = $this->invoice('INV-SHOW-NONE', 18, null, null, PaymentLinkService::INVOICE_NONE);
        $this->startChase($optedOut);

        $body = $this->get('/invoices/' . $optedOut)->body;

        // Said in the user's terms, not as a reason code.
        self::assertStringContainsString('you turned it off for this invoice', $body);
    }

    // ----------------------------- self-check 10: Standard, and only Standard

    public function testNoCodePathCreatesAnExpressOrCustomAccountOrTakesAFee(): void
    {
        // A grep the build can fail on. Express and Custom would make the
        // platform liable for negative balances; application_fee_amount would
        // make Duely take a cut it has told users it does not take.
        $forbidden = ["'express'", "'custom'", 'application_fee_amount'];
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src')
        );

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            foreach ($forbidden as $needle) {
                // The comments explaining why these are absent say the words,
                // so only code lines count.
                foreach (explode("\n", $contents) as $number => $line) {
                    $trimmed = ltrim($line);

                    if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')) {
                        continue;
                    }

                    if (str_contains($line, $needle)) {
                        $offenders[] = $file->getFilename() . ':' . ($number + 1) . ' — ' . $needle;
                    }
                }
            }
        }

        self::assertSame([], $offenders, 'Connect Standard only: ' . implode(', ', $offenders));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Sign an event with the Connect secret and post it to the Connect endpoint.
     */
    private function deliver(array $event): \Tests\Support\TestResponse
    {
        $payload = (string) json_encode($event);
        $timestamp = time();
        $hash = hash_hmac('sha256', $timestamp . '.' . $payload, self::CONNECT_SECRET);

        return $this->postRawJson('/webhooks/stripe-connect', $payload, [
            'Stripe-Signature' => 't=' . $timestamp . ',v1=' . $hash,
        ]);
    }

    private function paymentEvent(
        string $eventId,
        string $account,
        int $invoiceId,
        int $tenantId,
        int $amountCents
    ): array {
        return [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'account' => $account,
            'data' => [
                'object' => [
                    'id' => 'pi_' . $eventId,
                    'object' => 'payment_intent',
                    'status' => 'succeeded',
                    'amount' => $amountCents,
                    'amount_received' => $amountCents,
                    'currency' => 'usd',
                    'metadata' => [
                        'duely_invoice_id' => (string) $invoiceId,
                        'duely_tenant_id' => (string) $tenantId,
                    ],
                ],
            ],
        ];
    }

    /**
     * Sign in as the workspace setUp already created, rather than making a
     * second user with the same address.
     */
    private function signInAsAda(): void
    {
        \Keel\Core\Session::put('user_id', $this->userId);
        \Keel\Core\Session::put('user_email', 'ada@studio.test');
        \Keel\Core\Session::put('organization_id', $this->tenantId);
        \Keel\Core\Auth::setUserId(null);
    }

    private function connectStripe(string $accountId, bool $chargesEnabled = true): void
    {
        Database::connection()->prepare(
            'UPDATE organizations
             SET stripe_account_id = ?, stripe_charges_enabled = ?, stripe_payouts_enabled = ?,
                 stripe_account_connected_at = ?
             WHERE id = ?'
        )->execute([
            $accountId,
            $chargesEnabled ? 1 : 0,
            $chargesEnabled ? 1 : 0,
            $this->now->format('Y-m-d H:i:s'),
            $this->tenantId,
        ]);
    }

    private function startChase(int $invoiceId): int
    {
        $sequenceId = (int) Sequence::defaultSequence($this->tenantId)['id'];

        $start = (new ChaseScheduler())->start(
            $this->tenantId,
            $invoiceId,
            $sequenceId,
            $this->accountId,
            $this->now
        );

        return (int) $start['chase_id'];
    }

    private function setWorkspaceMode(string $mode): void
    {
        Database::connection()
            ->prepare('UPDATE organizations SET payment_link_mode = ? WHERE id = ?')
            ->execute([$mode, $this->tenantId]);
    }

    private function invoice(
        string $number,
        int $daysOverdue,
        ?string $paymentUrl,
        ?int $tenantId = null,
        ?string $linkMode = null
    ): int {
        $tenantId ??= $this->tenantId;

        $clientId = Client::findOrCreate($tenantId, 'dana+' . $tenantId . '@client.test', [
            'name' => 'Dana Whitfield',
            'company' => 'Whitfield & Partners',
            'timezone' => 'UTC',
        ]);

        return Invoice::create($tenantId, [
            'client_id' => $clientId,
            'number' => $number,
            'amount_cents' => 320000,
            'currency' => 'USD',
            'due_date' => $this->now->modify('-' . $daysOverdue . ' days')->format('Y-m-d'),
            'payment_url' => $paymentUrl,
            'payment_link_mode' => $linkMode,
        ]);
    }

    private function paymentRow(string $eventId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM invoice_payments WHERE stripe_event_id = ? LIMIT 1'
        );
        $statement->execute([$eventId]);
        $row = $statement->fetch();

        self::assertIsArray($row, 'No payment was recorded for ' . $eventId);

        return $row;
    }
}
