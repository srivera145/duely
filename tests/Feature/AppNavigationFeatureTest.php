<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Client;
use Keel\App\Models\Invoice;
use Keel\App\Models\Sequence;
use Keel\App\Services\TenantContext;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The product navigation.
 *
 * `$showNav` defaulted to false and only the dashboard set it, so every other
 * signed-in page was a dead end: from the invoice list there was no way to
 * reach Clients without going back through the dashboard first.
 *
 * These tests exist because that bug was invisible to every existing test. Each
 * page rendered, returned 200, and contained its own content. Nothing asserted
 * that a user could get anywhere from it.
 */
class AppNavigationFeatureTest extends TestCase
{
    private int $tenantId;
    private int $invoiceId;
    private int $clientId;
    private int $sequenceId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = $this->createUser(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $this->tenantId = TenantContext::forUser((int) $user['id']);
        $this->sequenceId = (int) Sequence::defaultSequence($this->tenantId)['id'];

        $this->clientId = Client::findOrCreate($this->tenantId, 'dana@client.test', [
            'name' => 'Dana Whitfield',
            'company' => 'Whitfield & Partners',
            'timezone' => 'UTC',
        ]);

        $this->invoiceId = Invoice::create($this->tenantId, [
            'client_id' => $this->clientId,
            'number' => 'INV-3001',
            'amount_cents' => 320000,
            'currency' => 'USD',
            'due_date' => '2026-08-01',
        ]);
    }

    /**
     * Every destination in the bar. If one of these ever 404s, the nav is
     * pointing somewhere that does not exist.
     */
    public static function navDestinations(): array
    {
        return [
            'Dashboard' => ['/dashboard'],
            'Invoices' => ['/invoices'],
            'Clients' => ['/clients'],
            'Sequences' => ['/sequences'],
            'Email' => ['/settings/email'],
            'Payments' => ['/settings/payments'],
        ];
    }

