<?php

declare(strict_types=1);

namespace Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Keel\App\Models\Chase;
use Keel\App\Models\Client;
use Keel\App\Models\EmailAccount;
use Keel\App\Models\Invoice;
use Keel\App\Models\ReplyEvent;
use Keel\App\Models\Sequence;
use Keel\App\Services\ChaseScheduler;
use Keel\App\Services\ChaseSender;
use Keel\App\Services\Clock;
use Keel\App\Services\DashboardMetrics;
use Keel\App\Services\ImapPoller;
use Keel\App\Services\InboundMessage;
use Keel\App\Services\ReplyMatcher;
use Keel\App\Services\TenantContext;
use Keel\Core\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeImapServer;
use Tests\Support\RecordingTransport;
use Tests\TestCase;

/**
 * Reply and bounce detection.
 *
 * The failure this guards against is specific: a client answers "cheque went
 * out Friday" and Duely sends a Final Notice anyway. Everything here is in
 * service of that never happening — and of its mirror image, an out-of-office
 * silently killing a chase for a fortnight.
 */
class ReplyDetectionFeatureTest extends TestCase
{
    private int $tenantId;
    private int $sequenceId;
    /** Created lazily by ensureAccount(), because the IMAP port is not known until a server starts. */
    private ?int $accountId = null;
    private DateTimeImmutable $now;
    private ?FakeImapServer $imap = null;

