<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use Keel\Core\Database;
use Keel\Core\Env;
use Keel\Core\Session;
use Stripe\OAuth;
use Stripe\Stripe;
use Stripe\StripeClient;
use Throwable;

/**
 * Linking a user's own Stripe account.
 *
 * Connect **Standard**, and only Standard. The user authorises their existing
 * Stripe account by OAuth; money settles directly into it; they are the
 * merchant of record and own their KYC, disputes, refunds and payouts.
 *
 * Express and Custom are deliberately not built. Under either, the platform
 * carries the negative balance — a chargeback on a $3,200 invoice would be
 * clawed back from EchoDial LLC. For a solo operation that is not an acceptable
 * exposure, and it is not a decision to make accidentally by copying an example
 * from the docs. There is no code path here that creates an account of any type.
 */
class ConnectService
{
    /** Where the OAuth nonce lives between redirect and callback. */
    private const STATE_KEY = 'stripe_connect_state';

    /**
     * Is Connect configured on this install at all?
     */
    public static function isConfigured(): bool
    {
        return trim((string) Env::get('STRIPE_CONNECT_CLIENT_ID', '')) !== ''
            && trim((string) Env::get('STRIPE_SECRET_KEY', '')) !== '';
    }

    /**
     * What this workspace's connection looks like.
     *
     * @return array{connected:bool, account_id:?string, charges_enabled:bool, payouts_enabled:bool, connected_at:?string, can_take_payments:bool}
     */
    public function status(int $tenantId): array
    {
        $row = $this->organization($tenantId);

        $accountId = trim((string) ($row['stripe_account_id'] ?? ''));
        $connected = $accountId !== '';
        $charges = (bool) ($row['stripe_charges_enabled'] ?? false);

        return [
            'connected' => $connected,
            'account_id' => $connected ? $accountId : null,
            'charges_enabled' => $charges,
            'payouts_enabled' => (bool) ($row['stripe_payouts_enabled'] ?? false),
            'connected_at' => $row['stripe_account_connected_at'] ?? null,
            // The only question the rest of the app should ask.
            'can_take_payments' => $connected && $charges,
        ];
    }

    /**
     * Begin the OAuth flow.
     *
     * The `state` nonce is stored in the session and checked on the way back.
     * Without it, anyone who can make the user's browser hit the callback with
     * their own code can attach *their* Stripe account to the user's workspace,
     * and every payment link after that pays a stranger.
     */
    public function authorizeUrl(int $tenantId): string
    {
        $nonce = bin2hex(random_bytes(32));
        Session::put(self::STATE_KEY, $nonce . '|' . $tenantId);

        Stripe::setApiKey((string) Env::get('STRIPE_SECRET_KEY', ''));

        return OAuth::authorizeUrl([
            'client_id' => (string) Env::get('STRIPE_CONNECT_CLIENT_ID', ''),
            'response_type' => 'code',
            // Standard only. No account type is requested here, and none is
            // ever created: the user authorises an account they already own.
            'stripe_user' => ['email' => null],
            'scope' => 'read_write',
            'state' => $nonce,
            'redirect_uri' => $this->redirectUri(),
        ]);
    }

    /**
     * Finish the OAuth flow.
     *
     * @return array{ok:bool, error:?string, account_id:?string}
     */
    public function completeConnection(
        int $tenantId,
        string $code,
        string $state,
        ?DateTimeImmutable $now = null
    ): array {
        $now ??= Clock::now();

        $expected = (string) Session::get(self::STATE_KEY, '');
        Session::forget(self::STATE_KEY);

        if ($expected === '' || !str_contains($expected, '|')) {
            return $this->failure('That connection attempt has expired. Start again.');
        }

        [$nonce, $stateTenant] = explode('|', $expected, 2);

        // Constant-time, and the workspace must match the one that started it:
        // a nonce alone would let a callback land in the wrong workspace.
        if (!hash_equals($nonce, $state) || (int) $stateTenant !== $tenantId) {
            return $this->failure('That connection could not be verified. Nothing has been linked.');
        }

        if (trim($code) === '') {
            return $this->failure('Stripe did not return an authorisation code.');
        }

        try {
            Stripe::setApiKey((string) Env::get('STRIPE_SECRET_KEY', ''));

            $token = OAuth::token([
                'grant_type' => 'authorization_code',
                'code' => $code,
            ]);

            $accountId = (string) ($token->stripe_user_id ?? '');
        } catch (Throwable $exception) {
            error_log('[Duely] Stripe Connect token exchange failed: ' . $exception->getMessage());

            return $this->failure('Stripe refused that connection. Nothing has been linked.');
        }

        if ($accountId === '') {
            return $this->failure('Stripe did not return an account. Nothing has been linked.');
        }

        $statement = Database::connection()->prepare(
            'UPDATE organizations
             SET stripe_account_id = ?, stripe_account_connected_at = ?
             WHERE id = ?'
        );
        $statement->execute([$accountId, Clock::toDatabase($now), $tenantId]);

        // Charges may not be live yet if the account is mid-verification, so
        // the flags come from Stripe rather than being assumed.
        $this->refreshAccount($tenantId, $now);

        return ['ok' => true, 'error' => null, 'account_id' => $accountId];
    }