    /**
     * Every signed-in page, including the ones not in the bar. A page that
     * renders nav is a page a user can leave.
     */
    public static function signedInPages(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'invoice list' => ['/invoices'],
            'new invoice' => ['/invoices/new'],
            'invoice import' => ['/invoices/import'],
            'client list' => ['/clients'],
            'new client' => ['/clients/new'],
            'sequence list' => ['/sequences'],
            'email settings' => ['/settings/email'],
            'payment settings' => ['/settings/payments'],
            'plans' => ['/billing/upgrade'],
            'api tokens' => ['/settings/api-tokens'],
            'onboarding' => ['/onboarding'],
        ];
    }

    // ------------------------------------------- self-check: nav is everywhere

    #[DataProvider('signedInPages')]
    public function testEverySignedInPageCarriesTheNav(string $path): void
    {
        $this->signIn();

        $response = $this->get($path);

        self::assertSame(200, $response->status, $path . ' did not render.');

        foreach (array_keys(self::navDestinations()) as $label) {
            self::assertStringContainsString(
                '>' . $label . '</a>',
                $response->body,
                $path . ' has no link to ' . $label . '.'
            );
        }
    }

    #[DataProvider('navDestinations')]
    public function testEveryNavDestinationActuallyExists(string $path): void
    {
        $this->signIn();

        self::assertSame(200, $this->get($path)->status, $path . ' is linked but does not render.');
    }

    public function testChildPagesOfASectionCarryTheNavToo(): void
    {
        $this->signIn();

        foreach ([
            '/invoices/' . $this->invoiceId,
            '/invoices/' . $this->invoiceId . '/edit',
            '/clients/' . $this->clientId,
            '/sequences/' . $this->sequenceId,
        ] as $path) {
            $body = $this->get($path)->body;

            self::assertStringContainsString('aria-label="Main"', $body, $path . ' has no nav.');
            self::assertStringContainsString('href="/clients"', $body, $path . ' cannot reach Clients.');
        }
    }

    // ---------------------------------------------- self-check: active section

    public function testTheCurrentSectionIsMarkedWithAriaCurrent(): void
    {
        $this->signIn();

        $body = $this->get('/invoices')->body;

        self::assertMatchesRegularExpression(
            '/<a href="\/invoices"[^>]*aria-current="page"/',
            $body,
            'The invoice list does not mark Invoices as the current page.'
        );
    }

    public function testAChildPageMarksItsParentSectionActive(): void
    {
        $this->signIn();

        // /invoices/12 lights up Invoices. Derived from the path in the partial,
        // so a new child page gets this for free rather than having to remember
        // to pass a section name.
        $body = $this->get('/invoices/' . $this->invoiceId)->body;

        self::assertMatchesRegularExpression(
            '/<a href="\/invoices"[^>]*aria-current="page"/',
            $body,
            '/invoices/{id} does not mark Invoices active.'
        );

        $body = $this->get('/sequences/' . $this->sequenceId)->body;

        self::assertMatchesRegularExpression(
            '/<a href="\/sequences"[^>]*aria-current="page"/',
            $body,
            '/sequences/{id} does not mark Sequences active.'
        );
    }

    public function testExactlyOneSectionIsActiveAtATime(): void
    {
        $this->signIn();

        // Two lit links would mean the prefix matching is too loose. The count
        // is two rather than one because the bar renders twice — once for
        // desktop, once inside the mobile disclosure — and CSS shows one.
        foreach (['/dashboard', '/invoices', '/clients', '/sequences', '/settings/email', '/settings/payments'] as $path) {
            self::assertSame(
                2,
                substr_count($this->get($path)->body, 'aria-current="page"'),
                $path . ' lit the wrong number of nav links.'
            );
        }
    }

    public function testASettingsChildPathDoesNotLightASiblingSetting(): void
    {
        $this->signIn();

        // /settings/payments/callback is a child of Payments, not of Email.
        $body = $this->get('/settings/payments')->body;

        self::assertMatchesRegularExpression('/<a href="\/settings\/payments"[^>]*aria-current="page"/', $body);
        self::assertDoesNotMatchRegularExpression('/<a href="\/settings\/email"[^>]*aria-current="page"/', $body);
    }

    // ------------------------------------------ self-check: works without JS

    public function testTheNavIsPlainLinksAndTheMobileMenuIsADetailsElement(): void
    {
        $this->signIn();

        $body = $this->get('/dashboard')->body;

        // A disclosure the browser opens by itself. A button wired to a class
        // toggle stops working the moment the bundle fails to load, and a nav
        // that needs script is a nav that can strand somebody on a train.
        self::assertMatchesRegularExpression('/<details[^>]*class="[^"]*md:hidden/', $body);
        self::assertStringContainsString('<summary', $body);

        // And nothing in the bar is an onclick or a href="#".
        preg_match('/<div class="mb-6 border-b.*?<\/details>/s', $body, $matches);
        $nav = $matches[0] ?? '';

        self::assertNotSame('', $nav, 'The nav bar was not found.');
        self::assertStringNotContainsString('onclick', $nav);
        self::assertStringNotContainsString('href="#"', $nav);
    }

    // ----------------------------------- self-check: hierarchy is not misstated

    public function testSequencesDoesNotClaimToBeAChildOfInvoices(): void
    {
        $this->signIn();

        // Sequences are a peer of Invoices, not a child. The old "Back to
        // invoices" said otherwise, and with a real nav it is also redundant.
        self::assertStringNotContainsString('Back to invoices', $this->get('/sequences')->body);
    }

    public function testGenuineParentLinksSurvive(): void
    {
        $this->signIn();

        // These are not navigation, they are "the record you came from", and
        // the nav's section link does not say the same thing.
        self::assertStringContainsString(
            'Back to sequences',
            $this->get('/sequences/' . $this->sequenceId)->body
        );
        self::assertStringContainsString('Back to invoices', $this->get('/invoices/new')->body);
        self::assertStringContainsString('Back to invoices', $this->get('/invoices/import')->body);
    }

    public function testTheNavReplacedTheBackToDashboardLinks(): void
    {
        $this->signIn();

        foreach (['/settings/email', '/settings/payments', '/billing/upgrade'] as $path) {
            self::assertStringNotContainsString(
                'Back to dashboard',
                $this->get($path)->body,
                $path . ' still carries a back-link the nav replaced.'
            );
        }
    }

    // ----------------------------------- self-check: no inherited Keel styling

    public function testNoSignedInViewUsesKeelsGreyLiterals(): void
    {
        // text-gray-500 and friends came from the starter kit and ignore the
        // theme entirely, so those pages rendered as white cards with near-black
        // text in dark mode — visibly a different product.
        $offenders = [];

        foreach ([
            'views/settings',
            'views/super-admin',
            'views/onboarding',
            'views/billing',
            'views/clients',
            'views/invoices',
            'views/sequences',
            'views/dashboard',
            'views/partials',
        ] as $directory) {
            foreach (glob(dirname(__DIR__, 2) . '/' . $directory . '/*.php') ?: [] as $file) {
                $contents = (string) file_get_contents($file);

                foreach (['text-gray-500', 'text-gray-900', 'text-gray-600', 'bg-gray-50', 'bg-white'] as $literal) {
                    // Whole utility only. `bg-gray-500/10` is the neutral
                    // status badge -- an opacity over whatever is behind it,
                    // which works in both themes -- and a substring match would
                    // report it as `bg-gray-50`.
                    if (preg_match('/' . preg_quote($literal, '/') . '(?![-\/0-9])/', $contents) === 1) {
                        $offenders[] = basename(dirname($file)) . '/' . basename($file) . ': ' . $literal;
                    }
                }
            }
        }

        self::assertSame([], $offenders, implode(', ', $offenders));
    }

    // ------------------------------------------------------------------ helper

    private function signIn(): void
    {
        \Keel\Core\Session::put('user_id', $this->userIdForTenant());
        \Keel\Core\Session::put('user_email', 'ada@studio.test');
        \Keel\Core\Session::put('organization_id', $this->tenantId);
        \Keel\Core\Auth::setUserId(null);
    }

    private function userIdForTenant(): int
    {
        $statement = \Keel\Core\Database::connection()->prepare(
            'SELECT id FROM users WHERE email = ? LIMIT 1'
        );
        $statement->execute(['ada@studio.test']);

        return (int) $statement->fetchColumn();
    }
}
