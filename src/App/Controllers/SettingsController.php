<?php

namespace Keel\App\Controllers;

use Keel\App\Models\Client;
use Keel\App\Models\EmailAccount;
use Keel\App\Services\Clock;
use Keel\App\Services\MailAccountService;
use Keel\App\Services\ProviderPresets;
use Keel\App\Services\TenantContext;
use Keel\App\Services\Timezones;
use Keel\Core\Activity;
use Keel\Core\Controller;
use Keel\Core\Request;

/**
 * The email-account settings screen and the JSON endpoints behind it.
 *
 * No method on this controller ever puts a credential into a response. The
 * form state carries booleans for "a password is stored", the probe endpoints
 * return diagnoses, and the only thing written back is a status.
 */
class SettingsController extends Controller
{
    public function __construct(private readonly MailAccountService $accounts = new MailAccountService())
    {
    }

    /**
     * GET /settings/email
     */
    public function email(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $user = TenantContext::user();

        $this->view('settings.email', [
            'title' => 'Email account - Duely',
            'metaDescription' => 'Connect the mailbox Duely sends invoice reminders from.',
            'account' => $this->accounts->formState($tenantId, $user),
            'providers' => ProviderPresets::catalogue(),
            // Connecting a mailbox is the highest-effort step in the product and
            // the page ended at the moment it succeeded. The wizard already
            // knows what comes next; the page just has to say so.
            'onboarding' => (new \Keel\App\Services\OnboardingService())->progress($tenantId),
            'user' => [
                'name' => (string) ($user['name'] ?? ''),
                'email' => (string) $user['email'],
            ],
        ]);
    }

    /**
     * POST /api/email-account/preset — prefill hosts and ports for an address.
     */
    /**
     * GET /settings/timezone — the workspace's display zone.
     */
    public function timezone(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $current = Timezones::forWorkspace($tenantId);

        $this->view('settings.timezone', [
            'title' => 'Timezone - Duely',
            'current' => $current,
            'timezones' => Timezones::catalogue(),
            'nowLocal' => Timezones::render(Clock::now(), $current, 'l, F j, g:i A'),
            'nowUtc' => Clock::now()->format('H:i'),
            'clientsOnDefault' => Client::countOnTimezone($tenantId, Timezones::DEFAULT),
            'notice' => $request->query['notice'] ?? null,
            'error' => $request->query['error'] ?? null,
        ]);
    }

    /**
     * POST /settings/timezone
     */
    public function saveTimezone(Request $request): never
    {
        $tenantId = TenantContext::requireId();
        $name = (string) $request->input('timezone', '');

        if (!Timezones::setForWorkspace($tenantId, $name)) {
            $this->redirect('/settings/timezone?error=' . rawurlencode(
                '"' . $name . '" is not a timezone Duely recognises.'
            ));
        }

        Activity::log('settings.timezone_changed', 'Organization', $tenantId, ['timezone' => $name]);

        $this->redirect('/settings/timezone?notice=' . rawurlencode('Times are now shown in ' . $name . '.'));
    }

    /**
     * POST /api/settings/timezone/detect — adopt the browser's zone as the
     * workspace default.
     *
     * Called once from onboarding. It only ever fills in a workspace still on
     * the UTC default: a zone somebody chose is never overwritten by whatever
     * machine they happen to be sitting at, and a user travelling with a laptop
     * should not find their invoices relabelled on landing.
     *
     * A default rather than a decision, which is why it needs no click. The
     * onboarding card shows the result and offers a select to change it.
     */
    public function detectTimezone(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $current = Timezones::forWorkspace($tenantId);

        if ($current !== Timezones::DEFAULT) {
            $this->json(['timezone' => $current, 'changed' => false]);
        }

        $detected = (string) $request->input('timezone', '');

        if (!Timezones::setForWorkspace($tenantId, $detected)) {
            $this->json(['timezone' => $current, 'changed' => false], 422);
        }

        Activity::log('settings.timezone_detected', 'Organization', $tenantId, ['timezone' => $detected]);

        $this->json(['timezone' => $detected, 'changed' => true]);
    }