    protected function setUp(): void
    {
        parent::setUp();

        $user = $this->createUser(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $this->tenantId = TenantContext::forUser((int) $user['id']);
        $this->sequenceId = (int) Sequence::defaultSequence($this->tenantId)['id'];
        $this->now = new DateTimeImmutable('2026-08-19 11:00:00', new DateTimeZone('UTC'));
    }

    protected function tearDown(): void
    {
        $this->imap?->stop();
        $this->imap = null;

        parent::tearDown();
    }

    // ------------------------------------------- self-check: a reply pauses

    public function testAGenuineReplyPausesTheChaseWithinOnePoll(): void
    {
        $chase = $this->startChase('INV-REPLY', 'dana@whitfield.test');

        $this->poll([$this->replyMessage(101, $chase['root_message_id'], 'dana@whitfield.test')]);

        $paused = Chase::find($this->tenantId, $chase['chase_id']);
        self::assertSame(Chase::STATUS_PAUSED, $paused['status']);
        self::assertSame(Chase::PAUSE_CLIENT_REPLIED, $paused['paused_reason']);
        self::assertNull($paused['next_send_at'], 'a paused chase must have no pending send');
    }

    public function testAPausedChaseSendsNothingEvenWhenTheNextStepFallsDue(): void
    {
        $chase = $this->startChase('INV-REPLY', 'dana@whitfield.test');
        $this->poll([$this->replyMessage(101, $chase['root_message_id'], 'dana@whitfield.test')]);

        // Well past the day-30 step.
        $transport = new RecordingTransport();
        (new ChaseSender($transport))->processDueForTenant($this->tenantId, $this->now->modify('+40 days'));

        self::assertSame([], $transport->to('dana@whitfield.test'), 'a Final Notice went out after a reply');
    }

    public function testTheReplyEventRecordsWhatTheClientSaidWithoutStoringTheMessage(): void
    {
        $chase = $this->startChase('INV-REPLY', 'dana@whitfield.test');
        $this->poll([$this->replyMessage(101, $chase['root_message_id'], 'dana@whitfield.test')]);

        $events = ReplyEvent::forChase($this->tenantId, $chase['chase_id']);
        self::assertCount(1, $events);

        $event = $events[0];
        self::assertSame(ReplyEvent::TYPE_REPLY, $event['type']);
        self::assertSame(ReplyMatcher::MATCH_MESSAGE_ID, $event['matched_by']);
        self::assertNotNull($event['chase_message_id'], 'the exact message answered should be linked');

        self::assertStringContainsString('cheque went out Friday', (string) $event['snippet']);
        self::assertLessThanOrEqual(300, mb_strlen((string) $event['snippet']));

        // The quoted reminder is history, not what the client said.
        self::assertStringNotContainsString('18 days overdue', (string) $event['snippet']);
        self::assertStringNotContainsString('On Wed, Ada wrote', (string) $event['snippet']);

        self::assertNotNull($event['processed_at']);
        self::assertSame('paused_chase', $event['action_taken']);
    }

    public function testTheUserIsEmailedWhenAClientReplies(): void
    {
        $chase = $this->startChase('INV-REPLY', 'dana@whitfield.test');
        $this->poll([$this->replyMessage(101, $chase['root_message_id'], 'dana@whitfield.test')]);

        $log = $this->mailLog();
        self::assertStringContainsString('replied about', $log);
        self::assertStringContainsString('cheque went out Friday', $log, 'the notice should quote the client');
    }

    // ------------------------------------- self-check: an out-of-office does not

    public function testAnOutOfOfficeDoesNotPauseTheChase(): void
    {
        $chase = $this->startChase('INV-OOO', 'sam@chen.test');

        $this->poll([[
            'uid' => 102,
            'headers' => "From: Sam Chen <sam@chen.test>\r\n"
                . "To: ada@studio.test\r\n"
                . "Subject: Automatic reply: Invoice INV-OOO is 18 days overdue\r\n"
                . "Message-ID: <ooo-1@chen.test>\r\n"
                . 'In-Reply-To: ' . $chase['root_message_id'] . "\r\n"
                . "Auto-Submitted: auto-replied\r\n"
                . "Content-Type: text/plain\r\n",
            'body' => "I am out of the office until 2 September.\r\n",
        ]]);

        $after = Chase::find($this->tenantId, $chase['chase_id']);
        self::assertContains($after['status'], [Chase::STATUS_SCHEDULED, Chase::STATUS_ACTIVE]);
        self::assertNull($after['paused_reason']);
        self::assertNotNull($after['next_send_at'], 'the ladder should keep climbing through a holiday');

        // Still recorded, so the user can see it happened.
        $events = ReplyEvent::forChase($this->tenantId, $chase['chase_id']);
        self::assertCount(1, $events);
        self::assertSame(ReplyEvent::TYPE_AUTO_REPLY, $events[0]['type']);
        self::assertSame('auto_reply_ignored', $events[0]['action_taken']);
        self::assertFalse(ReplyEvent::hasHumanReply($this->tenantId, $chase['chase_id']));
    }

    // ------------------------------------------ self-check: a bounce stops

    public function testAHardBounceStopsTheChaseAndFlagsTheAddress(): void
    {
        $chase = $this->startChase('INV-BOUNCE', 'gone@deadco.test');

        $this->poll([[
            'uid' => 103,
            'headers' => "Return-Path: <>\r\n"
                . "From: Mail Delivery Subsystem <mailer-daemon@studio.test>\r\n"
                . "Subject: Undeliverable: Invoice INV-BOUNCE\r\n"
                . "Message-ID: <bounce-1@studio.test>\r\n"
                . "X-Failed-Recipients: gone@deadco.test\r\n"
                . "Content-Type: multipart/report; report-type=delivery-status; boundary=\"b1\"\r\n",
            'body' => "--b1\r\nContent-Type: message/delivery-status\r\n\r\n"
                . "Final-Recipient: rfc822; gone@deadco.test\r\nAction: failed\r\nStatus: 5.1.1\r\n"
                . "Diagnostic-Code: smtp; 550 5.1.1 User unknown\r\n--b1--\r\n",
        ]]);

        self::assertSame(Chase::STATUS_STOPPED, Chase::find($this->tenantId, $chase['chase_id'])['status']);

        $client = Client::find($this->tenantId, $chase['client_id']);
        self::assertNotNull($client['email_invalid_at'], 'the address should be flagged invalid');
        self::assertStringContainsString('bounced', (string) $client['email_invalid_reason']);

        $events = ReplyEvent::forChase($this->tenantId, $chase['chase_id']);
        self::assertSame(ReplyEvent::TYPE_BOUNCE, $events[0]['type']);
        self::assertSame(1, (int) $events[0]['is_hard_bounce']);
    }

    public function testASoftBounceDoesNotAbandonTheClient(): void
    {
        $chase = $this->startChase('INV-SOFT', 'full@mailbox.test');

        $this->poll([[
            'uid' => 104,
            'headers' => "Return-Path: <>\r\n"
                . "From: postmaster@mailbox.test\r\n"
                . "Subject: Delivery delayed: Invoice INV-SOFT\r\n"
                . "Message-ID: <soft-1@mailbox.test>\r\n"
                . "X-Failed-Recipients: full@mailbox.test\r\n"
                . "Content-Type: multipart/report; report-type=delivery-status; boundary=\"b2\"\r\n",
            'body' => "--b2\r\nContent-Type: message/delivery-status\r\n\r\n"
                . "Final-Recipient: rfc822; full@mailbox.test\r\nAction: delayed\r\nStatus: 4.2.2\r\n"
                . "Diagnostic-Code: smtp; 452 4.2.2 Mailbox full\r\n--b2--\r\n",
        ]]);

        self::assertNotSame(Chase::STATUS_STOPPED, Chase::find($this->tenantId, $chase['chase_id'])['status']);
        self::assertNull(Client::find($this->tenantId, $chase['client_id'])['email_invalid_at']);

        $events = ReplyEvent::forChase($this->tenantId, $chase['chase_id']);
        self::assertSame(0, (int) $events[0]['is_hard_bounce']);
        self::assertSame('soft_bounce_noted', $events[0]['action_taken']);
    }

    // ---------------------------------------- self-check: polling is idempotent

    public function testPollingTwiceCreatesNoDuplicateEventsOrPauses(): void
    {
        $chase = $this->startChase('INV-REPLY', 'dana@whitfield.test');
        $messages = [$this->replyMessage(101, $chase['root_message_id'], 'dana@whitfield.test')];

        $this->poll($messages);

        $eventsAfterFirst = $this->eventCount();
        $pausedAt = Chase::find($this->tenantId, $chase['chase_id'])['paused_at'];

        // Rewind the cursor so the second poll genuinely re-reads the same mail.
        EmailAccount::update($this->tenantId, $this->accountId, ['imap_uidnext' => 1]);
        $second = (new ImapPoller())->pollAccount(
            $this->tenantId,
            EmailAccount::find($this->tenantId, $this->accountId),
            $this->now->modify('+1 minute')
        );

        self::assertSame(1, $second['examined'], 'the message should have been re-read');
        self::assertSame(0, $second['recorded'], 'a re-poll must record nothing new');
        self::assertSame($eventsAfterFirst, $this->eventCount());
        self::assertSame($pausedAt, Chase::find($this->tenantId, $chase['chase_id'])['paused_at']);

        // And no duplicate slipped past the unique index.
        $duplicates = Database::connection()->query(
            'SELECT COUNT(*) FROM (
                SELECT email_account_id, provider_message_id, COUNT(*) c
                FROM reply_events GROUP BY 1, 2 HAVING c > 1
             ) x'
        )->fetchColumn();

        self::assertSame(0, (int) $duplicates);
    }

