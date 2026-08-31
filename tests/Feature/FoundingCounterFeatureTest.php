<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Jobs\AnnounceSignupToWaitlistJob;
use Keel\App\Services\Clock;
use Keel\App\Services\FoundingCounter;
use Keel\App\Services\PlanService;
use Keel\App\Services\WaitlistService;
use Keel\Core\Database;
use Tests\TestCase;

/**
 * The founding counter, and signup replacing the waitlist.
 *
 * The counter is a scarcity number on a public page, which is exactly the kind
 * of number products lie about. These tests pin it to the table it claims to
 * read, and pin the copy to three states that all have to read like sentences a
 * person would write.
 */
class FoundingCounterFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The counter caches for a minute across requests, and a test that
        // inherited the previous test's number would be measuring the cache.
        FoundingCounter::forget();
    }

    // ------------------- self-check: the number matches the table

    public function testTheCounterMatchesTheFreeRowsInFoundingSlots(): void
    {
        $counter = new FoundingCounter();

        self::assertSame($this->freeSlots(), $counter->snapshot()['remaining']);

        $plans = new PlanService();

        for ($i = 0; $i < 7; $i++) {
            $plans->claimFoundingSlot($this->workspace('Cohort ' . $i));
        }

        FoundingCounter::forget();

        self::assertSame($this->freeSlots(), (new FoundingCounter())->snapshot()['remaining']);
        self::assertSame(PlanService::FOUNDING_SLOTS - 7, $this->freeSlots());
    }

    public function testTheCounterIsNeverFudgedUpwardsOrDownwards(): void
    {
        $plans = new PlanService();

        for ($i = 0; $i < PlanService::FOUNDING_SLOTS; $i++) {
            $plans->claimFoundingSlot($this->workspace('Cohort ' . $i));
        }

        FoundingCounter::forget();
        $snapshot = (new FoundingCounter())->snapshot();

        // Zero is zero. No floor at "a few left", no rounding to keep the page
        // looking alive.
        self::assertSame(0, $snapshot['remaining']);
        self::assertSame(PlanService::FOUNDING_SLOTS, $snapshot['taken']);
        self::assertSame(FoundingCounter::STATE_GONE, $snapshot['state']);
    }

    public function testClaimingASlotMovesTheCounterWithinTheCacheWindow(): void
    {
        $counter = new FoundingCounter();
        $before = $counter->snapshot()['remaining'];

        // The cache is a minute long. Without invalidation on claim, this would
        // still read $before -- and the last few places are exactly where a
        // stale number would be noticed.
        (new PlanService())->claimFoundingSlot($this->workspace('Immediate'));

        self::assertSame($before - 1, (new FoundingCounter())->snapshot()['remaining']);
    }

    public function testTheCachedValueIsServedWithinTheWindow(): void
    {
        $counter = new FoundingCounter();
        $counter->snapshot();

        // Taken behind the counter's back -- a direct write rather than
        // PlanService, which invalidates the cache on purpose. Only a live
        // query would see this.
        $tenantId = $this->workspace('Behind the cache');

        Database::connection()->prepare(
            'UPDATE founding_slots SET tenant_id = ?, claimed_at = NOW()
             WHERE tenant_id IS NULL ORDER BY slot_number ASC LIMIT 1'
        )->execute([$tenantId]);

        // A second request: same process, fresh object, no in-memory memo. That
        // is what makes this exercise the file cache rather than the memo, which
        // would short-circuit before it.
        $this->forgetMemoOnly();

        $second = (new FoundingCounter())->snapshot();

        self::assertTrue($second['cached'], 'the second request went to the database');
        self::assertSame(PlanService::FOUNDING_SLOTS, $second['remaining']);

        // And it does expire rather than caching forever.
        $this->forgetMemoOnly();

        $later = (new FoundingCounter())->snapshot(
            Clock::now()->modify('+' . (FoundingCounter::CACHE_SECONDS + 5) . ' seconds')
        );

        self::assertFalse($later['cached']);
        self::assertSame(PlanService::FOUNDING_SLOTS - 1, $later['remaining']);
    }

    // ----------------------- self-check: all three copy states read naturally

    public function testThePlacesLeftStateNamesTheNumberAndWhatItBuys(): void
    {
        $body = $this->get('/')->body;

        self::assertStringContainsString('50 of 50 founding places left', $body);
        self::assertStringContainsString('$19 a month for as long as you stay', $body);
    }

    public function testTheFewLeftStateDropsTheTotalAndAddsNoUrgency(): void
    {
        $this->leaveExactly(3);

        $body = $this->get('/')->body;

        self::assertStringContainsString('3 founding places left', $body);

        // No manufactured urgency anywhere on the page. The scarcity is real --
        // fifty rows in a table -- and dressing it up is what makes people
        // suspect it is not.
        foreach (['hurry', 'act now', 'ending soon', 'last chance', 'countdown', 'expires in'] as $pressure) {
            self::assertStringNotContainsStringIgnoringCase($pressure, $body, 'manufactured urgency: ' . $pressure);
        }
    }

    public function testTheLastPlaceIsSingular(): void
    {
        $this->leaveExactly(1);

        // "1 founding places left" is the kind of sentence that tells a reader
        // nobody looked at this page.
        self::assertStringContainsString('One founding place left', $this->get('/')->body);
    }

    public function testTheNoneLeftStateReadsAsAnEndingNotAFailure(): void
    {
        $this->leaveExactly(0);

        $body = $this->get('/')->body;

        self::assertStringContainsString('founding places have been taken', $body);
        self::assertStringContainsString('standard pricing', $body);
        self::assertStringContainsString('free plan has no time limit', $body);

        // Not apologetic, and not pretending there is still something to claim.
        self::assertStringNotContainsString('founding places left', $body);
        foreach (['sorry', 'unfortunately', 'sold out'] as $word) {
            self::assertStringNotContainsStringIgnoringCase($word, $body, 'apologetic: ' . $word);
        }
    }

    // --------------- self-check: every page describes the offer identically

    public function testTheHomepageAndPricingDescribeTheOfferInTheSameWords(): void
    {
        $this->leaveExactly(12);

        $sentence = 'Signing up takes one and holds it for 30 days.';

        foreach (['/', '/pricing', '/how-it-works', '/signup'] as $path) {
            self::assertStringContainsString(
                $sentence,
                $this->get($path)->body,
                $path . ' describes the founding offer differently'
            );
        }
    }

    public function testEveryMarketingPageOffersSignupRatherThanTheWaitlist(): void
    {
        foreach (['/', '/pricing', '/how-it-works', '/privacy'] as $path) {
            $body = $this->get($path)->body;

            self::assertStringContainsString('action="/signup"', $body, $path . ' has no signup form');
            self::assertStringNotContainsString('action="/api/waitlist"', $body, $path . ' still shows the waitlist');
        }
    }

    public function testTheSignupFormAsksForNothingButAnEmail(): void
    {
        $body = $this->get('/')->body;

        preg_match('/<form[^>]+action="\/signup".*?<\/form>/s', $body, $matches);
        $form = $matches[0] ?? '';

        self::assertNotSame('', $form);

        preg_match_all('/<input[^>]+type="(\w+)"/', $form, $inputs);

        // One visible field. Hidden inputs carry the source and nothing a
        // person has to fill in.
        self::assertSame(['email'], array_values(array_filter(
            $inputs[1],
            static fn (string $type): bool => $type !== 'hidden'
        )));
    }

    // ------------------------ self-check: the waitlist still works

    public function testTheWaitlistEndpointsAndLinksStillWork(): void
    {
        // The form is gone from the pages; the machinery behind it is not. Those
        // confirm and unsubscribe links are in inboxes already.
        $service = new WaitlistService();
        $service->join('waiting@studio.test', [], Clock::now());

        preg_match('/waitlist\/confirm\?token=([a-f0-9]{64})/', $this->latestMailLog(), $matches);
        self::assertNotEmpty($matches[1] ?? '', 'the waitlist stopped sending confirmations');

        self::assertSame(200, $this->get('/waitlist/confirm?token=' . $matches[1])->status);
        self::assertSame(1, $service->confirmedCount());

        $unsubscribe = $service->unsubscribeUrl('waiting@studio.test');
        $path = parse_url($unsubscribe, PHP_URL_PATH) . '?' . parse_url($unsubscribe, PHP_URL_QUERY);

        self::assertSame(200, $this->get($path)->status);
    }

    // ----------------- self-check: the waitlist hears about it, once

    public function testTheWaitlistIsToldSignupIsOpenExactlyOnce(): void
    {
        $service = new WaitlistService();
        $service->join('warm@studio.test', [], Clock::now());

        preg_match('/waitlist\/confirm\?token=([a-f0-9]{64})/', $this->latestMailLog(), $matches);
        $this->get('/waitlist/confirm?token=' . $matches[1]);

        $job = new AnnounceSignupToWaitlistJob();

        $first = $job->run();
        self::assertSame(1, $first['eligible']);
        self::assertSame(1, $first['sent']);

        $log = $this->latestMailLog();
        self::assertStringContainsString('Duely is open', $log);
        self::assertStringContainsString('/signup?email=', $log);

        // The unsubscribe link survives. Somebody who waited months for one
        // email should not have to hunt for the way out of the second.
        self::assertStringContainsString('/waitlist/unsubscribe?email=', $log);

        $second = $job->run();
        self::assertSame(0, $second['eligible'], 'the waitlist would have been mailed twice');
        self::assertSame(0, $second['sent']);
    }

    public function testAnUnconfirmedOrUnsubscribedAddressIsNeverAnnouncedTo(): void
    {
        $service = new WaitlistService();

        // Joined but never confirmed: Duely has permission to send the
        // confirmation and nothing else.
        $service->join('pending@studio.test', [], Clock::now());

        // Confirmed, then left.
        $service->join('left@studio.test', [], Clock::now());
        preg_match_all('/waitlist\/confirm\?token=([a-f0-9]{64})/', $this->latestMailLog(), $matches);
        $this->get('/waitlist/confirm?token=' . end($matches[1]));
        $service->unsubscribe('left@studio.test', $service->unsubscribeToken('left@studio.test'));

        self::assertSame(0, (new AnnounceSignupToWaitlistJob())->run(true)['eligible']);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Take slots until exactly $remaining are free.
     */
    private function leaveExactly(int $remaining): void
    {
        $plans = new PlanService();
        $toTake = $this->freeSlots() - $remaining;

        for ($i = 0; $i < $toTake; $i++) {
            $plans->claimFoundingSlot($this->workspace('Filler ' . $i));
        }

        FoundingCounter::forget();

        self::assertSame($remaining, $this->freeSlots());
    }

    /**
     * Drop the per-request memo without touching the file, so the next call
     * behaves like a fresh request against a warm cache.
     */
    private function forgetMemoOnly(): void
    {
        $property = new \ReflectionProperty(FoundingCounter::class, 'memo');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    private function freeSlots(): int
    {
        return (int) Database::connection()
            ->query('SELECT COUNT(*) FROM founding_slots WHERE tenant_id IS NULL')
            ->fetchColumn();
    }

    private function workspace(string $name): int
    {
        $connection = Database::connection();
        $connection->prepare('INSERT INTO organizations (name, slug) VALUES (?, ?)')
            ->execute([$name, strtolower(str_replace(' ', '-', $name)) . '-' . bin2hex(random_bytes(4))]);

        return (int) $connection->lastInsertId();
    }
}
