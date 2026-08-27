<?php

use Keel\App\Controllers\AuthController;
use Keel\App\Controllers\ActivityController;
use Keel\App\Controllers\ApiFileController;
use Keel\App\Controllers\ApiTokenController;
use Keel\App\Controllers\BillingController;
use Keel\App\Controllers\ChaseController;
use Keel\App\Controllers\ClientController;
use Keel\App\Controllers\ConnectController;
use Keel\App\Controllers\ConnectWebhookController;
use Keel\App\Controllers\DashboardController;
use Keel\App\Controllers\SuperAdmin\AccountsController;
use Keel\App\Controllers\SuperAdmin\DashboardController as SuperAdminDashboardController;
use Keel\App\Controllers\SuperAdmin\ImpersonationController;
use Keel\App\Controllers\SuperAdmin\OperationsController;
use Keel\App\Controllers\SuperAdmin\SupportController;
use Keel\App\Controllers\DocsController;
use Keel\App\Controllers\FileController;
use Keel\App\Controllers\HealthController;
use Keel\App\Controllers\ImportController;
use Keel\App\Controllers\InvoiceController;
use Keel\App\Controllers\InvoiceExtractionController;
use Keel\App\Controllers\LlmsTxtController;
use Keel\App\Controllers\MarketingController;
use Keel\App\Controllers\ManifestController;
use Keel\App\Controllers\OnboardingController;
use Keel\App\Controllers\OrganizationController;
use Keel\App\Controllers\RobotsController;
use Keel\App\Controllers\SequenceController;
use Keel\App\Controllers\SettingsController;
use Keel\App\Controllers\ToneAssistController;
use Keel\App\Controllers\SitemapController;
use Keel\App\Controllers\ThemeController;
use Keel\App\Controllers\StripeWebhookController;
use Keel\App\Controllers\WaitlistController;
use Keel\App\Controllers\WelcomeController;
use Keel\App\Middleware\AuthMiddleware;
use Keel\App\Middleware\ApiAuthMiddleware;
use Keel\App\Middleware\ImpersonationGuardMiddleware;
use Keel\App\Middleware\CsrfMiddleware;
use Keel\App\Middleware\RequireOrgAdminMiddleware;
use Keel\App\Middleware\RequireOrganizationMiddleware;
use Keel\App\Middleware\RequireSuperAdminMiddleware;
use Keel\App\Middleware\ThrottleMiddleware;
use Keel\Core\Env;

/** @var \Keel\Core\Router $router */

$multiTenancyEnabled = (bool) Env::get('MULTI_TENANCY_ENABLED', false);

$router->get('/up', [HealthController::class, 'index']);
$router->get('/manifest.webmanifest', [ManifestController::class, 'index']);
$router->get('/sitemap.xml', [SitemapController::class, 'index']);
$router->get('/robots.txt', [RobotsController::class, 'index']);
$router->get('/llms.txt', [LlmsTxtController::class, 'index']);

