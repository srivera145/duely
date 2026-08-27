<?php

namespace Keel\App\Controllers;

use Keel\App\Models\User;
use Keel\App\Services\Clock;
use Keel\App\Services\DashboardMetrics;
use Keel\App\Services\MoneyParser;
use Keel\App\Services\RelativeTime;
use Keel\App\Services\Timezones;
use Keel\App\Services\TenantContext;
use Keel\Core\Auth;
use Keel\Core\Controller;
use Keel\Core\Request;

/**
 * The one screen that answers "what is being chased, and what came back?"
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardMetrics $metrics = new DashboardMetrics())
    {
    }

    /**
     * GET /dashboard
     */
    public function index(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $user = User::find((int) Auth::id());
        $now = Clock::now();

        // The workspace's zone. This used to read users.timezone, a column
        // that does not exist, so it silently returned UTC for everyone.
        $timezone = Timezones::forWorkspace($tenantId);

        $this->view('dashboard.index', [
            'title' => 'Dashboard - Duely',
            'metaDescription' => 'What Duely is chasing right now, and what has come back.',
            'user' => $user,
            'cards' => $this->metrics->cards($tenantId, $now),
            'chases' => array_map(
                fn (array $row): array => $this->presentChase($row, $now, $timezone),
                $this->metrics->activeChases($tenantId, $now)
            ),
            'attention' => $this->metrics->needsAttention($tenantId),
            'timezone' => $timezone,
            'hasMailbox' => \Keel\App\Models\EmailAccount::sendingAccount($tenantId) !== null,
            // The wizard stays reachable from here, including after a skip,
            // so "come back to it later" is a real offer rather than a phrase.
            'onboarding' => (new \Keel\App\Services\OnboardingService())->progress($tenantId),
        ]);
    }

    /**
     * GET /api/dashboard — the same figures as JSON, for live refresh.
     */
    public function metrics(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $now = Clock::now();
        $timezone = Timezones::forWorkspace($tenantId);

        $this->json([
            'cards' => $this->metrics->cards($tenantId, $now),
            'chases' => array_map(
                fn (array $row): array => $this->presentChase($row, $now, $timezone),
                $this->metrics->activeChases($tenantId, $now)
            ),
        ]);
    }

    // -------------------------------------------------------------- internals

    /**
     * Shape one active chase for the table, with money and times pre-rendered
     * so the browser never has to do currency or timezone arithmetic.
     */
    private function presentChase(array $row, \DateTimeImmutable $now, string $timezone): array
    {
        $nextSendAt = Clock::fromDatabase($row['next_send_at']);
        $daysOverdue = (int) $row['days_overdue'];

        return [
            'chase_id' => (int) $row['chase_id'],
            'invoice_id' => (int) $row['invoice_id'],
            'number' => (string) $row['number'],
            'client_name' => (string) $row['client_name'],
            'client_email' => (string) $row['client_email'],
            'client_company' => (string) ($row['client_company'] ?? ''),
            'amount' => MoneyParser::format((int) $row['amount_cents'], (string) $row['currency']),
            'amount_cents' => (int) $row['amount_cents'],
            'due_date' => (string) $row['due_date'],
            'days_overdue' => $daysOverdue,
            'step' => (int) $row['sent_count'],
            'total_steps' => (int) $row['total_steps'],
            'status' => (string) $row['chase_status'],
            'status_label' => $this->statusLabel($row),
            'paused_reason' => $row['paused_reason'],
            // Rendered in the viewer's own timezone, as a phrase rather than a
            // timestamp — "in 2 days" is the thing someone actually wants.
            'next_send_at' => $row['next_send_at'],
            'next_send_relative' => $nextSendAt === null
                ? null
                : RelativeTime::phrase($nextSendAt, $now),
            'next_send_local' => $nextSendAt === null
                ? null
                : RelativeTime::inTimezone($nextSendAt, $timezone),
            'last_sent_relative' => ($lastSent = Clock::fromDatabase($row['last_sent_at'])) === null
                ? null
                : RelativeTime::phrase($lastSent, $now),
            'last_reply_snippet' => $row['last_reply_snippet'],
            'needs_attention' => $row['chase_status'] === \Keel\App\Models\Chase::STATUS_PAUSED,
        ];
    }

    private function statusLabel(array $row): string
    {
        if ($row['chase_status'] !== \Keel\App\Models\Chase::STATUS_PAUSED) {
            return match ($row['chase_status']) {
                \Keel\App\Models\Chase::STATUS_SCHEDULED => 'Scheduled',
                \Keel\App\Models\Chase::STATUS_ACTIVE => 'Chasing',
                default => ucfirst((string) $row['chase_status']),
            };
        }

        return match ($row['paused_reason']) {
            \Keel\App\Models\Chase::PAUSE_CLIENT_REPLIED => 'Client replied',
            \Keel\App\Models\Chase::PAUSE_INVOICE_PAID => 'Marked paid',
            \Keel\App\Models\Chase::PAUSE_BOUNCED => 'Email bounced',
            \Keel\App\Models\Chase::PAUSE_NEEDS_REAUTH => 'Mailbox needs reconnecting',
            default => 'Paused',
        };
    }

}
