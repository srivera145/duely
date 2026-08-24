<?php

namespace Keel\App\Controllers;

use Keel\Core\Controller;
use Keel\Core\Env;
use Keel\Core\Request;
use Keel\Core\Response;

class LlmsTxtController extends Controller
{
    public function index(Request $request): never
    {
        $baseUrl = $this->baseUrl();
        $repoUrl = trim((string) Env::get('APP_REPO_URL', ''));

        $lines = [
            '# Duely',
            '',
            '> Duely follows up on overdue invoices for freelancers and small studios. It sends '
                . 'the reminders from the user\'s own mailbox rather than from a shared sending '
                . 'domain, and stops the moment a client replies, an invoice is paid, or a message '
                . 'bounces.',
            '',
            '## Pages',
            '',
            '- [Home](' . $baseUrl . '/): what Duely does and who it is for.',
            '- [How it works](' . $baseUrl . '/how-it-works): the four setup steps, the reminder '
                . 'ladder at days 3, 14 and 30, and every condition that stops a chase.',
            '- [Pricing](' . $baseUrl . '/pricing): Free (3 active chases, 1 mailbox), Solo $19/mo '
                . '(unlimited chases, 1 mailbox), Studio $39/mo (unlimited chases, 3 mailboxes, 5 '
                . 'seats). The first 50 paying accounts keep $19/mo permanently.',
            '- [Privacy and mailbox access](' . $baseUrl . '/privacy): exactly what Duely reads '
                . 'from a mailbox, what it stores, and what it never does.',
            '- [Terms](' . $baseUrl . '/terms)',
            '',
            '## Docs',
            '',
        ];

        foreach (DocsController::docsPages() as $page) {
            $slug = trim((string) ($page['slug'] ?? ''));
            $title = trim((string) ($page['title'] ?? ''));

            if ($slug === '' || $title === '') {
                continue;
            }

            $lines[] = '- [' . $title . '](' . $baseUrl . '/docs/' . $slug . ')';
        }

        $lines[] = '';
        $lines[] = '## Project';
        $lines[] = '';

        if ($repoUrl !== '') {
            $lines[] = '- [GitHub repository](' . $repoUrl . ')';
        }

        Response::raw(implode("\n", $lines) . "\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function baseUrl(): string
    {
        $baseUrl = trim((string) Env::get('APP_URL', ''));

        return $baseUrl !== '' ? rtrim($baseUrl, '/') : 'http://localhost';
    }
}
