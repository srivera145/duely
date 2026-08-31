<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What the marketing site says about collecting payment.
 *
 * The claim is a genuine differentiator — most invoicing tools skim 1–2% and
 * Duely takes nothing — which is exactly why the wording has to be exact. The
 * risk is not underselling it; the risk is describing Duely as something it is
 * not, in words that carry regulatory meaning.
 */
class MarketingPaymentsCopyFeatureTest extends TestCase
{
    /** Pages that may mention payments at all. */
    private const PAGES = ['/', '/pricing', '/how-it-works'];

    /** Every public page, for the words that must never appear anywhere. */
    private const ALL_PUBLIC = ['/', '/pricing', '/how-it-works', '/privacy', '/terms', '/signup'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectConfigured('ca_marketing_test');
    }

    // --------------------------- self-check: the gate

    #[DataProvider('pageProvider')]
    public function testNoPaymentCopyRendersWhenConnectIsNotConfigured(string $path): void
    {
        $this->connectConfigured('');

        $body = $this->get($path)->body;

        // A marketing page promising a feature the deployment cannot deliver is
        // worse than one that says nothing -- the visitor finds out after
        // signing up.
        self::assertStringNotContainsString('pay from the reminder', $body, $path);
        self::assertStringNotContainsString('Duely adds no fee of its own', $body, $path);
        self::assertStringNotContainsString('merchant of record', $body, $path);
    }

    #[DataProvider('pageProvider')]
    public function testThePaymentSectionAppearsOnceConnectIsConfigured(string $path): void
    {
        $body = $this->get($path)->body;

        self::assertStringContainsString('Duely adds no fee of its own', $body, $path . ' does not make the claim');
    }

    public static function pageProvider(): array
    {
        return array_map(static fn (string $path): array => [$path], self::PAGES);
    }

    // ------------------- self-check: no fee figure anywhere, ever

    #[DataProvider('allPublicProvider')]
    public function testNoPercentageOrAmountIsQuotedForStripesFee(string $path): void
    {
        $body = $this->textOf($path);

        // Stripe's rate depends on the account and the country. A figure printed
        // here goes stale without anybody editing the page, and a stale fee
        // quote on a pricing page ends up in a complaint.
        //
        // Matched near the words that would make it a fee claim, so the $19 and
        // $39 plan prices do not trip it.
        self::assertDoesNotMatchRegularExpression(
            '/\d+(\.\d+)?\s*%/',
            $body,
            $path . ' quotes a percentage'
        );

        foreach (['processing fee of', 'fee is 2', 'fee of $', '2.9', '1.4', '30c', '30¢'] as $figure) {
            self::assertStringNotContainsStringIgnoringCase($figure, $body, $path . ' quotes a fee: ' . $figure);
        }
    }

    // ------------- self-check: Duely is not described as a payments business

    #[DataProvider('allPublicProvider')]
    public function testTheRegulatedWordsNeverAppear(string $path): void
    {
        $body = $this->textOf($path);

        // "Processor" and "facilitator" carry regulatory meaning and neither is
        // true of Duely.
        foreach (['processor', 'facilitator', 'payment institution', 'money transmitter'] as $word) {
            self::assertStringNotContainsStringIgnoringCase($word, $body, $path . ' says "' . $word . '"');
        }
    }

    #[DataProvider('allPublicProvider')]
    public function testNoPageClaimsDuelyHoldsOrHandlesTheMoney(string $path): void
    {
        $body = $this->textOf($path);

        foreach ([
            'we hold your',
            'holds your funds',
            'holds funds',
            'we receive your payment',
            'funds are held',
            'in escrow',
            'we process payments',
            'we collect payment on your behalf',
            'pays into our',
            'through our account',
        ] as $claim) {
            self::assertStringNotContainsStringIgnoringCase($claim, $body, $path . ' claims: ' . $claim);
        }
    }

    // ----------------------- self-check: the claims that must be there

