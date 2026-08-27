<?php

namespace Keel\App\Controllers\SuperAdmin;

use Keel\App\Services\SupportAccessLog;
use Keel\Core\Database;
use Keel\Core\Request;
use Keel\Core\Session;

/**
 * Tier four: opening a customer's account to answer their question.
 *
 * Two rules govern this file and neither bends.
 *
 * **A reason first.** Nothing opens without one of at least ten characters, and
 * it is stored with the record and shown to the customer. The point is not the
 * string; it is that stating a purpose out loud, into something the subject will
 * read, is what separates support from browsing.
 *
 * **Credentials are never decrypted.** The mailbox view below selects columns
 * by name and `smtp_password_encrypted`, `imap_password_encrypted` and the OAuth
 * token columns are not among them. Nothing here calls `Crypto::decrypt`, and a
 * test asserts that no file under the panel does. The privacy page promises
 * this in as many words, and it is the one promise a user actually feels: they
 * handed over an email password.
 */
class SupportController extends BaseController
{
    /**
     * Columns the support view may read from `email_accounts`.
     *
     * An allowlist rather than `SELECT *`. With a wildcard, adding a column to
     * the table quietly adds it to this screen, and the next encrypted column
     * somebody adds would land here without anybody deciding it should.
     */
    private const MAILBOX_COLUMNS = [
        'id', 'tenant_id', 'provider', 'from_name', 'from_email', 'reply_to',
        'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username',
        'imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_folder',
        'imap_last_error', 'imap_last_polled_at',
        'status', 'last_verified_at', 'last_error', 'is_default', 'created_at', 'updated_at',
    ];

    /**
     * GET /super-admin/support — pick an account, state a reason.
     */
    public function index(Request $request): void
    {
        $this->panel('super-admin.support', 'support.index', [
            'title' => 'Support — Duely',
            'organizations' => $this->organizations(),
            'error' => $request->query['error'] ?? null,
        ]);
    }

    /**
     * POST /super-admin/support/open — record the reason, then open.
     *
     * A POST because it is not a page you land on; it is an act with a stated
     * purpose, and it is recorded before anything is shown.
     */
    public function open(Request $request): never
    {
        $tenantId = (int) $request->input('tenant_id', 0);
        $reason = trim((string) $request->input('reason', ''));

        if (!SupportAccessLog::isUsableReason($reason)) {
            $this->redirect('/super-admin/support?error=' . rawurlencode(
                'Give a reason of at least ' . SupportAccessLog::MIN_REASON_LENGTH . ' characters.'
            ));
        }

        if ($this->organization($tenantId) === null) {
            $this->redirect('/super-admin/support?error=' . rawurlencode('No such account.'));
        }

        // Held for the duration so the reason travels with each page of this
        // visit, rather than being asked once and forgotten.
        Session::put('support_reason_' . $tenantId, $reason);

        $this->redirect('/super-admin/support/' . $tenantId);
    }

    /**
     * GET /super-admin/support/{id} — the account, without its contents.
     */
    public function show(Request $request, string $id): void
    {
        $tenantId = (int) $id;
        $organization = $this->organization($tenantId);

        if ($organization === null) {
            $this->redirect('/super-admin/support');
        }

        $reason = (string) Session::get('support_reason_' . $tenantId, '');

        // No reason in the session means somebody navigated straight here.
        // Back to the front door to state one.
        if (!SupportAccessLog::isUsableReason($reason)) {
            $this->redirect('/super-admin/support?error=' . rawurlencode(
                'State a reason before opening an account.'
            ));
        }

        $this->audit->recordAction('support.account_opened', $tenantId, [
            'organization' => (string) $organization['name'],
        ], $reason);

        $this->view('super-admin.support-account', [
            'title' => 'Support — Duely',
            'superAdminNav' => $this->nav(),
            'organization' => $organization,
            'reason' => $reason,
            'members' => $this->members($tenantId),
            'mailboxes' => $this->mailboxes($tenantId),
            'counts' => $this->counts($tenantId),
            'access' => (new SupportAccessLog())->forTenant($tenantId, 20),
        ]);
    }

    /**
     * GET /super-admin/audit — the trail itself.
     */
    public function audit(Request $request): void
    {
        $tenantId = isset($request->query['tenant_id']) ? (int) $request->query['tenant_id'] : null;

        $this->panel('super-admin.audit', 'audit.view', [
            'title' => 'Audit — Duely',
            'entries' => (new SupportAccessLog())->recent(200, $tenantId ?: null),
            'filterTenantId' => $tenantId,
        ]);
    }

    // -------------------------------------------------------------- internals

    /**
     * Mailbox configuration, credentials excluded by construction.
     *
     * The allowlist above is the whole mechanism. There is no branch here that
     * could include a password column, no flag that widens it, and no debug
     * mode. That is deliberate: a conditional would be a thing somebody could
     * flip in an emergency at 2am.
     */
    private function mailboxes(int $tenantId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . implode(', ', self::MAILBOX_COLUMNS) . '
             FROM email_accounts WHERE tenant_id = ? ORDER BY is_default DESC, id ASC'
        );
        $statement->execute([$tenantId]);

        return $statement->fetchAll() ?: [];
    }

    /**
     * How much of what, never what it says.
     *
     * Counts answer nearly every support question -- "are my reminders going
     * out", "did anything send" -- without putting a client's name or an
     * invoice amount on a screen that has no business showing them.
     */
    private function counts(int $tenantId): array
    {
        $one = static function (string $sql, array $bindings) use ($tenantId): int {
            $statement = Database::connection()->prepare($sql);
            $statement->execute(array_merge([$tenantId], $bindings));

            return (int) $statement->fetchColumn();
        };

        return [
            'clients' => $one('SELECT COUNT(*) FROM clients WHERE tenant_id = ?', []),
            'invoices_open' => $one('SELECT COUNT(*) FROM invoices WHERE tenant_id = ? AND status = ?', ['open']),
            'invoices_paid' => $one('SELECT COUNT(*) FROM invoices WHERE tenant_id = ? AND status = ?', ['paid']),
            'chases_live' => $one(
                'SELECT COUNT(*) FROM chases WHERE tenant_id = ? AND status IN (?, ?)',
                ['scheduled', 'active']
            ),
            'chases_paused' => $one('SELECT COUNT(*) FROM chases WHERE tenant_id = ? AND status = ?', ['paused']),
            'messages_sent' => $one('SELECT COUNT(*) FROM chase_messages WHERE tenant_id = ? AND status = ?', ['sent']),
            'messages_failed' => $one(
                'SELECT COUNT(*) FROM chase_messages WHERE tenant_id = ? AND status IN (?, ?)',
                ['failed', 'bounced']
            ),
        ];
    }

    private function members(int $tenantId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, name, email, role, is_super_admin, created_at
             FROM users WHERE organization_id = ? ORDER BY id ASC'
        );
        $statement->execute([$tenantId]);

        return $statement->fetchAll() ?: [];
    }

    private function organizations(): array
    {
        return Database::connection()
            ->query('SELECT id, name, slug, plan, disabled_at, created_at FROM organizations ORDER BY name ASC')
            ->fetchAll() ?: [];
    }

    private function organization(int $tenantId): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM organizations WHERE id = ? LIMIT 1');
        $statement->execute([$tenantId]);

        return $statement->fetch() ?: null;
    }
}