    // -------------------------------------------------- read-only guarantees

    public function testThePollerNeverModifiesTheMailbox(): void
    {
        $chase = $this->startChase('INV-REPLY', 'dana@whitfield.test');
        $this->poll([$this->replyMessage(101, $chase['root_message_id'], 'dana@whitfield.test')]);

        $commands = $this->imap->commandLog();

        // EXAMINE is SELECT without write access.
        self::assertStringContainsString('EXAMINE', $commands);
        self::assertDoesNotMatchRegularExpression('/\bSELECT "INBOX"/', $commands);

        // BODY.PEEK returns content without setting \Seen.
        self::assertStringContainsString('BODY.PEEK', $commands);

        foreach (['STORE', 'COPY', 'MOVE', 'EXPUNGE', 'APPEND', 'DELETE'] as $mutation) {
            self::assertDoesNotMatchRegularExpression(
                '/\b' . $mutation . '\b/i',
                $commands,
                'the poller issued ' . $mutation . ' against a real mailbox'
            );
        }

        self::assertStringNotContainsString('\\Seen', $commands);
    }

    // ------------------------------------------------------------- matching

    public function testASubjectLineAloneNeverMatchesAChase(): void
    {
        $chase = $this->startChase('INV-REPLY', 'dana@whitfield.test');

        // Same subject, no threading headers, unrelated sender.
        $stranger = InboundMessage::parse(1,
            "From: Newsletter <news@somewhere.test>\r\n"
            . "Subject: Re: Invoice INV-REPLY is 18 days overdue\r\n"
        );

        $match = (new ReplyMatcher())->match($this->tenantId, $stranger, $this->now);

        self::assertNull($match['chase_id'], 'a stranger matched on subject alone');
        self::assertSame(ReplyMatcher::MATCH_NONE, $match['matched_by']);
        self::assertContains($chase['chase_id'], [$chase['chase_id']]);
    }

