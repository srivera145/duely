<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class OtpRoundTripFeatureTest extends TestCase
{
    public function testOtpRoundTripEstablishesSessionAndRedirectsToDashboard(): void
    {
        $email = 'otp_user@example.test';

        $requestResponse = $this->postJson('/auth/otp/request', ['email' => $email], [
            'X-CSRF-Token' => $this->csrfToken(),
        ]);

        self::assertSame(200, $requestResponse->status);
        self::assertTrue((bool) ($requestResponse->json()['success'] ?? false));

        $mailLog = $this->latestMailLog();
        self::assertNotSame('', $mailLog);

        preg_match('/\b(\d{6})\b/', $mailLog, $matches);
        $code = $matches[1] ?? '';

        self::assertMatchesRegularExpression('/^\d{6}$/', $code);

        $verifyResponse = $this->postJson('/auth/otp/verify', [
            'email' => $email,
            'code' => $code,
        ], [
            'X-CSRF-Token' => $this->csrfToken(),
        ]);

        self::assertSame(200, $verifyResponse->status);
        self::assertTrue(isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0);

        // A first arrival now gets a workspace and lands in onboarding. The
        // same two endpoints serve signup and sign-in, so this is the signup
        // path as well -- there is no second OTP implementation to keep in step.
        self::assertSame('/onboarding', (string) ($verifyResponse->json()['redirect'] ?? ''));

        $organizationId = $this->organizationIdFor($email);
        self::assertGreaterThan(0, $organizationId, 'signing in for the first time made no workspace');
    }

    public function testAReturningUserGoesStraightToTheirDashboard(): void
    {
        $email = 'returning@studio.test';

        // First round trip: the workspace is created.
        $this->completeOtp($email);
        $firstTenantId = $this->organizationIdFor($email);

        // Second round trip: the same account, no second workspace.
        $redirect = $this->completeOtp($email);

        self::assertSame('/dashboard', $redirect);
        self::assertSame($firstTenantId, $this->organizationIdFor($email));
    }

    /**
     * One full request-and-verify round trip. Returns where it redirected to.
     */
    private function completeOtp(string $email): string
    {
        $this->postJson('/auth/otp/request', ['email' => $email], [
            'X-CSRF-Token' => $this->csrfToken(),
        ]);

        // Read from the plain-text part, which carries the code and nothing
        // else shaped like one. A bare six-digit match also finds the
        // `#111827` in the HTML template's inline styles.
        preg_match_all('/^\s{4}([0-9]{6})$/m', $this->latestMailLog(), $matches);

        self::assertNotSame([], $matches[1], 'no verification code was emailed to ' . $email);

        // The newest, so a second round trip does not replay the first code.
        $code = (string) end($matches[1]);

        $response = $this->postJson('/auth/otp/verify', ['email' => $email, 'code' => $code], [
            'X-CSRF-Token' => $this->csrfToken(),
        ]);

        self::assertSame(200, $response->status, 'verification failed for ' . $email);

        return (string) ($response->json()['redirect'] ?? '');
    }

    private function organizationIdFor(string $email): int
    {
        $statement = \Keel\Core\Database::connection()->prepare(
            'SELECT organization_id FROM users WHERE email = ? LIMIT 1'
        );
        $statement->execute([$email]);

        return (int) $statement->fetchColumn();
    }
}
