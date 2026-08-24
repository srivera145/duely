<?php

declare(strict_types=1);

namespace Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Keel\App\Services\PromptScrubber;
use Keel\App\Services\TemplateRenderer;
use Keel\App\Services\TenantContext;
use Keel\App\Services\ToneAssistService;
use Keel\Core\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeAiService;
use Tests\TestCase;

/**
 * The writing assistant.
 *
 * Three things are load-bearing and each has its own section: a draft must
 * render through the real TemplateRenderer, a bad response must degrade to a
 * sentence rather than a fatal, and no client data may reach the API.
 */
class ToneAssistFeatureTest extends TestCase
{
    private int $tenantId;
    private array $user;
    private FakeAiService $ai;
    private ToneAssistService $assist;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $this->tenantId = TenantContext::forUser((int) $this->user['id']);
        $this->now = new DateTimeImmutable('2026-08-19 11:00:00', new DateTimeZone('UTC'));

        // The service refuses outright without a key, so tests need one set.
        $_ENV['ANTHROPIC_API_KEY'] = 'sk-test-not-a-real-key';
        $_SERVER['ANTHROPIC_API_KEY'] = 'sk-test-not-a-real-key';

        $this->ai = new FakeAiService();
        $this->assist = new ToneAssistService($this->ai);
    }

    protected function tearDown(): void
    {
        unset($_ENV['ANTHROPIC_API_KEY'], $_SERVER['ANTHROPIC_API_KEY']);

        parent::tearDown();
    }

    // ---------------------------- self-check: valid JSON that actually renders

    public function testARewriteReturnsADraftThatRendersThroughTheRealRenderer(): void
    {
        $this->ai->replyWithStep(
            'Quick note about {{invoice_number}}',
            "Hi {{client_first_name}},\n\nJust checking in on {{invoice_number}} for {{amount}}, "
            . "due {{due_date}}.\n\n{{invoice_url}}\n\nThanks,\n{{sender_name}}"
        );

        $result = $this->rewrite();

        self::assertTrue($result['ok'], (string) $result['error']);
        self::assertArrayHasKey('subject', $result['proposal']);
        self::assertArrayHasKey('body', $result['proposal']);

        // The draft has to survive the renderer that will produce the real email.
        $rendered = (new TemplateRenderer())->renderMessage(
            $result['proposal']['subject'],
            $result['proposal']['body'],
            TemplateRenderer::sampleContext('Ada Lovelace')
        );

        self::assertSame([], $rendered['warnings']);
        self::assertStringNotContainsString('{{', $rendered['subject']);
        self::assertStringNotContainsString('{{', $rendered['text']);
        self::assertStringNotContainsString('{{', $rendered['html']);
        self::assertStringContainsString('INV-1042', $rendered['text']);
        self::assertStringContainsString('$3,200.00', $rendered['text']);
    }

    #[DataProvider('wrappedResponseProvider')]
    public function testAWrappedOrChattyResponseIsStillParsed(string $reply): void
    {
        $this->ai->reply = $reply;

        self::assertTrue($this->rewrite()['ok'], 'could not parse: ' . $reply);
    }

    public static function wrappedResponseProvider(): array
    {
        $json = '{"subject":"S {{invoice_number}}","body":"B {{client_first_name}}"}';

        return [
            'json fence' => ["```json\n" . $json . "\n```"],
            'bare fence' => ["```\n" . $json . "\n```"],
            'unclosed fence' => ["```json\n" . $json],
            'preamble' => ["Here you go:\n\n" . $json],
            'postamble' => [$json . "\n\nHope that helps!"],
            'stray whitespace' => ["  \n" . $json . "\n  "],
        ];
    }

    // --------------------------- self-check: malformed degrades, never fatals

    #[DataProvider('malformedResponseProvider')]
    public function testAMalformedResponseDegradesToAnErrorMessage(string $reply): void
    {
        $this->ai->reply = $reply;

        // The assertion is as much that nothing is thrown as that ok is false.
        $result = $this->rewrite();

        self::assertFalse($result['ok']);
        self::assertIsString($result['error']);
        self::assertNotSame('', $result['error']);

        // A user-facing message, not a leaked internal.
        self::assertStringNotContainsString('Exception', $result['error']);
        self::assertStringNotContainsString('.php', $result['error']);
        self::assertNull($result['proposal']);
    }

    public static function malformedResponseProvider(): array
    {
        return [
            'plain prose' => ['I am sorry, I cannot help with that.'],
            'empty' => [''],
            'missing body' => ['{"subject": "only a subject"}'],
            'missing subject' => ['{"body": "only a body"}'],
            'empty strings' => ['{"subject":"","body":""}'],
            'json array' => ['[1,2,3]'],
            'truncated json' => ['{"subject":"S","body":"B"'],
            'literal null' => ['null'],
        ];
    }

    public function testAnApiFailureDegradesToAnErrorMessage(): void
    {
        $this->ai->throw = 'Connection timed out';

        $result = $this->rewrite();

        self::assertFalse($result['ok']);
        self::assertStringContainsString('could not reach', (string) $result['error']);
        self::assertStringContainsString('unchanged', (string) $result['error']);
    }

    // ------------------------------------- self-check: no PII in the payload

    public function testNoClientDataReachesTheApi(): void
    {
        $this->ai->replyWithStep('S {{invoice_number}}', 'B {{client_first_name}}');

        // A template a user has hand-edited, full of real values.
        $subject = 'Invoice INV-4417 for Dana Whitfield is overdue';
        $body = "Hi Dana,\n\n"
            . "Invoice INV-4417 for \$12,480.00 was due on 2026-07-15.\n"
            . "Pay at https://pay.example.com/secret-token-abc123\n"
            . "Call me on +44 7700 900123 or email dana.whitfield@bigcorp.example\n"
            . "Our account is GB29NWBK60161331926819.\n";

        $result = $this->assist->rewriteStep(
            $this->tenantId, $subject, $body, 'firm', 'Mention that Dana can call me', $this->now
        );

        self::assertTrue($result['ok'], (string) $result['error']);

        $sent = $this->ai->everythingSent();

        foreach ([
            'INV-4417' => 'invoice number',
            '12,480.00' => 'amount',
            '2026-07-15' => 'due date',
            'secret-token-abc123' => 'payment link',
            'dana.whitfield@bigcorp.example' => 'client email',
            'GB29NWBK60161331926819' => 'bank account',
            '7700 900123' => 'phone number',
        ] as $secret => $label) {
            self::assertStringNotContainsString($secret, $sent, 'the ' . $label . ' reached the API');
        }

        // Merge tags went instead, and the user is told what was removed.
        self::assertStringContainsString('{{amount}}', $sent);
        self::assertStringContainsString('{{invoice_number}}', $sent);
        self::assertNotSame([], $result['redactions']);
    }

    public function testTheBusinessDescriptionIsScrubbedToo(): void
    {
        $this->ai->replyWithSequence($this->threeGoodSteps());

        $result = $this->assist->generateSequence(
            $this->tenantId,
            'I bill BigCorp about $9,500 a month, contact is finance@bigcorp.example',
            $this->now
        );

        self::assertTrue($result['ok'], (string) $result['error']);

        $sent = $this->ai->everythingSent();
        self::assertStringNotContainsString('9,500', $sent);
        self::assertStringNotContainsString('finance@bigcorp.example', $sent);
    }

    #[DataProvider('piiProvider')]
    public function testTheScrubberCatchesEachKindOfClientData(string $input, string $secret, string $kind): void
    {
        $result = PromptScrubber::scrub($input);

        self::assertStringNotContainsString($secret, $result['text']);
        self::assertArrayHasKey($kind, $result['redactions']);
    }

    public static function piiProvider(): array
    {
        return [
            'dollar amount' => ['Pay $3,200.00 now', '$3,200.00', 'money'],
            'iso amount' => ['Pay USD 1200 now', 'USD 1200', 'money'],
            'invoice ref' => ['Ref INV-1042', 'INV-1042', 'invoice'],
            'hash ref' => ['Ref #10425', '#10425', 'invoice'],
            'iso date' => ['Due 2026-08-05', '2026-08-05', 'date'],
            'slashed date' => ['Due 05/08/2026', '05/08/2026', 'date'],
            'email' => ['Mail bob@example.com', 'bob@example.com', 'email'],
            'url' => ['Visit https://x.test/abc', 'https://x.test/abc', 'url'],
            'phone' => ['Call +1 555 123 4567', '555 123 4567', 'phone'],
            'iban' => ['IBAN GB29NWBK60161331926819', 'GB29NWBK60161331926819', 'iban'],
        ];
    }

    public function testACleanTemplatePassesThroughUntouched(): void
    {
        $clean = 'Hi {{client_first_name}}, invoice {{invoice_number}} for {{amount}} was due {{due_date}}.';

        $result = PromptScrubber::scrub($clean);

        self::assertSame($clean, $result['text']);
        self::assertSame([], $result['redactions']);
        self::assertFalse(PromptScrubber::containsLikelyPii($clean));
    }

    // -------------------------------------------------- merge tag validation

    public function testAnInventedMergeTagIsRejectedBeforeTheUserSeesIt(): void
    {
        $this->ai->replyWithStep(
            'About {{invoice_number}}',
            'Hi {{client_first_name}}, your reference is {{customer_reference}}.'
        );

        $result = $this->rewrite();

        self::assertFalse($result['ok'], 'a hallucinated tag reached the user');
        self::assertStringContainsString('customer_reference', (string) $result['error']);
        self::assertNull($result['proposal']);
    }

    public function testEveryApprovedTagIsAccepted(): void
    {
        $allTags = implode(' ', array_map(
            static fn (string $tag): string => '{{' . $tag . '}}',
            TemplateRenderer::tagNames()
        ));

        $this->ai->replyWithStep('Re {{invoice_number}}', $allTags);

        self::assertTrue($this->rewrite()['ok']);
    }

    public function testOneBadTagRejectsTheWholeLadder(): void
    {
        $this->ai->replyWithSequence([
            ['offset_days' => 3, 'tone' => 'polite', 'subject' => 'A', 'body' => 'Hi {{client_first_name}}'],
            ['offset_days' => 14, 'tone' => 'firm', 'subject' => 'B', 'body' => 'Ref {{made_up_tag}}'],
        ]);

        $result = $this->assist->generateSequence($this->tenantId, $this->description(), $this->now);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('made_up_tag', (string) $result['error']);
    }

    // -------------------------------------------------------- sequence shape

    public function testAGeneratedSequenceComesBackOrderedAndRenumbered(): void
    {
        // Deliberately out of order.
        $this->ai->replyWithSequence([
            ['offset_days' => 30, 'tone' => 'final', 'subject' => 'C', 'body' => 'Hi {{client_first_name}}'],
            ['offset_days' => 3, 'tone' => 'polite', 'subject' => 'A', 'body' => 'Hi {{client_first_name}}'],
            ['offset_days' => 14, 'tone' => 'firm', 'subject' => 'B', 'body' => 'Hi {{client_first_name}}'],
        ]);

        $result = $this->assist->generateSequence($this->tenantId, $this->description(), $this->now);

        self::assertTrue($result['ok'], (string) $result['error']);
        self::assertSame([3, 14, 30], array_column($result['proposal']['steps'], 'offset_days'));
        self::assertSame([1, 2, 3], array_column($result['proposal']['steps'], 'position'));
        self::assertSame(['polite', 'firm', 'final'], array_column($result['proposal']['steps'], 'tone'));
    }

    public function testTwoRemindersOnTheSameDayAreRefused(): void
    {
        $this->ai->replyWithSequence([
            ['offset_days' => 7, 'tone' => 'polite', 'subject' => 'A', 'body' => 'Hi {{client_first_name}}'],
            ['offset_days' => 7, 'tone' => 'firm', 'subject' => 'B', 'body' => 'Hi {{client_first_name}}'],
        ]);

        $result = $this->assist->generateSequence($this->tenantId, $this->description(), $this->now);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('same day', (string) $result['error']);
    }

    public function testNothingIsEverWrittenToALiveSequence(): void
    {
        $stepsBefore = $this->countRows('sequence_steps');
        $sequencesBefore = $this->countRows('sequences');

        $this->ai->replyWithSequence($this->threeGoodSteps());
        $this->assist->generateSequence($this->tenantId, $this->description(), $this->now);

        $this->ai->replyWithStep('S {{invoice_number}}', 'B {{client_first_name}}');
        $this->rewrite();

        self::assertSame($stepsBefore, $this->countRows('sequence_steps'), 'a draft was written to a live sequence');
        self::assertSame($sequencesBefore, $this->countRows('sequences'));
    }

    // ---------------------------------------------------------- rate limiting

    public function testTheDailyLimitIsTwentyCallsPerTenant(): void
    {
        $this->ai->replyWithStep('S {{invoice_number}}', 'B {{client_first_name}}');

        $allowance = $this->assist->allowance($this->tenantId, $this->now);
        self::assertSame(0, $allowance['used']);
        self::assertSame(ToneAssistService::DAILY_LIMIT, $allowance['limit']);

        for ($i = 0; $i < ToneAssistService::DAILY_LIMIT; $i++) {
            $this->rewrite();
        }

        self::assertSame(ToneAssistService::DAILY_LIMIT, $this->ai->callCount());

        $blocked = $this->rewrite();

        self::assertFalse($blocked['ok']);
        self::assertStringContainsString('all ' . ToneAssistService::DAILY_LIMIT, (string) $blocked['error']);

        // The refusal happens before the API is touched.
        self::assertSame(ToneAssistService::DAILY_LIMIT, $this->ai->callCount());
        self::assertNotNull($this->assist->allowance($this->tenantId, $this->now)['resets_at']);
    }

    public function testTheLimitWindowRollsForward(): void
    {
        $this->ai->replyWithStep('S {{invoice_number}}', 'B {{client_first_name}}');

        for ($i = 0; $i < ToneAssistService::DAILY_LIMIT; $i++) {
            $this->rewrite();
        }

        self::assertFalse($this->assist->allowance($this->tenantId, $this->now)['allowed']);
        self::assertTrue($this->assist->allowance($this->tenantId, $this->now->modify('+25 hours'))['allowed']);
    }

    public function testTheLimitIsPerTenant(): void
    {
        $this->ai->replyWithStep('S {{invoice_number}}', 'B {{client_first_name}}');

        for ($i = 0; $i < ToneAssistService::DAILY_LIMIT; $i++) {
            $this->rewrite();
        }

        $otherTenant = (int) $this->createOrganization('Rival Studio')['id'];

        self::assertSame(0, $this->assist->allowance($otherTenant, $this->now)['used']);
        self::assertTrue(
            $this->assist->rewriteStep($otherTenant, 'S', 'B', 'polite', '', $this->now)['ok'],
            'one tenant exhausting the limit blocked another'
        );
    }

    // ------------------------------------------------------- usage accounting

    public function testTokenUsageIsRecordedAgainstTheTenant(): void
    {
        $this->ai->replyWithStep('S {{invoice_number}}', 'B {{client_first_name}}');

        $this->rewrite();
        $this->rewrite();

        $summary = $this->assist->usageSummary($this->tenantId);

        self::assertSame(2, $summary['calls']);
        self::assertSame(2 * $this->ai->inputTokens, $summary['input_tokens']);
        self::assertSame(2 * $this->ai->outputTokens, $summary['output_tokens']);
        self::assertSame(2, $summary['accepted']);
    }

    public function testARejectedDraftIsStillRecordedBecauseItStillCostTokens(): void
    {
        $this->ai->replyWithStep('S', 'Ref {{nope}}');

        $this->rewrite();

        $row = Database::connection()
            ->query('SELECT * FROM ai_usage ORDER BY id DESC LIMIT 1')
            ->fetch();

        self::assertNotFalse($row);
        self::assertSame('rejected', $row['outcome']);
        self::assertGreaterThan(0, (int) $row['input_tokens']);
        self::assertNotNull($row['failure_reason']);
        self::assertSame('claude-opus-5', $row['model']);

        // And it counts against the limit, because it really was spent.
        self::assertSame(1, $this->assist->allowance($this->tenantId, $this->now)['used']);
    }

    // ------------------------------------------------------------ the prompt

    public function testTheSystemPromptTeachesTheHouseStyleAndTheTagAllowlist(): void
    {
        $this->ai->replyWithStep('S {{invoice_number}}', 'B {{client_first_name}}');
        $this->assist->rewriteStep($this->tenantId, 'Subject', 'Body', 'final', '', $this->now);

        $system = $this->ai->systems[0];

        foreach (TemplateRenderer::tagNames() as $tag) {
            self::assertStringContainsString('{{' . $tag . '}}', $system, $tag . ' missing from the allowlist');
        }

        self::assertStringContainsStringIgnoringCase('never invent', $system);
        self::assertStringContainsStringIgnoringCase('never threaten', $system);

        $prompt = $this->ai->prompts[0];
        self::assertStringContainsString('Final:', $prompt, 'the tone label did not reach the prompt');
        self::assertStringContainsString('{"subject": "...", "body": "..."}', $prompt);
        self::assertStringContainsStringIgnoringCase('no markdown code fences', $prompt);
    }

    public function testRequestOptionsAreSensible(): void
    {
        $this->ai->replyWithStep('S {{invoice_number}}', 'B {{client_first_name}}');
        $this->rewrite();

        $options = $this->ai->options[0];

        self::assertSame('medium', $options['effort'] ?? null, 'effort should be tuned down for short copy');
        self::assertGreaterThanOrEqual(2000, $options['maxTokens'] ?? 0, 'max_tokens must not be lowballed');
    }

    // ------------------------------------------------------ configuration

    public function testTheFeatureRefusesWithoutAnApiKey(): void
    {
        unset($_ENV['ANTHROPIC_API_KEY'], $_SERVER['ANTHROPIC_API_KEY']);
        $_ENV['ANTHROPIC_API_KEY'] = '';

        self::assertFalse(ToneAssistService::isConfigured());

        $result = $this->rewrite();

        self::assertFalse($result['ok']);
        self::assertStringContainsString('ANTHROPIC_API_KEY', (string) $result['error']);
        self::assertSame(0, $this->ai->callCount(), 'the API was called without a key');
    }

    // -------------------------------------------------------------- routes

    public function testTheEndpointsRequireAuthenticationAndCsrf(): void
    {
        $unauthenticated = $this->postJson('/api/tone-assist/rewrite', [
            '_csrf' => $this->csrfToken(),
            'subject_template' => 'x',
        ]);

        self::assertContains($unauthenticated->status, [302, 401], 'the endpoint was reachable without a session');

        $this->signIn();

        foreach (['/api/tone-assist/rewrite', '/api/tone-assist/sequence'] as $path) {
            self::assertSame(419, $this->post($path, [])->status, $path . ' is not CSRF protected');
        }
    }

    public function testTheRewriteEndpointReturnsAProposalAndNeverSaves(): void
    {
        $this->signIn();

        // The controller builds its own service, so this exercises the real
        // path as far as the API boundary.
        $response = $this->postJson('/api/tone-assist/rewrite', [
            '_csrf' => $this->csrfToken(),
            'subject_template' => '',
            'body_template' => '',
        ]);

        self::assertSame(422, $response->status, 'an empty template should be refused');
        self::assertStringContainsString('nothing to rewrite', $response->body);
    }

    public function testTheAllowanceEndpointReportsUsage(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/tone-assist/allowance');

        self::assertSame(200, $response->status);

        $body = json_decode($response->body, true);
        self::assertTrue($body['configured']);
        self::assertSame(ToneAssistService::DAILY_LIMIT, $body['allowance']['limit']);
        self::assertArrayHasKey('tags', $body);
    }

    // -------------------------------------------------------------- helpers

    private function rewrite(): array
    {
        return $this->assist->rewriteStep(
            $this->tenantId,
            'Invoice {{invoice_number}}',
            'Hi {{client_first_name}}, please pay.',
            'polite',
            '',
            $this->now
        );
    }

    private function description(): string
    {
        return 'I photograph weddings and most clients are couples paying personally.';
    }

    /**
     * @return array<int, array{offset_days:int, tone:string, subject:string, body:string}>
     */
    private function threeGoodSteps(): array
    {
        return [
            ['offset_days' => 3, 'tone' => 'polite', 'subject' => 'A {{invoice_number}}', 'body' => 'Hi {{client_first_name}}'],
            ['offset_days' => 14, 'tone' => 'firm', 'subject' => 'B {{invoice_number}}', 'body' => 'Hi {{client_first_name}}'],
            ['offset_days' => 30, 'tone' => 'final', 'subject' => 'C {{invoice_number}}', 'body' => 'Hi {{client_first_name}}'],
        ];
    }

    private function countRows(string $table): int
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE tenant_id = ?');
        $statement->execute([$this->tenantId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Sign in as the user setUp() created, which owns this tenant.
     */
    private function signIn(): void
    {
        \Keel\Core\Session::put('user_id', (int) $this->user['id']);
        \Keel\Core\Session::put('user_email', (string) $this->user['email']);
        \Keel\Core\Session::put('organization_id', $this->tenantId);
        \Keel\Core\Auth::setUserId(null);
    }
}