    public function testASenderMatchIsBoundedBySixtyDays(): void
    {
        $chase = $this->startChase('INV-OLD', 'ancient@client.test');
        $matcher = new ReplyMatcher();

        $message = InboundMessage::parse(1, "From: ancient@client.test\r\nSubject: hello again\r\n");

        $this->ageSentMessages($chase['chase_id'], 90);
        self::assertNull($matcher->match($this->tenantId, $message, $this->now)['chase_id']);

        $this->ageSentMessages($chase['chase_id'], 10);
        self::assertSame($chase['chase_id'], $matcher->match($this->tenantId, $message, $this->now)['chase_id']);
    }

    // ------------------------------------------------------- classification

    #[DataProvider('automatedHeaderProvider')]
    public function testAutomatedMailIsRecognised(string $headers): void
    {
        $message = InboundMessage::parse(1, "From: a@b.test\r\n" . $headers);

        self::assertSame(ReplyEvent::TYPE_AUTO_REPLY, (new ReplyMatcher())->classify($message));
    }

    public static function automatedHeaderProvider(): array
    {
        return [
            'Auto-Submitted' => ["Auto-Submitted: auto-generated\r\n"],
            'X-Autoreply' => ["X-Autoreply: yes\r\n"],
            'X-Autorespond' => ["X-Autorespond: yes\r\n"],
            'Precedence' => ["Precedence: auto_reply\r\n"],
            'out of office subject' => ["Subject: Out of Office: your invoice\r\n"],
            'automatic reply subject' => ["Subject: Automatic reply: hello\r\n"],
            'holiday subject' => ["Subject: I am on holiday until Monday\r\n"],
            'encoded OOO subject' => ["Subject: =?UTF-8?B?T3V0IG9mIE9mZmljZQ==?=\r\n"],
        ];
    }

    public function testAutoSubmittedNoIsAHumanReply(): void
    {
        $message = InboundMessage::parse(1,
            "From: dana@whitfield.test\r\nAuto-Submitted: no\r\nSubject: paying today\r\n"
        );

        self::assertSame(ReplyEvent::TYPE_REPLY, (new ReplyMatcher())->classify($message));
    }

    #[DataProvider('bounceHeaderProvider')]
    public function testBouncesAreRecognised(string $headers): void
    {
        $message = InboundMessage::parse(1, $headers);

        self::assertSame(ReplyEvent::TYPE_BOUNCE, (new ReplyMatcher())->classify($message));
    }

    public static function bounceHeaderProvider(): array
    {
        return [
            'multipart/report' => ["From: x@y.test\r\nContent-Type: multipart/report; report-type=delivery-status\r\n"],
            'mailer-daemon' => ["From: mailer-daemon@y.test\r\nSubject: failed\r\n"],
            'postmaster' => ["From: postmaster@y.test\r\nSubject: failed\r\n"],
            'empty return-path' => ["From: x@y.test\r\nReturn-Path: <>\r\n"],
            'failed recipients header' => ["From: x@y.test\r\nX-Failed-Recipients: a@b.test\r\n"],
        ];
    }

