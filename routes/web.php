<?php

use Keel\App\Controllers\AuthController;
use Keel\App\Controllers\ActivityController;
use Keel\App\Controllers\ApiFileController;
use Keel\App\Controllers\ApiTokenController;
use Keel\App\Controllers\BillingController;
use Keel\App\Controllers\ChaseController;
use Keel\App\Controllers\ClientController;
use Keel\App\Controllers\DashboardController;
use Keel\App\Controllers\DocsController;
use Keel\App\Controllers\FileController;
use Keel\App\Controllers\HealthController;
use Keel\App\Controllers\ImportController;
use Keel\App\Controllers\InvoiceController;
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
use Keel\App\Controllers\SuperAdminController;
use Keel\App\Controllers\ThemeController;
use Keel\App\Controllers\StripeWebhookController;
use Keel\App\Controllers\WaitlistController;
use Keel\App\Controllers\WelcomeController;
use Keel\App\Middleware\AuthMiddleware;
use Keel\App\Middleware\ApiAuthMiddleware;
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

    if ($multiTenancyEnabled) {
        $router->get('/invite/accept', [OrganizationController::class, 'acceptInvite']);
    }

    $router->group(['middleware' => [AuthMiddleware::class]], function ($router) use ($multiTenancyEnabled) {
        $router->post('/settings/theme', [ThemeController::class, 'update']);

        $router->get('/settings/api-tokens', [ApiTokenController::class, 'index']);
        $router->post('/settings/api-tokens', [ApiTokenController::class, 'store']);
        $router->post('/settings/api-tokens/{id}/revoke', [ApiTokenController::class, 'destroy']);

        if ($multiTenancyEnabled) {
            $router->get('/onboarding/organization', [OrganizationController::class, 'showOnboarding']);
            $router->post('/onboarding/organization', [OrganizationController::class, 'createOrganization']);

            $router->group(['middleware' => [RequireOrgAdminMiddleware::class]], function ($router) {
                $router->get('/settings/organization', [OrganizationController::class, 'showSettings']);
                $router->get('/settings/members', [OrganizationController::class, 'showMembers']);
                $router->get('/settings/activity', [ActivityController::class, 'orgIndex']);
                $router->post('/settings/members/invite', [OrganizationController::class, 'sendInvite']);
            });

            $router->group(['middleware' => [RequireSuperAdminMiddleware::class]], function ($router) {
                $router->get('/super-admin/organizations', [SuperAdminController::class, 'index']);
                $router->get('/super-admin/organizations/{id}', [SuperAdminController::class, 'showOrganization']);
                $router->get('/super-admin/activity', [ActivityController::class, 'platformIndex']);
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
        });
    });
});

$router->group(['prefix' => '/api/v1', 'middleware' => [ThrottleMiddleware::class, ApiAuthMiddleware::class]], function ($router) {
    $router->get('/files', [ApiFileController::class, 'index']);
});

$router->post('/webhooks/stripe', [StripeWebhookController::class, 'handle']);
