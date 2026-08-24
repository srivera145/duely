<?php

namespace Keel\App\Controllers;

use Keel\App\Services\PlanService;
use Keel\Core\Controller;
use Keel\Core\Env;
use Keel\Core\Request;
use Throwable;

/**
 * The public site.
 *
 * These are routes rather than files in public_html because Keel's rewrite
 * rule serves a real file directly and skips the router — which would mean no
 * CSRF token for the waitlist form, no sitemap registration, and no shared
 * head. Same URLs either way; this way they go through the application.
 */
class MarketingController extends Controller
{
    /**
     * The questions on the landing page, and the ones the FAQPage markup
     * describes. Those have to be the same content — markup that answers
     * questions the page does not ask is dropped, and rightly — so they are
     * one array rendered in two places.
     */
    private const FAQ = [
        'Do I need accounting software?' =>
            'No. Duely is built for people who keep invoices in a spreadsheet. Import a CSV, or '
            . 'add them one at a time.',
        'Does Duely email my clients from its own address?' =>
            'No. Duely sends through your mailbox, so the reminder comes from your address, lands '
            . 'in your sent items, and replies come back to you. Your client sees a message from '
            . 'you, not from a tool.',
        'What happens if a client replies?' =>
            'The chase stops before the next reminder is written. Duely watches the mailbox for '
            . 'replies and pauses the sequence as soon as one arrives, so nobody gets a final '
            . 'notice after saying the cheque went out on Friday.',
        'What does Duely actually read?' =>
            'Message headers, and the first few hundred characters of a reply so you can see what '
            . 'was said without opening your inbox. It does not store message bodies, never marks '
            . 'anything as read, never deletes and never moves anything.',
        'What if I want to send one myself?' =>
            'Send it. Duely notices the reply in the thread and steps out of the way.',
        'What does it cost?' =>
            'Free for three invoices being chased at once. $19 a month for unlimited, and the '
            . 'first fifty accounts keep that price for as long as they stay.',
    ];

    /**
     * The setup steps, shown on /how-it-works and described by its HowTo
     * markup. `summary` is the one line the markup carries; `body` is the page.
     */
    private const SETUP_STEPS = [
        [
            'title' => 'Connect your email',
            'summary' => 'Duely sends through your own mailbox, so reminders come from your '
                . 'address and replies land in your inbox.',
            'body' => 'Duely needs your mailbox because the reminders come from you. You give it '
                . 'an app password — the kind Gmail and Outlook generate for exactly this '
                . '— and it tests the connection before saving anything. If the handshake '
                . 'fails, you get a sentence explaining why, not a code.',
        ],
        [
            'title' => 'Add your invoices',
            'summary' => 'Import a CSV from your spreadsheet, or add them one at a time.',
            'body' => 'Export a CSV from wherever you keep them and drop it in. Duely shows you '
                . 'the first ten rows, lets you match up the columns, and tells you line by line '
                . 'which rows it could not read. One bad row never fails the whole file.',
        ],
        [
            'title' => 'Read the reminders',
            'summary' => 'A polite nudge at three days, a firmer note at fourteen, a final message '
                . 'at thirty. Edit any of it.',
            'body' => 'Three messages are already written: a nudge at three days past due, a '
                . 'firmer note at fourteen, a last one at thirty. Edit any of them, or leave them '
                . 'alone — most people leave them alone.',
        ],
        [
            'title' => 'Turn on chasing',
            'summary' => 'Duely follows up on schedule and stops the moment a client replies or '
                . 'the invoice is paid.',
            'body' => "Duely sends during working hours in your client's timezone, skips weekends, "
                . 'and spaces messages out so a morning of reminders does not look like a mail '
                . 'merge.',
        ],
    ];

    public function index(Request $request): void
    {
        $this->view('marketing.index', [
            'title' => 'Duely - Get paid without writing the awkward follow-up',
            'metaDescription' => 'Duely chases your overdue invoices from your own inbox, and stops the '
                . 'moment a client replies or pays. Built for freelancers and small studios who track '
                . 'invoices in a spreadsheet.',
            'jsonLd' => [$this->softwareApplication(), $this->faq()],
            'founding' => $this->foundingAvailability(),
            // The same questions the FAQPage markup describes. Structured data
            // has to be content a visitor can actually read on the page, so
            // there is one array and the view renders it.
            'faq' => self::FAQ,
        ]);
    }

    public function howItWorks(Request $request): void
    {
        $this->view('marketing.how-it-works', [
            'title' => 'How Duely works - polite, then firm, then done',
            'metaDescription' => 'Duely sends a nudge at three days, a firmer note at fourteen, and a '
                . 'final message at thirty — from your own inbox, and never after a client has replied.',
            'jsonLd' => [$this->howTo()],
            'steps' => self::SETUP_STEPS,
        ]);
    }

