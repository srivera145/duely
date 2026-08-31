<?php

declare(strict_types=1);

namespace Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Keel\App\Services\Clock;
use Keel\App\Services\WaitlistService;
use Keel\Core\Database;
use Tests\TestCase;

/**
 * The public site and the waitlist.
 *
 * Two things are load-bearing here and each has a section.
 *
 *   The opt-in is genuinely double. A row must be worth nothing until someone
 *   has clicked a link sent to the address they typed, or the first campaign
 *   sent from this list is the one that gets the domain blocked.
 *
 *   The form must not be an address checker. Every outcome a stranger could
 *   probe for — new, pending, already on the list — has to look identical from
 *   outside.
 */
class MarketingSiteFeatureTest extends TestCase
{
    private WaitlistService $waitlist;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->waitlist = new WaitlistService();
        // Relative to the real clock, not a fixed date.
        //
        // These tests mint a token at $this->now and then confirm it over HTTP,
        // where WaitlistService reads Clock::now(). A hardcoded date therefore
        // works only until the calendar passes it by more than CONFIRM_TTL_DAYS
        // -- and on 2026-08-31 the fixed 2026-08-24 crossed that line and the
        // suite started failing on its own, with no code change.
        $this->now = Clock::now()->modify('-1 hour');
    }

    // ------------------------------------------------------------ the pages

    public function testEveryPublicPageRendersWithoutASession(): void
    {
        foreach (['/', '/how-it-works', '/pricing', '/privacy', '/terms'] as $path) {
            $response = $this->get($path);

            self::assertSame(200, $response->status, $path . ' should be public');
            self::assertStringContainsString('<title>', $response->body, $path . ' needs a title');
        }
    }

    public function testTheHeroSaysTheThingItIsSupposedToSay(): void
    {
        $body = $this->get('/')->body;

        self::assertStringContainsString('Get paid without writing the awkward follow-up.', $body);

        // The subhead has to name who this is for, not gesture at it.
        self::assertStringContainsString('freelancers and small studios', $body);
        self::assertStringContainsString('spreadsheet', $body);

        // The differentiator, in the words it has to be in.
        self::assertStringContainsString('from your own inbox, not ours', $body);
    }

    public function testTheHeroVisualIsMarkupRatherThanAnImage(): void
    {
        $body = $this->get('/')->body;

        // Day 3 / 14 / 30 must be text in the document, not pixels in a file.
        foreach (['Day 3', 'Day 14', 'Day 30'] as $label) {
            self::assertStringContainsString($label, $body);
        }

        self::assertStringContainsString('INV&#8209;2041', $body, 'the sample invoice is rendered');
        self::assertStringContainsString('$2,400.00', $body);
        self::assertStringContainsString('you@lanternstudio.com', $body, 'the sample nudge shows the sender');

        // And it must be described to anyone who cannot see it.
        self::assertStringContainsString('<figcaption', $body);
    }

    public function testThePublicPagesLoadTheSmallBundleRatherThanTheApplication(): void
    {
        $marketing = $this->get('/')->body;

        // Keel serves from the Vite dev server whenever public_html/hot exists,
        // so the asset URLs differ between a built tree and a machine with
        // `npm run dev` running. The claim being tested is the same either way:
        // the public pages pull the marketing entry and never the application
        // bundle. Asserting the built filenames alone made this fail for the
        // sole reason that somebody had a dev server open.
        $isHot = file_exists(dirname(__DIR__, 2) . '/public_html/hot');

        if ($isHot) {
            self::assertStringContainsString('resources/js/marketing.js', $marketing);
            self::assertStringNotContainsString(
                'resources/js/app.js',
                $marketing,
                'the landing page must not ship the application bundle'
            );

            return;
        }

        self::assertMatchesRegularExpression('#/assets/assets/marketing-[^"]+\.js#', $marketing);
        self::assertDoesNotMatchRegularExpression(
            '#/assets/assets/app-[A-Za-z0-9_-]+\.js#',
            $marketing,
            'the landing page must not ship the application bundle'
        );

        // And the stylesheet has to arrive. Vite hoists shared modules into a
        // common chunk and moves the CSS with them, so an entry-only reading of
        // the manifest silently emits no stylesheet at all.
        self::assertMatchesRegularExpression(
            '#<link rel="stylesheet" href="/assets/assets/[^"]+\.css">#',
            $marketing,
            'the page must link a stylesheet however Vite chunked it'
        );
    }

    public function testButtonLabelsWithInlineMarkupKeepTheirSpaces(): void
    {
        // `.btn` is inline-flex, and a flex container discards whitespace-only
        // text between its items. So `Import <span>12</span> invoices` rendered
        // as "Import12invoices" -- the markup had the spaces and the layout
        // threw them away. The gap puts them back.
        //
        // Asserted against the compiled CSS rather than the source, because the
        // source is @apply and what ships is what matters.
        $stylesheets = glob(dirname(__DIR__, 2) . '/public_html/assets/assets/*.css') ?: [];

        if ($stylesheets === []) {
            self::markTestSkipped('No built stylesheet; run npm run build.');
        }

        $found = false;

        foreach ($stylesheets as $stylesheet) {
            if (preg_match('/\.btn\{[^}]*\}/', (string) file_get_contents($stylesheet), $matches) === 1) {
                $found = true;

                self::assertStringContainsString(
                    'gap:',
                    $matches[0],
                    '.btn is a flex container with no gap, so any label mixing text and markup loses its spaces.'
                );
            }
        }

        self::assertTrue($found, '.btn was not found in the compiled CSS.');
    }

    public function testTheStructuredDataDescribesWhatIsActuallyOnThePage(): void
    {
        $body = $this->get('/')->body;
        $nodes = $this->structuredData($body);

        self::assertCount(2, $nodes);

        $types = array_column($nodes, '@type');
        self::assertContains('SoftwareApplication', $types);
        self::assertContains('FAQPage', $types);

        $faq = $nodes[array_search('FAQPage', $types, true)];

        self::assertNotEmpty($faq['mainEntity']);

        foreach ($faq['mainEntity'] as $question) {
            self::assertSame('Question', $question['@type']);
            self::assertSame('Answer', $question['acceptedAnswer']['@type']);

            // Markup that answers questions the page does not ask is dropped,
            // and rightly. Both must come from the same array.
            self::assertStringContainsString(
                htmlspecialchars($question['name'], ENT_QUOTES, 'UTF-8'),
                $body,
                'the question must be visible on the page'
            );
            self::assertStringContainsString(
                htmlspecialchars($question['acceptedAnswer']['text'], ENT_QUOTES, 'UTF-8'),
                $body,
                'the answer must be visible on the page'
            );
        }
    }

    public function testTheHowToMarkupMatchesTheStepsOnThePage(): void
    {
        $body = $this->get('/how-it-works')->body;
        $nodes = $this->structuredData($body);

        self::assertCount(1, $nodes);
        self::assertSame('HowTo', $nodes[0]['@type']);
        self::assertCount(4, $nodes[0]['step']);

        foreach ($nodes[0]['step'] as $index => $step) {
            self::assertSame($index + 1, $step['position']);
            self::assertStringContainsString(
                htmlspecialchars($step['name'], ENT_QUOTES, 'UTF-8'),
                $body,
                'each named step must appear on the page'
            );
        }
    }

    public function testThePricesInTheMarkupAreThePricesOnThePage(): void
    {
        $body = $this->get('/pricing')->body;
        $nodes = $this->structuredData($body);

        $offers = [];
        foreach ($nodes[0]['offers'] as $offer) {
            $offers[$offer['name']] = $offer['price'];
        }

        self::assertSame(['Free' => '0', 'Solo' => '19', 'Studio' => '39'], $offers);
        self::assertStringContainsString('$19', $body);
        self::assertStringContainsString('$39', $body);
    }

    public function testThePrivacyPageSaysTheFourThingsItHasToSay(): void
    {
        $body = $this->get('/privacy')->body;

        // Someone is reading this with their hand on an app password. Each of
        // the four promises has to be findable, in words, not implied.
        $promises = [
            // Reads only for reply matching.
            'only to match replies to invoices',
            // Stores only snippets.
            'three hundred characters',
            'Message bodies are never written to our database',
            // Never marks read, never deletes, never moves.
            'It never marks anything read, deletes, or moves anything.',
            'read-only',
            // Credentials encrypted at rest.
            'AES-256-GCM',
            'never in the database',
        ];

        foreach ($promises as $promise) {
            self::assertStringContainsString(
                htmlspecialchars($promise, ENT_QUOTES, 'UTF-8'),
                $body,
                'the privacy page must say: ' . $promise
            );
        }

        // The negative list, which is the part people scan for.
        foreach (['Mark a message as read', 'Delete or archive anything', 'Store message bodies'] as $never) {
            self::assertStringContainsString($never, $body);
        }
    }

    public function testTheConfirmationPagesAreNotIndexed(): void
    {
        $confirm = $this->get('/waitlist/confirm?token=nonsense');

        self::assertSame(200, $confirm->status);
        self::assertStringContainsString('name="robots" content="noindex', $confirm->body);

        // A public page must not pick that up by accident.
        self::assertStringNotContainsString('name="robots" content="noindex', $this->get('/')->body);
    }

    // ------------------------------- self-check: double opt-in, end to end

    public function testJoiningSendsAConfirmationLinkThatActuallyConfirms(): void
    {
        $response = $this->postJson('/api/waitlist', [
            '_csrf' => $this->csrfToken(),
            'email' => 'Ada@Studio.test',
            'source' => 'landing_hero',
            'landing_path' => '/',
            'utm_source' => 'twitter',
            'utm_campaign' => 'launch',
        ]);

        self::assertSame(200, $response->status, (string) json_encode($response->json()));
        self::assertStringContainsString('Check your inbox', (string) $response->json()['message']);

        // Nothing is on the list yet. That is the whole point of double opt-in.
        $row = $this->row('ada@studio.test');

        self::assertSame(WaitlistService::STATUS_PENDING, $row['status']);
        self::assertNull($row['confirmed_at']);
        self::assertSame('landing_hero', $row['source']);
        self::assertSame('twitter', $row['utm_source']);
        self::assertSame('launch', $row['utm_campaign']);
        self::assertSame(1, (int) $row['confirm_send_count']);

        // The address was normalised on the way in.
        self::assertSame('ada@studio.test', $row['email']);

        $token = $this->tokenFromMail();

        self::assertNotSame('', $token, 'the confirmation email must carry a link');
        self::assertSame(
            hash('sha256', $token),
            (string) $row['confirm_token_hash'],
            'the raw token is never stored'
        );

        $confirm = $this->get('/waitlist/confirm?token=' . $token);

        self::assertSame(200, $confirm->status);
        self::assertStringContainsString('You are on the list', $confirm->body);

        $confirmed = $this->row('ada@studio.test');

        self::assertSame(WaitlistService::STATUS_CONFIRMED, $confirmed['status']);
        self::assertNotNull($confirmed['confirmed_at']);
        self::assertNull($confirmed['confirm_token_hash'], 'the token is spent');
        self::assertSame(1, $this->waitlist->confirmedCount());
    }

    public function testClickingTheLinkTwiceStillReadsAsSuccess(): void
    {
        $this->join('twice@studio.test');
        $token = $this->tokenFromMail();

        $this->get('/waitlist/confirm?token=' . $token);
        $second = $this->get('/waitlist/confirm?token=' . $token);

        // The token is gone, so the second click cannot match — but a person
        // clicking their own link twice should not be told they failed.
        self::assertSame(200, $second->status);
        self::assertSame(1, $this->waitlist->confirmedCount());
    }

    public function testAnExpiredLinkConfirmsNothing(): void
    {
        $result = $this->waitlist->join('slow@studio.test', [], $this->now->modify('-30 days'));

        self::assertTrue($result['ok']);

        $token = $this->tokenFromMail();
        $confirm = $this->waitlist->confirm($token, $this->now);

        self::assertFalse($confirm['ok']);
        self::assertSame('expired', $confirm['state']);
        self::assertSame(
            WaitlistService::STATUS_PENDING,
            $this->row('slow@studio.test')['status'],
            'an expired link leaves the row exactly as it was'
        );
    }

    public function testAnInventedTokenConfirmsNothing(): void
    {
        $this->join('real@studio.test');

        $confirm = $this->waitlist->confirm(str_repeat('a', 64), $this->now);

        self::assertFalse($confirm['ok']);
        self::assertSame(0, $this->waitlist->confirmedCount());
    }

    public function testTheFormCannotBeUsedToCheckWhetherAnAddressIsOnTheList(): void
    {
        // Three states a stranger might want to distinguish.
        $this->join('known@studio.test');
        $pending = $this->joinResponse('known@studio.test');

        $token = $this->tokenFromMail();
        $this->get('/waitlist/confirm?token=' . $token);
        $confirmed = $this->joinResponse('known@studio.test');

        $fresh = $this->joinResponse('stranger@studio.test');

        self::assertSame($fresh->status, $pending->status);
        self::assertSame($fresh->status, $confirmed->status);
        self::assertSame($fresh->json()['message'], $pending->json()['message']);
        self::assertSame($fresh->json()['message'], $confirmed->json()['message']);
    }

    public function testAConfirmedAddressIsNotEmailedAgainByASecondSignup(): void
    {
        $this->join('quiet@studio.test');
        $this->get('/waitlist/confirm?token=' . $this->tokenFromMail());

        $before = strlen($this->latestMailLog());
        $this->joinResponse('quiet@studio.test');

        self::assertSame(
            $before,
            strlen($this->latestMailLog()),
            'someone already on the list does not get another confirmation email'
        );
    }

    public function testAttributionIsNotOverwrittenByALaterSignup(): void
    {
        $this->waitlist->join('attributed@studio.test', [
            'source' => 'landing_hero',
            'utm_source' => 'twitter',
            'utm_campaign' => 'launch',
            'ip' => '203.0.113.10',
        ], $this->now);

        $this->waitlist->join('attributed@studio.test', [
            'source' => 'pricing',
            'utm_source' => 'newsletter',
            'utm_campaign' => 'second',
            'ip' => '203.0.113.11',
        ], $this->now->modify('+1 hour'));

        $row = $this->row('attributed@studio.test');

        self::assertSame('landing_hero', $row['source'], 'the campaign that earned the signup keeps it');
        self::assertSame('twitter', $row['utm_source']);
        self::assertSame('launch', $row['utm_campaign']);
        self::assertSame(2, (int) $row['confirm_send_count']);
    }

    public function testTheIpAddressIsHashedRatherThanStored(): void
    {
        $this->waitlist->join('hashed@studio.test', ['ip' => '203.0.113.42'], $this->now);

        $row = $this->row('hashed@studio.test');

        self::assertNotNull($row['ip_hash']);
        self::assertSame(64, strlen((string) $row['ip_hash']));
        self::assertStringNotContainsString('203.0.113.42', (string) json_encode($row));
    }

    public function testAnAddressThatIsNotAnAddressIsRefused(): void
    {
        $response = $this->postJson('/api/waitlist', [
            '_csrf' => $this->csrfToken(),
            'email' => 'not-an-address',
        ]);

        self::assertSame(422, $response->status);
        self::assertSame(0, $this->rowsFor('not-an-address'));
    }

    public function testTheHoneypotIsAnsweredAsThoughItWorkedAndStoresNothing(): void
    {
        $response = $this->postJson('/api/waitlist', [
            '_csrf' => $this->csrfToken(),
            'email' => 'bot@studio.test',
            'company' => 'Acme Spam Co',
        ]);

        // Telling a bot it was caught only teaches whoever wrote it.
        self::assertSame(200, $response->status);
        self::assertSame(0, $this->rowsFor('bot@studio.test'));
    }

    public function testTheFormWorksWithoutJavaScript(): void
    {
        $response = $this->post('/api/waitlist', [
            '_csrf' => $this->csrfToken(),
            'email' => 'nojs@studio.test',
            'landing_path' => '/pricing',
        ]);

        self::assertSame(302, $response->status);
        self::assertSame('/pricing?waitlist=sent#waitlist', (string) $response->header('Location'));
        self::assertSame(WaitlistService::STATUS_PENDING, $this->row('nojs@studio.test')['status']);
    }

    public function testTheReturnPathCannotBePointedAtAnotherSite(): void
    {
        $response = $this->post('/api/waitlist', [
            '_csrf' => $this->csrfToken(),
            'email' => 'redirect@studio.test',
            'landing_path' => 'https://evil.test/phish',
        ]);

        self::assertSame(302, $response->status);
        self::assertSame('/?waitlist=sent#waitlist', (string) $response->header('Location'));
    }

    public function testThePageSentenceIsNotTakenFromTheUrl(): void
    {
        // A crafted link must not be able to make this site say anything.
        $body = $this->get('/?waitlist=sent&waitlist_message=Your+account+is+suspended')->body;

        self::assertStringNotContainsString('Your account is suspended', $body);
        self::assertStringContainsString('Check your inbox', $body);
    }

    public function testTheJoinEndpointRequiresCsrf(): void
    {
        $response = $this->postJson('/api/waitlist', ['email' => 'nocsrf@studio.test']);

        self::assertSame(419, $response->status);
        self::assertSame(0, $this->rowsFor('nocsrf@studio.test'));
    }

    // ---------------------------------------------------------- unsubscribe

    public function testUnsubscribingWorksAndSurvivesTheTokenBeingSpent(): void
    {
        $this->join('leaving@studio.test');
        $this->get('/waitlist/confirm?token=' . $this->tokenFromMail());

        $token = $this->waitlist->unsubscribeToken('leaving@studio.test');
        $response = $this->get('/waitlist/unsubscribe?email=leaving@studio.test&token=' . $token);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('off the list', $response->body);
        self::assertSame(
            WaitlistService::STATUS_UNSUBSCRIBED,
            $this->row('leaving@studio.test')['status']
        );
        self::assertSame(0, $this->waitlist->confirmedCount());
    }

    public function testAnUnsubscribeLinkCannotBeGuessedForSomebodyElse(): void
    {
        $this->join('victim@studio.test');

        $wrongToken = $this->waitlist->unsubscribeToken('someone.else@studio.test');
        $result = $this->waitlist->unsubscribe('victim@studio.test', $wrongToken, $this->now);

        self::assertFalse($result['ok']);
        self::assertSame(
            WaitlistService::STATUS_PENDING,
            $this->row('victim@studio.test')['status']
        );
    }

    public function testTheConfirmationEmailCarriesAnUnsubscribeLink(): void
    {
        $this->join('optout@studio.test');

        self::assertStringContainsString('/waitlist/unsubscribe', $this->latestMailLog());
    }

    // -------------------------------------------------------------- internals

    private function join(string $email): void
    {
        $result = $this->waitlist->join($email, ['source' => 'landing_hero'], $this->now);

        self::assertTrue($result['ok'], (string) $result['message']);
    }

    private function joinResponse(string $email): \Tests\Support\TestResponse
    {
        return $this->postJson('/api/waitlist', [
            '_csrf' => $this->csrfToken(),
            'email' => $email,
        ]);
    }

    /**
     * The raw token out of the most recent confirmation email, which is the
     * only place it ever exists in plaintext.
     */
    private function tokenFromMail(): string
    {
        $log = $this->latestMailLog();

        if (preg_match_all('/waitlist\/confirm\?token=([a-f0-9]{64})/', $log, $matches) !== 0) {
            return (string) end($matches[1]);
        }

        return '';
    }

    private function row(string $email): array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM waitlist_signups WHERE email = ? LIMIT 1'
        );
        $statement->execute([strtolower($email)]);

        $row = $statement->fetch();

        self::assertIsArray($row, 'expected a waitlist row for ' . $email);

        return $row;
    }

    private function rowsFor(string $email): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM waitlist_signups WHERE email = ?'
        );
        $statement->execute([strtolower($email)]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function structuredData(string $html): array
    {
        preg_match_all(
            '#<script type="application/ld\+json">(.*?)</script>#s',
            $html,
            $matches
        );

        return array_map(
            static function (string $json): array {
                $decoded = json_decode($json, true);

                self::assertIsArray($decoded, 'JSON-LD must parse: ' . substr($json, 0, 120));

                return $decoded;
            },
            $matches[1]
        );
    }
}