    public function testAnOrdinaryNoReplySenderIsNotTreatedAsABounce(): void
    {
        $message = InboundMessage::parse(1, "From: no-reply@stripe.test\r\nSubject: Your receipt\r\n");

        self::assertSame(ReplyEvent::TYPE_REPLY, (new ReplyMatcher())->classify($message));
    }

    public function testHardAndSoftBouncesAreDistinguished(): void
    {
        $matcher = new ReplyMatcher();

        self::assertTrue($matcher->isHardBounce(
            InboundMessage::parse(1, "From: mailer-daemon@y.test\r\nStatus: 5.1.1\r\n")
        ));
        self::assertFalse($matcher->isHardBounce(
            InboundMessage::parse(1, "From: mailer-daemon@y.test\r\nStatus: 4.2.2\r\n")
        ));
        self::assertTrue($matcher->isHardBounce(
            InboundMessage::parse(1, "From: mailer-daemon@y.test\r\nX-Note: 550 User unknown\r\n")
        ));
    }

    // --------------------------------------------------------- header parsing

    public function testFoldedHeadersAreUnfoldedAndOrderedNewestFirst(): void
    {
        $message = InboundMessage::parse(1,
            "From: Dana <dana@whitfield.test>\r\n"
            . "References: <a@x.test>\r\n <b@x.test>\r\n <c@x.test>\r\n"
            . "Subject: Re: hi\r\n"
        );

        self::assertCount(3, $message->references);
        self::assertSame('<c@x.test>', $message->threadCandidates()[0], 'the newest reference should be tried first');
    }

    public function testAMessageWithNoSenderIsUnknownRatherThanAReply(): void
    {
        self::assertSame(
            ReplyEvent::TYPE_UNKNOWN,
            (new ReplyMatcher())->classify(InboundMessage::parse(1, "Subject: nothing\r\n"))
        );
    }

    // ------------------------------------------------------- cursor handling

    public function testARenumberedMailboxDoesNotReplayOldMail(): void
    {
        $chase = $this->startChase('INV-REPLY', 'dana@whitfield.test');
        $this->poll([$this->replyMessage(101, $chase['root_message_id'], 'dana@whitfield.test')]);

        $before = $this->eventCount();

        // A different UIDVALIDITY means every stored UID now means something else.
        EmailAccount::update($this->tenantId, $this->accountId, [
            'imap_uidvalidity' => 999_999,
            'imap_uidnext' => 50,
        ]);

        $result = (new ImapPoller())->pollAccount(
            $this->tenantId,
            EmailAccount::find($this->tenantId, $this->accountId),
            $this->now->modify('+3 minutes')
        );

        self::assertSame(0, $result['recorded']);
        self::assertSame($before, $this->eventCount());
        self::assertSame(
            1000,
            (int) EmailAccount::find($this->tenantId, $this->accountId)['imap_uidvalidity'],
            'the stored UIDVALIDITY should be corrected'
        );
    }

    // -------------------------------------------------------------- helpers

    /**
     * Start a chase and send its first reminder, so there is a real
     * Message-ID for an inbound reply to thread onto.
     *
     * @return array{chase_id:int, client_id:int, invoice_id:int, root_message_id:string}
     */
    // ============================================================
    // Mail that is not about an invoice.
    //
    // The poller used to record every message in the connected mailbox and only
    // then ask whether it belonged to a chase. That mailbox is the user's real
    // inbox, so Duely kept a copy of their ordinary mail -- and the dashboard
    // rendered it as "someone replied", including Duely's own login codes with
    // the code visible in the snippet.
    // ============================================================