    public function pricing(Request $request): void
    {
        $this->view('marketing.pricing', [
            'title' => 'Duely pricing - free to start, $19 a month',
            'metaDescription' => 'Free for three active chases. $19 a month for unlimited. The first '
                . 'fifty accounts keep that price for as long as they stay.',
            'jsonLd' => [$this->softwareApplication()],
            'founding' => $this->foundingAvailability(),
        ]);
    }

    public function privacy(Request $request): void
    {
        $this->view('marketing.privacy', [
            'title' => 'Privacy and what Duely does with your mailbox',
            'metaDescription' => 'Exactly what Duely reads, what it stores, and what it never touches. '
                . 'Read-only mailbox access, snippets only, credentials encrypted at rest.',
            'updatedOn' => '2026-08-24',
        ]);
    }

    public function terms(Request $request): void
    {
        $this->view('marketing.terms', [
            'title' => 'Terms of service - Duely',
            'metaDescription' => 'The terms you agree to when you use Duely.',
            'updatedOn' => '2026-08-24',
        ]);
    }

    // -------------------------------------------------------------- internals

    /**
     * How many founding places are left, if the database is reachable.
     *
     * The landing page must render for someone who has never signed in, on a
     * box where the database is down. A number that cannot be read is simply
     * not shown rather than a 500.
     */
    private function foundingAvailability(): ?array
    {
        try {
            return (new PlanService())->foundingAvailability();
        } catch (Throwable) {
            return null;
        }
    }

    private function baseUrl(): string
    {
        $url = trim((string) Env::get('APP_URL', ''));

        return $url !== '' ? rtrim($url, '/') : 'http://localhost';
    }

    /**
     * The SoftwareApplication node. Prices are the real ones; an offer that
     * disagrees with the pricing page is worse than no offer at all.
     */
    private function softwareApplication(): array
    {
        $baseUrl = $this->baseUrl();

        return [
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => 'Duely',
            'url' => $baseUrl . '/',
            'applicationCategory' => 'BusinessApplication',
            'applicationSubCategory' => 'Invoicing',
            'operatingSystem' => 'Web',
            'description' => 'Duely follows up on overdue invoices from your own inbox and stops the '
                . 'moment a client replies or pays.',
            'softwareVersion' => '1.0',
            'offers' => [
                [
                    '@type' => 'Offer',
                    'name' => 'Free',
                    'price' => '0',
                    'priceCurrency' => 'USD',
                    'description' => 'Three invoices being chased at once, one connected mailbox.',
                    'url' => $baseUrl . '/pricing',
                ],
                [
                    '@type' => 'Offer',
                    'name' => 'Solo',
                    'price' => '19',
                    'priceCurrency' => 'USD',
                    'description' => 'Unlimited invoices being chased, one connected mailbox.',
                    'url' => $baseUrl . '/pricing',
                ],
                [
                    '@type' => 'Offer',
                    'name' => 'Studio',
                    'price' => '39',
                    'priceCurrency' => 'USD',
                    'description' => 'Unlimited invoices being chased, three mailboxes, five seats.',
                    'url' => $baseUrl . '/pricing',
                ],
            ],
            'featureList' => [
                'Sends reminders from your own email address',
                'Stops automatically when a client replies',
                'Stops automatically when an invoice is paid',
                'Detects bounces and flags bad addresses',
                'Imports invoices from a CSV',
            ],
        ];
    }

    /**
     * The questions people actually ask before handing over a mailbox.
     */
    private function faq(): array
    {
        $answers = self::FAQ;

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(
                static fn (string $question, string $answer): array => [
                    '@type' => 'Question',
                    'name' => $question,
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer],
                ],
                array_keys($answers),
                array_values($answers)
            ),
        ];
    }

    private function howTo(): array
    {
        // The same four steps the page shows, so the markup describes this page
        // rather than a page nobody wrote.
        $steps = [];

        foreach (self::SETUP_STEPS as $step) {
            $steps[$step['title']] = $step['summary'];
        }

        $position = 0;

        return [
            '@context' => 'https://schema.org',
            '@type' => 'HowTo',
            'name' => 'How to chase an overdue invoice with Duely',
            'totalTime' => 'PT10M',
            'step' => array_map(
                static function (string $name, string $text) use (&$position): array {
                    return [
                        '@type' => 'HowToStep',
                        'position' => ++$position,
                        'name' => $name,
                        'text' => $text,
                    ];
                },
                array_keys($steps),
                array_values($steps)
            ),
        ];
    }
}
