<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use Keel\Core\Activity;
use Keel\Core\Database;

/**
 * Turning a verified email address into a working workspace.
 *
 * Deliberately not a second authentication system. Signup posts to the same
 * `/auth/otp/request` and `/auth/otp/verify` endpoints the sign-in form uses,
 * and OtpService already creates the user row when it sends a code. So by the
 * time this class runs, the address is verified and the user exists; all that
 * is left is the workspace.
 *
 * That matters beyond tidiness. Two OTP implementations means two rate limiters,
 * two expiry windows and two sets of timing behaviour — and the one nobody is
 * looking at is the one that stays unpatched.
 *
 * Because it runs on *every* successful sign-in rather than on a signup-only
 * route, it has to be idempotent: a returning user already has a workspace and
 * must fall straight through.
 */
class SignupService
{
    public function __construct(private readonly PlanService $plans = new PlanService())
    {
    }

    /**
     * Make sure this user has a workspace, creating one on first arrival.
     *
     * @return array{created:bool, tenant_id:int, founding:bool, slot:?int}
     */
    public function provision(int $userId, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();

        $existing = $this->organizationIdFor($userId);

        if ($existing !== null) {
            // A returning user. Nothing to do, and nothing said about it.
            return ['created' => false, 'tenant_id' => $existing, 'founding' => false, 'slot' => null];
        }

        $user = $this->user($userId);

        if ($user === null) {
            throw new \RuntimeException('Cannot provision a workspace for a user that does not exist.');
        }

        // ------------------------------------------------------------------
        // One transaction: the organization, the membership, and the founding
        // slot. A crash between creating the workspace and claiming the slot
        // would otherwise leave a slot held by a tenant that does not exist,
        // and there are only fifty of them -- every leak is permanent and
        // visible on the homepage counter.
        // ------------------------------------------------------------------
        $result = Database::transaction(function () use ($user, $userId, $now): array {
            $tenantId = $this->createOrganization($user, $now);

            $this->attach($userId, $tenantId);

            // Not an error when they are gone. Signup succeeds at standard
            // pricing and the page says so; refusing to create an account
            // because a discount ran out would be an odd way to treat the
            // fifty-first person to trust you.
            $claim = $this->plans->claimFoundingSlot($tenantId, $now);

            return [
                'created' => true,
                'tenant_id' => $tenantId,
                'founding' => (bool) $claim['claimed'],
                'slot' => $claim['slot'],
            ];
        });

        Activity::log('signup.completed', 'Organization', $result['tenant_id'], [
            'founding' => $result['founding'],
            'slot' => $result['slot'],
        ], $result['tenant_id']);

        return $result;
    }

    /**
     * A workspace name from the email address.
     *
     * Never asked for at signup. Every field on that form costs conversions and
     * this one is not needed to start -- onboarding is where it gets renamed,
     * by which point the person has seen the product and knows what to call it.
     *
     * A work address gives the company; a free-mail address gives the person.
     * Both are better than "My Workspace", and neither is load-bearing.
     */
    public static function nameFromEmail(string $email): string
    {
        $email = strtolower(trim($email));
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $freeMail = [
            'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com',
            'yahoo.com', 'yahoo.co.uk', 'icloud.com', 'me.com', 'mac.com',
            'proton.me', 'protonmail.com', 'aol.com', 'gmx.com', 'zoho.com',
        ];

        $source = ($domain !== '' && !in_array($domain, $freeMail, true))
            // "whitfield-partners.co.uk" -> "Whitfield Partners"
            ? explode('.', $domain)[0]
            : $local;

        $words = preg_replace('/[._\-+]+/', ' ', $source);
        $words = trim(preg_replace('/\s+/', ' ', (string) $words));

        if ($words === '') {
            return 'My workspace';
        }

        return mb_substr(ucwords($words), 0, 120);
    }

    // -------------------------------------------------------------- internals

    private function createOrganization(array $user, DateTimeImmutable $now): int
    {
        $name = self::nameFromEmail((string) $user['email']);

        $connection = Database::connection();
        $statement = $connection->prepare(
            'INSERT INTO organizations (name, slug, created_at, updated_at) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$name, $this->uniqueSlug($name), Clock::toDatabase($now), Clock::toDatabase($now)]);

        return (int) $connection->lastInsertId();
    }

    private function attach(int $userId, int $tenantId): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE users SET organization_id = ?, role = ? WHERE id = ? AND organization_id IS NULL'
        );
        $statement->execute([$tenantId, 'owner', $userId]);
    }

    /**
     * The slug column is unique, and two people signing up from the same domain
     * in the same second is exactly the case a bare slug would collide on.
     */
    private function uniqueSlug(string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-') ?: 'workspace';
        $slug = $base;

        $statement = Database::connection()->prepare('SELECT 1 FROM organizations WHERE slug = ? LIMIT 1');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $statement->execute([$slug]);

            if ($statement->fetchColumn() === false) {
                return $slug;
            }

            $slug = $base . '-' . bin2hex(random_bytes(3));
        }

        return $base . '-' . bin2hex(random_bytes(6));
    }

    private function organizationIdFor(int $userId): ?int
    {
        $statement = Database::connection()->prepare(
            'SELECT organization_id FROM users WHERE id = ? LIMIT 1'
        );
        $statement->execute([$userId]);
        $id = $statement->fetchColumn();

        return $id ? (int) $id : null;
    }

    private function user(int $userId): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $statement->execute([$userId]);

        return $statement->fetch() ?: null;
    }
}
