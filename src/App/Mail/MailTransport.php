<?php

namespace Keel\App\Mail;

/**
 * How Duely puts a message into the world.
 *
 * The whole point of this interface is that ChaseScheduler and ChaseSender
 * never learn which one is in use. SmtpTransport is the implementation today;
 * a GmailApiTransport or an OutlookGraphTransport drops in by implementing
 * these three methods and nothing upstream changes.
 *
 * The contract:
 *
 *   - `send()` never throws for an expected failure. A wrong password, a
 *     blocked port, a throttled mailbox: all of those come back as a
 *     SendResult so the sender can decide about retries from one code path.
 *   - `send()` is responsible for honouring the message's threading headers
 *     as far as its protocol allows. An API transport that has no Message-ID
 *     header should return the id the provider assigned, so the sender can
 *     record what will actually appear in the client's References chain.
 *   - `send()` does not decide whether the send was allowed. Rate limits and
 *     hard stops are the sender's business and are checked before we get here.
 */
interface MailTransport
{
    /**
     * Deliver one message through one mailbox.
     *
     * @param array $account an `email_accounts` row, credentials still encrypted
     */
    public function send(array $account, OutboundMessage $message): SendResult;

    /**
     * Can this transport drive this account at all?
     *
     * Lets the sender skip an account an OAuth-only transport cannot use,
     * rather than failing once per queued message.
     */
    public function supports(array $account): bool;

    /**
     * A short identifier for logs and diagnostics, e.g. "smtp".
     */
    public function name(): string;
}
