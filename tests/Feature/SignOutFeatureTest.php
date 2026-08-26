<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\Core\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Signing out.
 *
 * `POST /logout` worked from the day the starter kit was written. Nothing in any
 * view ever linked to it, so for a user there was no way out of the product at
 * all — which is why the first test here is about the button existing rather
 * than about the endpoint working.
 */
class SignOutFeatureTest extends TestCase
{
    /**
     * Every signed-in page. A control on some pages and not others is the same
     * bug in a smaller form: the user still has to hunt for it.
     */
    public static function signedInPages(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'invoices' => ['/invoices'],
            'new invoice' => ['/invoices/new'],
            'clients' => ['/clients'],
            'new client' => ['/clients/new'],
            'sequences' => ['/sequences'],
            'email settings' => ['/settings/email'],
            'payment settings' => ['/settings/payments'],
            'plans' => ['/billing/upgrade'],
            'onboarding' => ['/onboarding'],
            'api tokens' => ['/settings/api-tokens'],
        ];
    }

    #[DataProvider('signedInPages')]
    public function testEverySignedInPageOffersAWayOut(string $path): void
    {
        $this->actingAs(['email' => 'ada@studio.test']);

        $response = $this->get($path);

        self::assertSame(200, $response->status, $path . ' did not render.');
        self::assertStringContainsString(
            'action="/logout"',
            $response->body,
            $path . ' has no way to sign out.'
        );
        self::assertStringContainsString('Sign out', $response->body, $path . ' has no sign-out label.');
    }

    public function testSigningOutEndsTheSession(): void
    {
        $this->actingAs(['email' => 'ada@studio.test']);
        self::assertTrue(Session::has('user_id'));

        $response = $this->post('/logout', ['_csrf' => $this->csrfToken()]);

        self::assertSame(302, $response->status);
        self::assertSame('/login?notice=signed_out', $response->headers['Location'] ?? '');
        self::assertFalse(Session::has('user_id'));
    }

    public function testTheLoginPageConfirmsIt(): void
    {
        $response = $this->get('/login?notice=signed_out');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('You are signed out', $response->body);
    }

    public function testAfterSigningOutTheDashboardIsNoLongerReachable(): void
    {
        $this->actingAs(['email' => 'ada@studio.test']);
        $this->post('/logout', ['_csrf' => $this->csrfToken()]);

        $response = $this->get('/dashboard');

        self::assertSame(302, $response->status);
        self::assertStringStartsWith('/login', (string) ($response->headers['Location'] ?? ''));
    }

    public function testSignOutIsAPostAndIsNeverReachableByAGet(): void
    {
        // A GET that ends a session can be fired by any image tag on any page
        // the user happens to visit. The control is a form for that reason, not
        // for style, and there must be no GET route to fall back to.
        //
        // Asserted against the route table rather than by requesting /logout:
        // an unrouted GET renders the 404 page through ErrorHandler, which
        // exits the process and would take the test runner with it.
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/web.php');

        self::assertStringContainsString(
            "\$router->post('/logout'",
            $routes,
            'Sign out must be registered as a POST route.'
        );
        self::assertStringNotContainsString("\$router->get('/logout'", $routes);

        $this->actingAs(['email' => 'ada@studio.test']);
        $body = $this->get('/dashboard')->body;

        self::assertMatchesRegularExpression(
            '/<form[^>]+method="post"[^>]+action="\/logout"/i',
            $body,
            'The sign-out control must post.'
        );
    }

    public function testSignOutWithoutACsrfTokenIsRejected(): void
    {
        $this->actingAs(['email' => 'ada@studio.test']);

        $response = $this->post('/logout', []);

        self::assertSame(419, $response->status);
        self::assertTrue(Session::has('user_id'));
    }
}
