<?php

namespace Keel\App\Jobs;

use DateTimeImmutable;
use Keel\App\Services\Clock;
use Keel\App\Services\FoundingCounter;
use Keel\App\Services\WaitlistService;
use Keel\Core\Database;
use Keel\Core\Env;
use Keel\Core\Mailer;
use Throwable;

/**
 * Telling the waitlist that signup is open.
 *
 * These people gave Duely an address before there was anything to give them, and
 * then confirmed it. They are the warmest audience the product has, and the
 * worst possible outcome is that they hear about the launch from somewhere else
 * — or not at all, because the waitlist quietly disappeared from the site.
 *
 * Sent once, to confirmed addresses only, and recorded so a second run mails
 * nobody twice. `announced_at` is written *before* the send for the same reason
 * the founding warning does it: a mail failure that left the column null would
 * mean the next run tries again, and the run after that.
 *
 * Every message carries the same unsubscribe link the confirmation email did.
 * Somebody who has been waiting months for one email should not have to hunt
 * for the way out of the second.
 *
 * Run deliberately -- `php bin/announce-signup.php` -- rather than from the
 * worker loop. A job that mails an entire list is not one that should fire
 * because a process restarted.
 */
class AnnounceSignupToWaitlistJob
{
    /**
     * @param bool $dryRun report who would be mailed without sending anything
     * @return array{eligible:int, sent:int, failed:int, errors:string[]}
     */
    public function run(bool $dryRun = false, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();

        $result = ['eligible' => 0, 'sent' => 0, 'failed' => 0, 'errors' => []];
        $recipients = $this->pending();

        $result['eligible'] = count($recipients);

        if ($dryRun) {
            return $result;
        }

        $founding = (new FoundingCounter())->snapshot();

        foreach ($recipients as $row) {
            try {
                if (!$this->claim((string) $row['email'], $now)) {
                    // Another run took it between the query and here.
                    continue;
                }

                if ($this->send((string) $row['email'], $founding)) {
                    $result['sent']++;
                } else {
                    $result['failed']++;
                    $result['errors'][] = 'Send failed for ' . $row['email'];
                }
            } catch (Throwable $exception) {
                $result['failed']++;
                $result['errors'][] = $row['email'] . ': ' . $exception->getMessage();
            }
        }

        return $result;
    }

    // -------------------------------------------------------------- internals

    /**
     * Confirmed, not unsubscribed, not already told.
     *
     * Pending signups are excluded on purpose: they gave an address and never
     * confirmed it, so Duely does not have permission to mail them about
     * anything other than that confirmation.
     */
    private function pending(): array
    {
        $statement = Database::connection()->prepare(
            'SELECT email FROM waitlist_signups
             WHERE status = ? AND announced_at IS NULL
             ORDER BY id ASC'
        );
        $statement->execute([WaitlistService::STATUS_CONFIRMED]);

        return $statement->fetchAll() ?: [];
    }

    /**
     * Mark before sending, conditionally, so two runs cannot both take a row.
     */
    private function claim(string $email, DateTimeImmutable $now): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE waitlist_signups SET announced_at = ?
             WHERE email = ? AND status = ? AND announced_at IS NULL'
        );
        $statement->execute([Clock::toDatabase($now), $email, WaitlistService::STATUS_CONFIRMED]);

        return $statement->rowCount() > 0;
    }

    private function send(string $email, ?array $founding): bool
    {
        $base = rtrim((string) Env::get('APP_URL', ''), '/');
        $signupUrl = $base . '/signup?email=' . urlencode($email);
        $unsubscribeUrl = (new WaitlistService())->unsubscribeUrl($email);

        // The offer, in the same words the site uses. A launch email that
        // describes the deal differently from the page it links to is the
        // beginning of an argument.
        $offer = match (true) {
            $founding === null => '',
            $founding['state'] === FoundingCounter::STATE_GONE =>
                '<p>The 50 founding places have been taken, so this is standard pricing &mdash; '
                . 'and the free plan has no time limit.</p>',
            default =>
                '<p><strong>' . (int) $founding['remaining'] . ' of ' . (int) $founding['total']
                . ' founding places are left.</strong> Signing up takes one, and it holds $19 a month '
                . 'for as long as you stay subscribed, whatever the price becomes later.</p>',
        };

        return Mailer::send(
            $email,
            $email,
            'Duely is open',
            '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;'
            . 'max-width:480px;margin:0 auto;padding:32px;color:#171717;font-size:15px;line-height:1.6;">'
            . '<h2 style="margin:0 0 16px;font-size:20px;color:#0a0a0a;">You can sign up now</h2>'
            . '<p>You joined the Duely waitlist, so you get this one email: it is open.</p>'
            . $offer
            . '<p>Duely chases your overdue invoices from your own inbox &mdash; a polite nudge at '
            . 'three days, a firmer note at fourteen &mdash; and stops the moment a client replies '
            . 'or pays. No card to start.</p>'
            . '<p style="margin-top:24px;"><a href="' . htmlspecialchars($signupUrl, ENT_QUOTES, 'UTF-8') . '" '
            . 'style="display:inline-block;background:#0f766e;color:#ffffff;text-decoration:none;'
            . 'padding:12px 24px;border-radius:8px;font-weight:600;">Create your account</a></p>'
            . '<p style="margin-top:28px;font-size:13px;color:#737373;">'
            . 'This is the email you signed up for. '
            . '<a href="' . htmlspecialchars($unsubscribeUrl, ENT_QUOTES, 'UTF-8') . '" '
            . 'style="color:#737373;">Unsubscribe</a> and you will hear nothing further.'
            . '</p>'
            . '</div>'
        );
    }
}
