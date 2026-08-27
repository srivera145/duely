<?php

namespace Keel\App\Support;

use Keel\App\Services\ImpersonationService;
use Keel\Core\Database;
use Keel\Core\Session;

/**
 * Is the person at the keyboard the platform operator?
 *
 * One answer, in one place, because two places would drift. The middleware
 * guarding `/super-admin` and the navigation deciding whether to show a link to
 * it have to agree exactly: a visible link the door refuses is a bug report, and
 * a hidden link on a door that opens is a control somebody will assume exists.
 *
 * Two rules, both deliberate:
 *
 * **Read from the database, never the session.** Revoking somebody's operator
 * rights has to take effect on their next request rather than at their next
 * login — a revocation that waits for the user to cooperate is a request, not a
 * revocation. That means a primary-key lookup per call and no memoisation: a
 * cache would reintroduce exactly the staleness this exists to avoid, and it is
 * one indexed row.
 *
 * **False during impersonation.** An operator inside a customer's session is
 * that customer for the duration. Showing them a link into the panel would offer
 * an escalation path back out, and the panel refuses it anyway.
 */
class Operator
{
    public static function isCurrent(): bool
    {
        // Checked first and cheaply. Inside a support session the answer is no,
        // whatever the person behind it is entitled to elsewhere.
        if ((new ImpersonationService())->isActive()) {
            return false;
        }

        // The session's own user, not Auth::id() -- impersonation rewrites that
        // one, and this question is always about the real human.
        $userId = ImpersonationService::realUserId();

        if ($userId === null || $userId <= 0) {
            return false;
        }

        $statement = Database::connection()->prepare(
            'SELECT is_super_admin FROM users WHERE id = ? LIMIT 1'
        );
        $statement->execute([$userId]);
        $row = $statement->fetch();

        return $row !== false && (int) $row['is_super_admin'] === 1;
    }

    /**
     * Where the operator link points. The panel's landing page is the
     * operations screen, which is the question worth asking first.
     */
    public static function panelHref(): string
    {
        return '/super-admin';
    }
}