    public function testAnUnrelatedEmailIsNotStoredAtAll(): void
    {
        $this->startChase('INV-UNRELATED', 'dana@whitfield.test');

        $before = $this->eventCount();

        $result = $this->poll([[
            'uid' => 501,
            'headers' => "From: Duely <hello@get-duely.com>\r\n"
                . "To: ada@studio.test\r\n"
                . "Subject: Your verification code\r\n"
                . "Message-ID: <otp-1@get-duely.com>\r\n"
                . "Content-Type: text/plain\r\n",
            'body' => "Use this code to sign in: 471973. It expires in 10 minutes.\r\n",
        ]]);

        // No row. Not a hidden one, not an empty one -- none. The only way to be
        // sure a login code is not kept is never to write it down.
        self::assertSame($before, $this->eventCount(), 'unrelated mail was stored');

        // And nothing of it survives anywhere in the table.
        self::assertSame(0, $this->rowsContaining('471973'));
        self::assertSame(0, $this->rowsContaining('get-duely.com'));

        // Still counted, so the operations page keeps its throughput number.
        self::assertSame(1, $result['examined'], 'the message should still be examined');
        self::assertSame(0, $result['recorded'], 'recorded means attached to a chase');
        self::assertSame(1, $result['unmatched']);
    }

    public function testAGenuineReplyIsStillRecordedAndStillPauses(): void
    {
        $chase = $this->startChase('INV-STILL-WORKS', 'dana@whitfield.test');

        $result = $this->poll([
            $this->replyMessage(1, $chase['root_message_id'], 'dana@whitfield.test'),
        ]);

        self::assertSame(1, $result['recorded']);
        self::assertSame(0, $result['unmatched']);
        self::assertSame(1, $result['paused']);

        self::assertSame(
            Chase::STATUS_PAUSED,
            Chase::find($this->tenantId, $chase['chase_id'])['status']
        );

        // The row exists, is attached, and keeps its snippet -- this one is
        // about an invoice, and the user needs to read what was said.
        $event = $this->latestEvent();
        self::assertNotNull($event['chase_id']);
        self::assertNotEmpty($event['snippet']);
    }

    public function testAnOutOfOfficeStillDoesNotPause(): void
    {
        $chase = $this->startChase('INV-OOO-KEPT', 'dana@whitfield.test');

        $result = $this->poll([[
            'uid' => 502,
            'headers' => "From: Dana Whitfield <dana@whitfield.test>\r\n"
                . "To: ada@studio.test\r\n"
                . "Subject: Automatic reply: Invoice INV-OOO-KEPT\r\n"
                . "Message-ID: <ooo-kept@whitfield.test>\r\n"
                . 'In-Reply-To: ' . $chase['root_message_id'] . "\r\n"
                . "Auto-Submitted: auto-replied\r\n"
                . "Content-Type: text/plain\r\n",
            'body' => "I am away until the 30th.\r\n",
        ]]);

        // It matched a chase, so it is stored -- but a holiday is not an answer
        // and the ladder keeps climbing.
        self::assertSame(1, $result['recorded']);
        self::assertSame(0, $result['paused']);
        self::assertNotSame(
            Chase::STATUS_PAUSED,
            Chase::find($this->tenantId, $chase['chase_id'])['status']
        );
    }

    public function testAnUnmatchedBounceIsKeptButWithoutABodySnippet(): void
    {
        $this->startChase('INV-BOUNCE-EARLY', 'dana@whitfield.test');

        $before = $this->eventCount();

        $this->poll([[
            'uid' => 503,
            'headers' => "From: MAILER-DAEMON@mail.test\r\n"
                . "To: ada@studio.test\r\n"
                . "Subject: Undelivered Mail Returned to Sender\r\n"
                . "Message-ID: <bounce-early@mail.test>\r\n"
                . "X-Failed-Recipients: nobody@nowhere.test\r\n"
                . "Content-Type: multipart/report; report-type=delivery-status\r\n",
            'body' => "550 5.1.1 The email account that you tried to reach does not exist.\r\n",
        ]]);

        // Kept, because a bounce can arrive before the chase it belongs to is
        // matchable, and losing it means chasing a dead address.
        self::assertSame($before + 1, $this->eventCount());

        $event = $this->latestEvent();
        self::assertNull($event['chase_id']);
        self::assertSame(ReplyEvent::TYPE_BOUNCE, $event['type']);

        // The address and the reason, never the body. A delivery report quotes
        // the original message, which is the one thing that must not be kept
        // from a message with nowhere to attach.
        self::assertNotEmpty($event['from_email']);
        self::assertEmpty($event['snippet'], 'an unmatched bounce kept a body snippet');
        self::assertEmpty($event['raw_headers'], 'an unmatched bounce kept its headers');
    }