    /**
     * Re-read whether the account can actually take money.
     *
     * An account that falls out of good standing must stop producing links: a
     * link that fails at checkout is worse than no link, because the client has
     * already tried to pay.
     */
    public function refreshAccount(int $tenantId, ?DateTimeImmutable $now = null): bool
    {
        $now ??= Clock::now();
        $accountId = (string) ($this->organization($tenantId)['stripe_account_id'] ?? '');

        if ($accountId === '') {
            return false;
        }

        try {
            $client = new StripeClient((string) Env::get('STRIPE_SECRET_KEY', ''));
            $account = $client->accounts->retrieve($accountId, []);
        } catch (Throwable $exception) {
            error_log('[Duely] Could not read connected account: ' . $exception->getMessage());

            return false;
        }

        $this->storeCapabilities(
            $tenantId,
            (bool) ($account->charges_enabled ?? false),
            (bool) ($account->payouts_enabled ?? false),
            $now
        );

        return true;
    }

    /**
     * Record what an account.updated webhook told us, without a round trip.
     */
    public function storeCapabilities(
        int $tenantId,
        bool $chargesEnabled,
        bool $payoutsEnabled,
        ?DateTimeImmutable $now = null
    ): void {
        $statement = Database::connection()->prepare(
            'UPDATE organizations
             SET stripe_charges_enabled = ?, stripe_payouts_enabled = ?, stripe_account_last_checked_at = ?
             WHERE id = ?'
        );
        $statement->execute([
            $chargesEnabled ? 1 : 0,
            $payoutsEnabled ? 1 : 0,
            Clock::toDatabase($now ?? Clock::now()),
            $tenantId,
        ]);
    }

    /**
     * Unlink the account.
     *
     * The authorisation is revoked at Stripe first. Clearing the row alone
     * would leave a live grant the user cannot see from either side — Duely
     * would show "not connected" while still holding access.
     *
     * @return array{ok:bool, revoked:bool, error:?string}
     */
    public function disconnect(int $tenantId): array
    {
        $accountId = (string) ($this->organization($tenantId)['stripe_account_id'] ?? '');

        if ($accountId === '') {
            return ['ok' => true, 'revoked' => false, 'error' => null];
        }

        $revoked = false;

        try {
            Stripe::setApiKey((string) Env::get('STRIPE_SECRET_KEY', ''));
            OAuth::deauthorize([
                'client_id' => (string) Env::get('STRIPE_CONNECT_CLIENT_ID', ''),
                'stripe_user_id' => $accountId,
            ]);
            $revoked = true;
        } catch (Throwable $exception) {
            // Stripe may report it already revoked, which is the state we want
            // anyway. Anything else is worth knowing about but must not strand
            // the user in a workspace they cannot disconnect.
            error_log('[Duely] Stripe deauthorize failed: ' . $exception->getMessage());
        }

        $this->clearConnection($tenantId);

        return [
            'ok' => true,
            'revoked' => $revoked,
            'error' => $revoked ? null : 'Stripe did not confirm the disconnection. Check your Stripe dashboard.',
        ];
    }

    /**
     * Forget the connection locally.
     *
     * Separate from disconnect() because Stripe can tell us the authorisation
     * is already gone — the user revoked Duely from their own dashboard — and
     * calling deauthorize back at Stripe in that case is both pointless and an
     * error to handle for no reason.
     */
    public function clearConnection(int $tenantId): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE organizations
             SET stripe_account_id = NULL, stripe_charges_enabled = 0, stripe_payouts_enabled = 0,
                 stripe_account_connected_at = NULL, stripe_account_last_checked_at = NULL
             WHERE id = ?'
        );
        $statement->execute([$tenantId]);

        // Generated links belong to an account we no longer have; a manually
        // pasted link is the user's own and stays.
        $links = Database::connection()->prepare(
            'UPDATE invoices SET payment_url = NULL, stripe_payment_link_id = NULL, payment_url_is_generated = 0
             WHERE tenant_id = ? AND payment_url_is_generated = 1'
        );
        $links->execute([$tenantId]);
    }

    /**
     * Which workspace a connected account belongs to. Used by the webhook,
     * which is told the account and has to find its way home from there.
     */
    public function tenantForAccount(string $accountId): ?int
    {
        if (trim($accountId) === '') {
            return null;
        }

        $statement = Database::connection()->prepare(
            'SELECT id FROM organizations WHERE stripe_account_id = ? LIMIT 1'
        );
        $statement->execute([$accountId]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    // -------------------------------------------------------------- internals

    private function redirectUri(): string
    {
        return rtrim((string) Env::get('APP_URL', ''), '/') . '/settings/payments/callback';
    }

    private function organization(int $tenantId): array
    {
        $statement = Database::connection()->prepare('SELECT * FROM organizations WHERE id = ? LIMIT 1');
        $statement->execute([$tenantId]);

        return $statement->fetch() ?: [];
    }

    private function failure(string $message): array
    {
        return ['ok' => false, 'error' => $message, 'account_id' => null];
    }
}