    public function testThePageSaysTheMoneyGoesDirectlyToTheUser(): void
    {
        foreach (self::PAGES as $path) {
            $body = $this->textOf($path);

            self::assertStringContainsStringIgnoringCase(
                'your own Stripe account',
                $body,
                $path . ' does not say whose account the money lands in'
            );
        }
    }

    public function testTheHomepageSaysItNeverPassesThroughDuely(): void
    {
        self::assertStringContainsString('never passes through Duely', $this->textOf('/'));
    }

    public function testMerchantOfRecordIsFramedAsControlNotLiability(): void
    {
        $body = $this->textOf('/');

        self::assertStringContainsString('you stay the merchant of record', strtolower($body));

        // Their account, their payouts, their relationship. The accurate framing
        // happens also to be the appealing one.
        self::assertStringContainsStringIgnoringCase('your payouts', $body);
        self::assertStringContainsStringIgnoringCase('your relationship with the client', $body);
    }

    public function testTheFeeSentenceNamesWhoseRateItIs(): void
    {
        foreach (self::PAGES as $path) {
            self::assertStringContainsStringIgnoringCase(
                'your own Stripe rate',
                $this->textOf($path),
                $path . ' does not say whose rate the fee is at'
            );
        }
    }

    // ------------------ self-check: optional, said on every page

    #[DataProvider('pageProvider')]
    public function testEveryPageThatMentionsPaymentsSaysItIsOptional(string $path): void
    {
        $body = $this->textOf($path);

        self::assertStringContainsStringIgnoringCase('optional', $body, $path . ' does not say it is optional');

        // And that the product works without it. Plenty of people will paste a
        // PayPal link or take a bank transfer, and the page must not imply
        // Duely-collected payment is the only way through.
        self::assertStringContainsStringIgnoringCase(
            'work exactly the same without it',
            $body,
            $path . ' does not say reminders work without it'
        );
    }

    // ---------------- self-check: every page describes it consistently

    public function testEveryPageIncludingTermsAndPrivacyAgreesOnTheFacts(): void
    {
        // The four claims, in whatever words each page uses. Privacy and terms
        // were written when Connect shipped and are checked here rather than
        // rewritten -- if this fails, they drifted.
        $checks = [
            '/privacy' => ['own Stripe account', 'merchant of record', 'optional'],
            '/terms' => ['own Stripe account', 'merchant of record', 'optionally'],
        ];

        foreach ($checks as $path => $phrases) {
            $body = $this->textOf($path);

            foreach ($phrases as $phrase) {
                self::assertStringContainsStringIgnoringCase($phrase, $body, $path . ' lost: ' . $phrase);
            }
        }

        // And neither promises Duely charges something it does not.
        foreach (['/privacy', '/terms'] as $path) {
            self::assertStringNotContainsStringIgnoringCase('our fee', $this->textOf($path), $path);
        }
    }

    public static function allPublicProvider(): array
    {
        return array_map(static fn (string $path): array => [$path], self::ALL_PUBLIC);
    }

    // ------------------------------------------------------------------ helpers

    private function connectConfigured(string $clientId): void
    {
        $_ENV['STRIPE_CONNECT_CLIENT_ID'] = $clientId;
        $_SERVER['STRIPE_CONNECT_CLIENT_ID'] = $clientId;
    }

    /**
     * The rendered page with its markup stripped, so a class name containing
     * "processor" could never pass or fail a copy assertion.
     */
    private function textOf(string $path): string
    {
        $body = $this->get($path)->body;

        // Scripts and styles carry vocabulary that is not copy.
        $body = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#si', ' ', $body) ?? $body;
        $body = preg_replace('/<!--.*?-->/s', ' ', $body) ?? $body;
        $body = strip_tags($body);

        return trim(preg_replace('/\s+/', ' ', html_entity_decode($body, ENT_QUOTES, 'UTF-8')) ?? '');
    }
}