    public function testTheDashboardShowsNothingWhenNothingMatchedAChase(): void
    {
        $this->startChase('INV-DASH', 'dana@whitfield.test');

        $this->poll([[
            'uid' => 504,
            'headers' => "From: Duely <hello@get-duely.com>\r\n"
                . "To: ada@studio.test\r\n"
                . "Subject: Your verification code\r\n"
                . "Message-ID: <otp-2@get-duely.com>\r\n"
                . "Content-Type: text/plain\r\n",
            'body' => "Use this code to sign in: 471973.\r\n",
        ]]);

        // Belt and braces: even an unmatched row written by an older build must
        // not reach this panel, so the row is forced in and the query is what is
        // under test.
        Database::connection()->prepare(
            'INSERT INTO reply_events
                (tenant_id, email_account_id, provider_message_id, provider_uid, type,
                 matched_by, from_email, subject, snippet, rfc_message_id, received_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([
            $this->tenantId,
            $this->accountId,
            'legacy:999',
            999,
            ReplyEvent::TYPE_REPLY,
            'none',
            'admin@client-cue.com',
            'Your ClientCue Login Code',
            'Hello hello! Your one-time login code is: 724530',
            '<legacy-999@client-cue.com>',
        ]);

        $attention = (new DashboardMetrics())->needsAttention($this->tenantId);

        self::assertSame([], $attention, 'unmatched mail reached the dashboard');
    }

    public function testAHistoricalUnmatchedRowNeverReachesTheInboxViewEither(): void
    {
        $this->startChase('INV-INBOX', 'dana@whitfield.test');

        Database::connection()->prepare(
            'INSERT INTO reply_events
                (tenant_id, email_account_id, provider_message_id, provider_uid, type,
                 matched_by, from_email, subject, snippet, rfc_message_id, received_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([
            $this->tenantId,
            $this->accountId,
            'legacy:998',
            998,
            ReplyEvent::TYPE_REPLY,
            'none',
            'bank@example.test',
            'Your statement is ready',
            'Your December statement is now available.',
            '<legacy-998@example.test>',
        ]);

        $rows = ReplyEvent::recentWithContext($this->tenantId);

        // Asserted positively. A foreach over an empty result asserts nothing,
        // which passes for the wrong reason and tells PHPUnit the test is risky.
        self::assertSame(
            [],
            array_values(array_filter($rows, static fn (array $row): bool => $row['chase_id'] === null)),
            'an unattached row reached the inbox view'
        );

        // And the row really is in the table, so the filter is what excluded it
        // rather than the insert having failed.
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM reply_events WHERE tenant_id = ? AND from_email = ?'
        );
        $statement->execute([$this->tenantId, 'bank@example.test']);
        self::assertSame(1, (int) $statement->fetchColumn());
    }

    private function latestEvent(): array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM reply_events WHERE tenant_id = ? ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([$this->tenantId]);

        return $statement->fetch() ?: [];
    }

    /**
     * How many rows anywhere in the table carry this string, in any column that
     * could hold message content.
     */
    private function rowsContaining(string $needle): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM reply_events
             WHERE COALESCE(snippet, "") LIKE ?
                OR COALESCE(subject, "") LIKE ?
                OR COALESCE(from_email, "") LIKE ?
                OR COALESCE(raw_headers, "") LIKE ?'
        );
        $like = '%' . $needle . '%';
        $statement->execute([$like, $like, $like, $like]);

        return (int) $statement->fetchColumn();
    }

