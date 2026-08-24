<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use Keel\Core\Database;
use Keel\Core\Env;
use Keel\Core\Mailer;

/**
 * The waitlist.
 *
 * Double opt-in, and genuinely double: nothing is on the list until the person
 * has clicked a link sent to the address they typed. That is not politeness —
 * a single-opt-in list collects typos and other people's addresses, and the
 * first campaign you send from it is the one that gets the domain blocked.
 *
 * Two consequences run through this class:
 *
 *   Every response to a join is the same sentence, whether the address is new,
 *   already pending, or already confirmed. A form that says "you are already on
 *   the list" is an address checker.
 *
 *   The confirmation token is stored as a hash. A copy of this table must not
 *   let anyone confirm an address they do not own.
 */
class WaitlistService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    /** How long a confirmation link stays good. Long enough to survive a weekend. */
    private const CONFIRM_TTL_DAYS = 7;

    /** Resends per address. Enough for a lost email, not enough to be a mail cannon. */
    private const MAX_SENDS = 5;

    /** The UTM parameters worth keeping, and the only ones read from the request. */
    public const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /**
     * Add an address to the list, or refresh the one that is already there.
     *
     * @param array<string, mixed> $context source, landing_path, referrer, utm_*, ip, user_agent
     * @return array{ok:bool, message:string, state:string}
     */
    public function join(string $email, array $context = [], ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();
        $email = $this->normaliseEmail($email);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'ok' => false,
                'message' => 'That does not look like an email address.',
                'state' => 'invalid',
            ];
        }

        $existing = $this->findByEmail($email);

        // Already confirmed: nothing to do, and nothing to say that would tell
        // a stranger this address is on the list.
        if ($existing !== null && $existing['status'] === self::STATUS_CONFIRMED) {
            return $this->sentResponse('already_confirmed');
        }

        if ($existing !== null && (int) $existing['confirm_send_count'] >= self::MAX_SENDS) {
            // Quietly stop sending. The person has had five; a sixth is not the
            // problem, and saying so out loud would confirm the address exists.
            return $this->sentResponse('throttled');
        }

        $rawToken = bin2hex(random_bytes(32));
        $hash = hash('sha256', $rawToken);
        $expiresAt = Clock::toDatabase($now->modify('+' . self::CONFIRM_TTL_DAYS . ' days'));

        $this->upsert($email, $hash, $expiresAt, $context, $now, $existing);

        $sent = $this->sendConfirmation($email, $rawToken);

        if (!$sent) {
            return [
                'ok' => false,
                'message' => 'We could not send the confirmation email just now. Try again in a moment.',
                'state' => 'send_failed',
            ];
        }

        return $this->sentResponse($existing === null ? 'created' : 'resent');
    }

    /**
     * Complete the opt-in.
     *
     * @return array{ok:bool, message:string, state:string, email:?string}
     */
    public function confirm(string $rawToken, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();
        $rawToken = trim($rawToken);

        if ($rawToken === '') {
            return $this->confirmFailure('missing');
        }

        $statement = Database::connection()->prepare(
            'SELECT * FROM waitlist_signups WHERE confirm_token_hash = ? LIMIT 1'
        );
        $statement->execute([hash('sha256', $rawToken)]);
        $row = $statement->fetch();

        if (!$row) {
            return $this->confirmFailure('unknown');
        }

        // Already done. Clicking the link twice is a normal thing to do, and
        // the second click should read as success rather than as an error.
        if ($row['status'] === self::STATUS_CONFIRMED) {
            return [
                'ok' => true,
                'message' => 'You are on the list.',
                'state' => 'already_confirmed',
                'email' => (string) $row['email'],
            ];
        }

        $expiresAt = Clock::fromDatabase($row['confirm_expires_at'] ?? null);

        if ($expiresAt !== null && $expiresAt <= $now) {
            return $this->confirmFailure('expired');
        }

        $update = Database::connection()->prepare(
            'UPDATE waitlist_signups
             SET status = ?, confirmed_at = ?, confirm_token_hash = NULL,
                 confirm_expires_at = NULL, updated_at = ?
             WHERE id = ? AND status <> ?'
        );
        $update->execute([
            self::STATUS_CONFIRMED,
            Clock::toDatabase($now),
            Clock::toDatabase($now),
            (int) $row['id'],
            self::STATUS_CONFIRMED,
        ]);

        return [
            'ok' => true,
            'message' => 'You are on the list.',
            'state' => 'confirmed',
            'email' => (string) $row['email'],
        ];
    }

    /**
     * Take an address off the list.
     *
     * The token is an HMAC of the address rather than a stored secret, so it
     * stays valid for the life of the list without a column to leak, and an
     * unsubscribe link never stops working the way a one-shot token would.
     *
     * @return array{ok:bool, message:string, state:string}
     */
    public function unsubscribe(string $email, string $token, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();
        $email = $this->normaliseEmail($email);

        if (!hash_equals($this->unsubscribeToken($email), trim($token))) {
            return [
                'ok' => false,
                'message' => 'That unsubscribe link is not valid. Reply to any of our emails and we will remove you by hand.',
                'state' => 'invalid',
            ];
        }

        $statement = Database::connection()->prepare(
            'UPDATE waitlist_signups
             SET status = ?, unsubscribed_at = ?, confirm_token_hash = NULL,
                 confirm_expires_at = NULL, updated_at = ?
             WHERE email = ?'
        );
        $statement->execute([
            self::STATUS_UNSUBSCRIBED,
            Clock::toDatabase($now),
            Clock::toDatabase($now),
            $email,
        ]);

        // The same answer whether or not the address was on the list. A valid
        // token proves ownership, not membership.
        return [
            'ok' => true,
            'message' => 'Done — that address is off the list. We will not email it again.',
            'state' => 'unsubscribed',
        ];
    }

    /**
     * The token that appears in an unsubscribe link.
     */
    public function unsubscribeToken(string $email): string
    {
        return hash_hmac('sha256', $this->normaliseEmail($email), $this->secret());
    }

    public function unsubscribeUrl(string $email): string
    {
        return rtrim((string) Env::get('APP_URL', ''), '/')
            . '/waitlist/unsubscribe?email=' . urlencode($this->normaliseEmail($email))
            . '&token=' . $this->unsubscribeToken($email);
    }

    /**
     * How many people have actually confirmed. The only number worth showing.
     */
    public function confirmedCount(): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM waitlist_signups WHERE status = ?'
        );
        $statement->execute([self::STATUS_CONFIRMED]);

        return (int) $statement->fetchColumn();
    }

    public function findByEmail(string $email): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM waitlist_signups WHERE email = ? LIMIT 1'
        );
        $statement->execute([$this->normaliseEmail($email)]);

        return $statement->fetch() ?: null;
    }

    /**
     * Pull the attribution out of a request, keeping only what we said we keep.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public static function contextFrom(array $query, array $server = [], string $source = 'landing'): array
    {
        $context = [
            'source' => self::clamp($source, 64),
            'landing_path' => self::clamp((string) ($query['landing_path'] ?? ''), 255) ?: null,
            'referrer' => self::clamp((string) ($server['HTTP_REFERER'] ?? ''), 512) ?: null,
            'user_agent' => self::clamp((string) ($server['HTTP_USER_AGENT'] ?? ''), 255) ?: null,
            'ip' => (string) ($server['REMOTE_ADDR'] ?? ''),
        ];

        foreach (self::UTM_KEYS as $key) {
            $context[$key] = self::clamp((string) ($query[$key] ?? ''), 128) ?: null;
        }

        return $context;
    }

    // -------------------------------------------------------------- internals

    /**
     * The same answer for every outcome a stranger could probe for.
     */
    private function sentResponse(string $state): array
    {
        return [
            'ok' => true,
            'message' => 'Check your inbox — we have sent you a link to confirm.',
            'state' => $state,
        ];
    }

    private function confirmFailure(string $state): array
    {
        return [
            'ok' => false,
            'message' => 'That confirmation link is no longer valid. Join again and we will send a fresh one.',
            'state' => $state,
            'email' => null,
        ];
    }

    private function upsert(
        string $email,
        string $tokenHash,
        string $expiresAt,
        array $context,
        DateTimeImmutable $now,
        ?array $existing
    ): void {
        $stamp = Clock::toDatabase($now);
        $ipHash = $this->hashIp((string) ($context['ip'] ?? ''));

        if ($existing !== null) {
            // Attribution is not overwritten. The campaign that first brought
            // someone here is the one that earned the signup.
            $statement = Database::connection()->prepare(
                'UPDATE waitlist_signups
                 SET status = ?, confirm_token_hash = ?, confirm_expires_at = ?,
                     confirm_sent_at = ?, confirm_send_count = confirm_send_count + 1,
                     unsubscribed_at = NULL, updated_at = ?
                 WHERE id = ?'
            );
            $statement->execute([
                self::STATUS_PENDING,
                $tokenHash,
                $expiresAt,
                $stamp,
                $stamp,
                (int) $existing['id'],
            ]);

            return;
        }

        $statement = Database::connection()->prepare(
            'INSERT INTO waitlist_signups
                (email, status, confirm_token_hash, confirm_expires_at, confirm_sent_at,
                 confirm_send_count, source, landing_path, referrer,
                 utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                 ip_hash, user_agent, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $statement->execute([
            $email,
            self::STATUS_PENDING,
            $tokenHash,
            $expiresAt,
            $stamp,
            self::clamp((string) ($context['source'] ?? 'landing'), 64) ?: 'landing',
            $context['landing_path'] ?? null,
            $context['referrer'] ?? null,
            $context['utm_source'] ?? null,
            $context['utm_medium'] ?? null,
            $context['utm_campaign'] ?? null,
            $context['utm_term'] ?? null,
            $context['utm_content'] ?? null,
            $ipHash,
            $context['user_agent'] ?? null,
            $stamp,
            $stamp,
        ]);
    }

    /**
     * The address is hashed with the app key, so the column is useful for
     * spotting one machine submitting two hundred addresses and useless as a
     * record of who visited.
     */
    private function hashIp(string $ip): ?string
    {
        $ip = trim($ip);

        if ($ip === '') {
            return null;
        }

        return hash('sha256', $ip . '|' . $this->secret());
    }

    /**
     * The key everything here is keyed on. Falls back to the app URL only so a
     * misconfigured install produces stable-but-useless tokens rather than a
     * fatal on a marketing page.
     */
    private function secret(): string
    {
        foreach (['APP_ENCRYPTION_KEY', 'APP_KEY'] as $name) {
            $value = trim((string) Env::get($name, ''));

            if ($value !== '') {
                return $value;
            }
        }

        return (string) Env::get('APP_URL', 'duely');
    }

    private function sendConfirmation(string $email, string $rawToken): bool
    {
        $url = rtrim((string) Env::get('APP_URL', ''), '/')
            . '/waitlist/confirm?token=' . urlencode($rawToken);

        return Mailer::send(
            $email,
            $email,
            'Confirm your place on the Duely waitlist',
            $this->confirmationEmail($url, $this->unsubscribeUrl($email))
        );
    }

    private function confirmationEmail(string $url, string $unsubscribeUrl): string
    {
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $safeUnsubscribe = htmlspecialchars($unsubscribeUrl, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; max-width: 480px; margin: 0 auto; padding: 32px; color: #171717;">
            <h2 style="margin: 0 0 16px; font-size: 20px; color: #0a0a0a;">One click and you are on the list</h2>
            <p style="margin: 0 0 20px; font-size: 15px; line-height: 1.6; color: #525252;">
                Someone entered this address on the Duely waitlist. If that was you, confirm below
                and we will email you when your place is ready.
            </p>
            <a href="{$safeUrl}" style="display: inline-block; background: #22c55e; color: #062012; text-decoration: none; padding: 12px 24px; border-radius: 8px; font-weight: 600; font-size: 15px;">Confirm my place</a>
            <p style="margin: 24px 0 0; font-size: 13px; line-height: 1.6; color: #737373;">
                The link works for seven days. If it was not you, ignore this email — we will not
                add the address, and we will not email it again.
            </p>
            <p style="margin: 16px 0 0; font-size: 13px; color: #a3a3a3;">
                Duely — get paid without writing the awkward follow-up.
                <a href="{$safeUnsubscribe}" style="color: #a3a3a3;">Remove this address</a>.
            </p>
        </div>
        HTML;
    }

    private function normaliseEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private static function clamp(string $value, int $length): string
    {
        return mb_substr(trim($value), 0, $length);
    }
}