$router->group(['middleware' => [CsrfMiddleware::class]], function ($router) use ($multiTenancyEnabled) {
    // Duely: the public site. Routes rather than files under public_html,
    // because a real file is served by the rewrite rule before the router runs
    // — no CSRF token for the waitlist form, and no sitemap registration.
    $router->get('/', [MarketingController::class, 'index'], ['sitemap' => true]);
    $router->get('/how-it-works', [MarketingController::class, 'howItWorks'], ['sitemap' => true]);
    $router->get('/pricing', [MarketingController::class, 'pricing'], ['sitemap' => true]);
    $router->get('/privacy', [MarketingController::class, 'privacy'], ['sitemap' => true]);
    $router->get('/terms', [MarketingController::class, 'terms'], ['sitemap' => true]);

    // Keel's own landing page, kept reachable for the starter-kit docs.
    $router->get('/keel', [WelcomeController::class, 'index']);

    $router->get('/docs', [DocsController::class, 'index'], ['sitemap' => true]);
    $router->get('/docs/{slug}', [DocsController::class, 'show']);

    $router->group(['middleware' => [ThrottleMiddleware::class]], function ($router) {
        // Waitlist. Throttled because it sends email to an address a stranger
        // chose, which is the shape of every open relay ever written.
        $router->post('/api/waitlist', [WaitlistController::class, 'join']);
        $router->get('/waitlist/confirm', [WaitlistController::class, 'confirm']);
        $router->get('/waitlist/unsubscribe', [WaitlistController::class, 'unsubscribe']);

        $router->get('/login', [AuthController::class, 'showLogin'], ['sitemap' => true]);
        $router->post('/auth/otp/request', [AuthController::class, 'requestOtp']);
        $router->post('/auth/otp/verify', [AuthController::class, 'verifyOtp']);
        $router->post('/auth/magic/request', [AuthController::class, 'requestMagicLink']);
        $router->get('/auth/magic', [AuthController::class, 'verifyMagicLink']);
    });

    $router->post('/logout', [AuthController::class, 'logout']);

// The way out of an impersonated session. Deliberately not under
// /super-admin, which such a session is blocked from reaching -- the exit
// cannot be behind the door it is escaping.
$router->post('/impersonation/stop', [ImpersonationController::class, 'stop']);

    if ($multiTenancyEnabled) {
        $router->get('/invite/accept', [OrganizationController::class, 'acceptInvite']);
    }

    // ImpersonationGuardMiddleware sits beside AuthMiddleware rather than
    // inside the panel: the actions it blocks live all over the product, and a
    // guard that only runs on pages somebody remembered to guard is not a guard.
    $router->group(
        ['middleware' => [AuthMiddleware::class, ImpersonationGuardMiddleware::class]],
        function ($router) use ($multiTenancyEnabled) {
        // The operator panel. Outside `if ($multiTenancyEnabled)` deliberately:
        // being the platform operator has nothing to do with whether this
        // install uses organizations, and nesting it there made the panel
        // unreachable on every single-tenant deployment -- which is all of them.
        $router->group(['middleware' => [RequireSuperAdminMiddleware::class]], function ($router) {
            // The operator panel. Throttled as a whole, and the middleware
            // 404s rather than 403s so the paths are not confirmed to
            // anybody who should not have them.
            $router->group(['middleware' => [ThrottleMiddleware::class]], function ($router) {
                // Tier 1 -- is anything broken. The landing page.
                $router->get('/super-admin', [OperationsController::class, 'index']);

                // Tier 2 -- how the business is doing.
                $router->get('/super-admin/metrics', [SuperAdminDashboardController::class, 'metrics']);

                // Tier 3 -- administering an account.
                $router->get('/super-admin/organizations', [AccountsController::class, 'index']);
                $router->get('/super-admin/organizations/{id}', [AccountsController::class, 'show']);
                $router->post('/super-admin/organizations/{id}/trial', [AccountsController::class, 'extendTrial']);
                $router->post('/super-admin/organizations/{id}/founding', [AccountsController::class, 'foundingSlot']);
                $router->post('/super-admin/organizations/{id}/plan', [AccountsController::class, 'changePlan']);
                $router->post('/super-admin/organizations/{id}/disable', [AccountsController::class, 'disable']);
                $router->post('/super-admin/organizations/{id}/enable', [AccountsController::class, 'enable']);
                $router->post('/super-admin/organizations/{id}/pause-chases', [AccountsController::class, 'pauseChases']);
                $router->post('/super-admin/organizations/{id}/reset-sessions', [AccountsController::class, 'resetSessions']);
                $router->post('/super-admin/organizations/{id}/resend-invite', [AccountsController::class, 'resendInvite']);

                // Tier 4 -- support access, and the trail it leaves.
                $router->get('/super-admin/support', [SupportController::class, 'index']);
                $router->post('/super-admin/support/open', [SupportController::class, 'open']);
                $router->get('/super-admin/support/{id}', [SupportController::class, 'show']);
                $router->get('/super-admin/audit', [SupportController::class, 'audit']);

                $router->get('/super-admin/impersonate/{userId}', [ImpersonationController::class, 'confirm']);
                $router->post('/super-admin/impersonate/{userId}/code', [ImpersonationController::class, 'sendCode']);
                $router->post('/super-admin/impersonate/{userId}', [ImpersonationController::class, 'start']);

                $router->get('/super-admin/activity', [ActivityController::class, 'platformIndex']);
            });
        });

        $router->post('/settings/theme', [ThemeController::class, 'update']);

        // The account's own activity, including every support access. Routed
        // unconditionally: the privacy page promises the owner can read this
        // without asking, and TenantContext scopes it to their workspace on
        // single-tenant installs exactly as it does on multi-tenant ones.
        $router->get('/settings/activity', [ActivityController::class, 'orgIndex']);

        $router->get('/settings/api-tokens', [ApiTokenController::class, 'index']);
        $router->post('/settings/api-tokens', [ApiTokenController::class, 'store']);
        $router->post('/settings/api-tokens/{id}/revoke', [ApiTokenController::class, 'destroy']);

        if ($multiTenancyEnabled) {
            $router->get('/onboarding/organization', [OrganizationController::class, 'showOnboarding']);
            $router->post('/onboarding/organization', [OrganizationController::class, 'createOrganization']);

            $router->group(['middleware' => [RequireOrgAdminMiddleware::class]], function ($router) {
                $router->get('/settings/organization', [OrganizationController::class, 'showSettings']);
                $router->get('/settings/members', [OrganizationController::class, 'showMembers']);
                $router->post('/settings/members/invite', [OrganizationController::class, 'sendInvite']);
            });
        }

        $applicationMiddleware = $multiTenancyEnabled ? [RequireOrganizationMiddleware::class] : [];

        $router->group(['middleware' => $applicationMiddleware], function ($router) {
            $router->get('/dashboard', [DashboardController::class, 'index']);

            // Duely: guided first run.
            $router->get('/onboarding', [OnboardingController::class, 'index']);
            $router->get('/api/onboarding/progress', [OnboardingController::class, 'progress']);
            $router->post('/api/onboarding/reviewed', [OnboardingController::class, 'markReviewed']);
            $router->post('/api/onboarding/skip', [OnboardingController::class, 'skip']);
            $router->post('/api/onboarding/dismiss-payment', [OnboardingController::class, 'dismissPayment']);
            $router->post('/api/onboarding/resume', [OnboardingController::class, 'resume']);

            // Duely: plans and trials.
            $router->get('/api/billing/status', [BillingController::class, 'status']);
            $router->post('/api/billing/trial', [BillingController::class, 'startTrial']);
            $router->get('/billing/upgrade', [BillingController::class, 'showPlans']);
            $router->get('/billing/success', [BillingController::class, 'success']);
            $router->get('/billing/cancel', [BillingController::class, 'cancel']);
            $router->post('/billing/checkout', [BillingController::class, 'checkout']);
            $router->post('/billing/portal', [BillingController::class, 'portal']);
            $router->post('/files', [FileController::class, 'store']);
            $router->get('/files/{id}', [FileController::class, 'show']);

            // Duely: clients, invoices, and the CSV import wizard.
            $router->get('/clients', [ClientController::class, 'index']);
            $router->get('/clients/new', [ClientController::class, 'create']);
            $router->get('/clients/{id}', [ClientController::class, 'edit']);
            $router->get('/api/clients', [ClientController::class, 'listJson']);
            $router->post('/api/clients', [ClientController::class, 'store']);
            $router->post('/api/clients/{id}/archive', [ClientController::class, 'archive']);
            $router->post('/api/clients/{id}/delete', [ClientController::class, 'destroy']);

            $router->get('/invoices', [InvoiceController::class, 'index']);
            $router->get('/invoices/new', [InvoiceController::class, 'create']);
            $router->get('/invoices/import', [ImportController::class, 'show']);
            $router->get('/invoices/{id}', [InvoiceController::class, 'edit']);
            $router->get('/invoices/{id}/timeline', [InvoiceController::class, 'show']);
            $router->get('/api/invoices', [InvoiceController::class, 'listJson']);
            $router->get('/api/dashboard', [DashboardController::class, 'metrics']);

            // Duely: manual control over a running chase.
            $router->post('/api/chases/{id}/pause', [ChaseController::class, 'pause']);
            $router->post('/api/chases/{id}/resume', [ChaseController::class, 'resume']);
            $router->post('/api/chases/{id}/stop', [ChaseController::class, 'stop']);
            $router->post('/api/chases/{id}/send-now', [ChaseController::class, 'sendNow']);
            $router->post('/api/invoices/undo', [ChaseController::class, 'undoAction']);
            $router->post('/api/invoices/{id}/mark-paid', [ChaseController::class, 'markPaid']);
            $router->post('/api/invoices/{id}/start-chase', [ChaseController::class, 'startChase']);
            $router->post('/api/invoices', [InvoiceController::class, 'store']);
            $router->post('/api/invoices/{id}/status', [InvoiceController::class, 'updateStatus']);
            $router->post('/api/invoices/{id}/delete', [InvoiceController::class, 'destroy']);

            // Upload and validate are read-only; only commit writes.
            // Reading an invoice document. Opt-in per workspace; the endpoint
            // returns a draft and never writes an invoice.
            $router->get('/api/invoices/extraction/status', [InvoiceExtractionController::class, 'status']);
            $router->post('/api/invoices/extraction/consent', [InvoiceExtractionController::class, 'consent']);
            $router->post('/api/invoices/extract', [InvoiceExtractionController::class, 'extract']);

            $router->post('/api/invoices/import/upload', [ImportController::class, 'upload']);
            $router->post('/api/invoices/import/validate', [ImportController::class, 'validate']);
            $router->post('/api/invoices/import/commit', [ImportController::class, 'commit']);
            $router->post('/api/invoices/import/cancel', [ImportController::class, 'cancel']);
            $router->post('/api/invoices/import/errors.csv', [ImportController::class, 'downloadErrors']);

            // Duely: the escalation ladder and its templates.
            $router->get('/sequences', [SequenceController::class, 'index']);
            $router->get('/sequences/{id}', [SequenceController::class, 'edit']);
            $router->post('/api/sequences/preview', [SequenceController::class, 'preview']);

            // Duely: Claude-assisted drafting. Both endpoints return a proposal
            // the user accepts or discards; neither writes to a sequence.
            $router->get('/api/tone-assist/allowance', [ToneAssistController::class, 'allowance']);
            $router->post('/api/tone-assist/rewrite', [ToneAssistController::class, 'rewrite']);
            $router->post('/api/tone-assist/sequence', [ToneAssistController::class, 'sequence']);
            $router->post('/api/sequences/restore-default', [SequenceController::class, 'restoreDefault']);
            $router->post('/api/sequences/{id}', [SequenceController::class, 'update']);
            $router->post('/api/sequences/{id}/default', [SequenceController::class, 'makeDefault']);
            $router->post('/api/sequences/{id}/delete', [SequenceController::class, 'destroy']);

            // Duely: the mailbox reminders are sent from and replies are read out of.
            $router->get('/settings/email', [SettingsController::class, 'email']);
            $router->post('/api/email-account/preset', [SettingsController::class, 'preset']);
            $router->post('/api/email-account/test', [SettingsController::class, 'test']);
            $router->post('/api/email-account/save', [SettingsController::class, 'save']);
            $router->post('/api/email-account/send-test', [SettingsController::class, 'sendTest']);
            $router->post('/api/email-account/delete', [SettingsController::class, 'delete']);

            // Duely: collecting payment through the user's own Stripe account.
            // Off by default; a workspace that never opens this page is
            // untouched by all of it.
            $router->get('/settings/payments', [ConnectController::class, 'show']);
            $router->get('/settings/payments/callback', [ConnectController::class, 'callback']);
            $router->post('/settings/payments/connect', [ConnectController::class, 'connect']);
            $router->post('/settings/payments/refresh', [ConnectController::class, 'refresh']);
            $router->get('/settings/payments/choose', [ConnectController::class, 'choose']);
            $router->post('/settings/payments/mode', [ConnectController::class, 'setMode']);
            $router->post('/settings/payments/disconnect', [ConnectController::class, 'disconnect']);
            $router->post('/api/invoices/{id}/payment-link', [ConnectController::class, 'createLink']);
        });
    });
});

$router->group(['prefix' => '/api/v1', 'middleware' => [ThrottleMiddleware::class, ApiAuthMiddleware::class]], function ($router) {
    $router->get('/files', [ApiFileController::class, 'index']);
});

$router->post('/webhooks/stripe', [StripeWebhookController::class, 'handle']);

// Connect payments. A separate endpoint from the subscription webhook above,
// with a separate signing secret, so an event captured from one cannot be
// replayed against the other. Outside every middleware group: Stripe carries no
// session and no CSRF token, and its signature is the authentication.
$router->post('/webhooks/stripe-connect', [ConnectWebhookController::class, 'handle']);
