<?php

namespace Keel\App\Controllers;

use Keel\App\Services\FoundingCounter;
use Keel\Core\Auth;
use Keel\Core\Controller;
use Keel\Core\Request;

/**
 * The signup page.
 *
 * Thin on purpose. It renders one form and nothing else: the form posts to
 * `/auth/otp/request` and `/auth/otp/verify`, the same endpoints the sign-in
 * page uses, and the workspace is created in AuthController's post-login
 * redirect. There is no signup-specific authentication anywhere.
 *
 * That is the whole design. A second OTP implementation would need its own rate
 * limiting, its own expiry, its own replay handling -- and would be the one that
 * quietly falls behind the first.
 */
class SignupController extends Controller
{
    /**
     * GET /signup
     */
    public function show(Request $request): void
    {
        // Somebody already signed in has nothing to do here.
        if (Auth::check()) {
            $this->redirect('/dashboard');
        }

        // The marketing form is a plain GET, so somebody who typed their address
        // there arrives with it already filled in -- and it still works with no
        // JavaScript, which a fetch-and-redirect button would not.
        $email = filter_var(trim((string) ($request->query['email'] ?? '')), FILTER_VALIDATE_EMAIL);

        $this->view('marketing.signup', [
            'title' => 'Create your Duely account',
            'metaDescription' => 'Start chasing overdue invoices from your own inbox. No password, no card.',
            'founding' => (new FoundingCounter())->snapshot(),
            'prefilledEmail' => $email === false ? '' : $email,
        ]);
    }

    /**
     * GET /register — the other word people type.
     */
    public function alias(Request $request): never
    {
        $this->redirect('/signup');
    }
}