    private function startChase(string $number, string $clientEmail): array
    {
        $this->ensureAccount();

        $clientId = Client::findOrCreate($this->tenantId, $clientEmail, [
            'name' => 'Dana Whitfield',
            'timezone' => 'UTC',
        ]);

        $invoiceId = Invoice::create($this->tenantId, [
            'client_id' => $clientId,
            'number' => $number,
            'amount_cents' => 320000,
            'currency' => 'USD',
            'due_date' => $this->now->modify('-18 days')->format('Y-m-d'),
        ]);

        (new ChaseScheduler())->start($this->tenantId, $invoiceId, $this->sequenceId, $this->accountId, $this->now);
        (new ChaseSender(new RecordingTransport()))->processDueForTenant($this->tenantId, $this->now);

        $chase = Chase::forInvoice($this->tenantId, $invoiceId);

        return [
            'chase_id' => (int) $chase['id'],
            'client_id' => $clientId,
            'invoice_id' => $invoiceId,
            'root_message_id' => (string) $chase['root_message_id'],
        ];
    }

    /**
     * @param array<int, array{uid:int, headers:string, body?:string}> $messages
     */
    private function poll(array $messages): array
    {
        $this->imap = FakeImapServer::start($messages);

        EmailAccount::update($this->tenantId, $this->accountId, [
            'imap_host' => '127.0.0.1',
            'imap_port' => $this->imap->port,
            'imap_encryption' => 'none',
            'imap_uidnext' => 1,
            'imap_uidvalidity' => null,
        ]);

        return (new ImapPoller())->pollAccount(
            $this->tenantId,
            EmailAccount::find($this->tenantId, $this->accountId),
            $this->now
        );
    }

    private function ensureAccount(): void
    {
        if ($this->accountId !== null) {
            return;
        }

        $this->accountId = EmailAccount::create($this->tenantId, [
            'from_name' => 'Ada Lovelace',
            'from_email' => 'ada@studio.test',
            'smtp_host' => 'smtp.test',
            'smtp_port' => 587,
            'smtp_username' => 'ada@studio.test',
            'smtp_password' => 'app-password',
            'imap_host' => '127.0.0.1',
            'imap_port' => 1,
            'imap_encryption' => 'none',
            'imap_username' => 'ada@studio.test',
            'imap_password' => 'app-password',
            'imap_folder' => 'INBOX',
            'status' => EmailAccount::STATUS_ACTIVE,
            'is_default' => 1,
        ]);
    }

    /**
     * @return array{uid:int, headers:string, body:string}
     */
    private function replyMessage(int $uid, string $rootMessageId, string $from): array
    {
        return [
            'uid' => $uid,
            'headers' => "Return-Path: <$from>\r\n"
                . "From: Dana Whitfield <$from>\r\n"
                . "To: Ada Lovelace <ada@studio.test>\r\n"
                . "Subject: Re: Invoice INV-REPLY is 18 days overdue\r\n"
                . "Date: Wed, 19 Aug 2026 10:30:00 +0000\r\n"
                . "Message-ID: <client-reply-$uid@whitfield.test>\r\n"
                . "In-Reply-To: $rootMessageId\r\n"
                . "References: $rootMessageId\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n",
            'body' => "Hi Ada,\r\n\r\nSorry about that - cheque went out Friday, should be with you Monday.\r\n\r\n"
                . "Dana\r\n\r\n> On Wed, Ada wrote:\r\n> Invoice INV-REPLY is 18 days overdue\r\n",
        ];
    }

    private function ageSentMessages(int $chaseId, int $days): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE chase_messages SET sent_at = ? WHERE tenant_id = ? AND chase_id = ?'
        );
        $statement->execute([
            Clock::toDatabase($this->now->modify('-' . $days . ' days')),
            $this->tenantId,
            $chaseId,
        ]);
    }

    private function eventCount(): int
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM reply_events WHERE tenant_id = ?');
        $statement->execute([$this->tenantId]);

        return (int) $statement->fetchColumn();
    }

    private function mailLog(): string
    {
        $path = self::$basePath . '/storage/logs/mail.log';

        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