    public function preset(Request $request): void
    {
        TenantContext::requireId();

        $email = trim((string) $request->input('email', ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->json(['error' => 'Enter a valid email address.'], 422);
        }

        $this->json(['preset' => $this->accounts->presetFor($email)]);
    }

    /**
     * POST /api/email-account/test — live SMTP + IMAP check, saves nothing.
     */
    public function test(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $input = $this->credentialInput($request);

        $validation = $this->validate($input);

        if ($validation !== null) {
            $this->json(['error' => $validation], 422);
        }

        $result = $this->accounts->test($tenantId, $input);

        $this->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * POST /api/email-account/save — test, then persist only if both pass.
     */
    public function save(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $user = TenantContext::user();
        $input = $this->credentialInput($request);

        $validation = $this->validate($input);

        if ($validation !== null) {
            $this->json(['error' => $validation], 422);
        }

        // Adding a mailbox is plan-limited. An existing account being edited
        // is not a new one, so the gate only applies to the first save.
        if (\Keel\App\Models\EmailAccount::findByEmail($tenantId, (string) $input['from_email']) === null) {
            $allowance = (new \Keel\App\Services\PlanService())
                ->canUseFeature($tenantId, \Keel\App\Services\PlanService::FEATURE_EMAIL_ACCOUNT);

            if (!$allowance['allowed']) {
                $this->json([
                    'error' => $allowance['reason'],
                    'upgrade_to' => $allowance['upgrade_to'],
                ], 402);
            }
        }

        $result = $this->accounts->save($tenantId, (int) $user['id'], $input);

        if (!$result['saved']) {
            // The connection test failed, so nothing was written.
            $this->json($result, 422);
        }

        Activity::log('email_account.connected', 'EmailAccount', (int) $result['account_id'], [
            'from_email' => $input['from_email'],
            'provider' => $input['provider'] ?? null,
        ]);

        $this->json($result + [
            'account' => $this->accounts->formState($tenantId, $user),
        ]);
    }

    /**
     * POST /api/email-account/send-test — deliver a real message to the user.
     */
    public function sendTest(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $user = TenantContext::user();

        $accountId = (int) $request->input('account_id', 0);

        if ($accountId <= 0) {
            $account = EmailAccount::defaultAccount($tenantId);
            $accountId = $account === null ? 0 : (int) $account['id'];
        }

        if ($accountId <= 0) {
            $this->json(['error' => 'Connect an email account first.'], 422);
        }

        $recipient = trim((string) $request->input('to', '')) ?: (string) $user['email'];

        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->json(['error' => 'Enter a valid recipient address.'], 422);
        }

        $diagnosis = $this->accounts->sendTestMessage(
            $tenantId,
            $accountId,
            $recipient,
            (string) ($user['name'] ?? '')
        );

        if ($diagnosis->isAuthProblem()) {
            $this->accounts->markNeedsReauth($tenantId, $accountId, $diagnosis);
        }

        $this->json(
            ['result' => $diagnosis->toArray(), 'sent_to' => $recipient],
            $diagnosis->succeeded() ? 200 : 422
        );
    }

    /**
     * POST /api/email-account/delete
     */
    public function delete(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $accountId = (int) $request->input('account_id', 0);

        if ($accountId <= 0) {
            $this->json(['error' => 'No account specified.'], 422);
        }

        if (!EmailAccount::exists($tenantId, $accountId)) {
            $this->json(['error' => 'That email account does not exist.'], 404);
        }

        $this->accounts->delete($tenantId, $accountId);

        Activity::log('email_account.disconnected', 'EmailAccount', $accountId);

        $this->json(['deleted' => true]);
    }

    // -------------------------------------------------------------- internals

    /**
     * Pull only the fields the form owns. Anything else a client sends is
     * ignored rather than passed through to the model.
     */
    private function credentialInput(Request $request): array
    {
        $fields = [
            'account_id',
            'provider',
            'from_name',
            'from_email',
            'reply_to',
            'smtp_host',
            'smtp_port',
            'smtp_encryption',
            'smtp_username',
            'smtp_password',
            'imap_host',
            'imap_port',
            'imap_encryption',
            'imap_username',
            'imap_password',
            'imap_folder',
        ];

        $input = [];

        foreach ($fields as $field) {
            $value = $request->input($field);

            if ($value !== null) {
                $input[$field] = is_string($value) ? $value : $value;
            }
        }

        return $input;
    }

    private function validate(array $input): ?string
    {
        $fromEmail = trim((string) ($input['from_email'] ?? ''));

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return 'Enter the email address you want reminders to come from.';
        }

        $replyTo = trim((string) ($input['reply_to'] ?? ''));

        if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            return 'The reply-to address is not a valid email address.';
        }

        if (trim((string) ($input['from_name'] ?? '')) === '') {
            return 'Enter the name your clients should see on the reminder.';
        }

        if (trim((string) ($input['smtp_host'] ?? '')) === '') {
            return 'Enter the outgoing (SMTP) server.';
        }

        if (trim((string) ($input['imap_host'] ?? '')) === '') {
            return 'Enter the incoming (IMAP) server. Duely needs it to notice replies.';
        }

        return null;
    }
}
