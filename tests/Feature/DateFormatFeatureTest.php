<?php

declare(strict_types=1);

namespace Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Keel\App\Services\DateParser;
use Keel\App\Services\Dates;
use Keel\App\Services\TemplateRenderer;
use Tests\TestCase;

/**
 * How Duely writes a date: US convention, everywhere.
 *
 * **August 12, 2026** and **08/12/2026** — never "12 August 2026" and never
 * "12/08/2026". It was a mixture before: reminder emails and the marketing
 * pages wrote day-first, and the tables printed the raw ISO column.
 *
 * The email format matters most, because a client reads it and a wrong-looking
 * date in a demand for money is the kind of detail that makes somebody doubt the
 * sender.
 */
class DateFormatFeatureTest extends TestCase
{
    public function testTheThreeShapesAreUsOrder(): void
    {
        $date = '2026-08-12';

        self::assertSame('August 12, 2026', Dates::long($date));
        self::assertSame('Aug 12, 2026', Dates::medium($date));
        self::assertSame('08/12/2026', Dates::short($date));
    }

    public function testAMomentCarriesATwelveHourClock(): void
    {
        $moment = new DateTimeImmutable('2026-08-12 15:04:00', new DateTimeZone('UTC'));

        self::assertSame('August 12, 2026 at 3:04 PM', Dates::withTime($moment, 'UTC'));
        self::assertSame('08/12/2026 3:04 PM', Dates::shortWithTime($moment, 'UTC'));
    }

    public function testTheReminderAClientReadsSaysAugustTwelfth(): void
    {
        // {{due_date}} goes out in every reminder. This is the format the
        // product is judged on.
        $rendered = (new TemplateRenderer())->renderMessage(
            'Invoice {{invoice_number}}',
            'Invoice {{invoice_number}} was due on {{due_date}}.',
            TemplateRenderer::contextFor([
                'client_name' => 'Dana Whitfield',
                'number' => 'INV-1042',
                'amount_cents' => 320000,
                'currency' => 'USD',
                'due_date' => '2026-08-12',
                'days_overdue' => 18,
            ], 'Ada Lovelace')
        );

        self::assertStringContainsString('due on August 12, 2026', $rendered['text']);
        self::assertStringNotContainsString('12 August 2026', $rendered['text']);
        self::assertStringNotContainsString('2026-08-12', $rendered['text']);
    }

    public function testTheTemplateEditorsExampleMatchesWhatActuallyGoesOut(): void
    {
        // A preview that teaches a format the product does not use is worse than
        // no preview.
        self::assertSame('August 5, 2026', TemplateRenderer::tags()['due_date']['example']);
        self::assertSame('August 5, 2026', TemplateRenderer::sampleContext()['due_date']);
    }

    public function testADateOnlyValueIsNeverShiftedAcrossMidnightByATimezone(): void
    {
        // A due date is a day, not an instant. Rendering "2026-08-12" through a
        // western zone would show the 11th, and an invoice would appear to be
        // due a day earlier than it is.
        self::assertSame('August 12, 2026', Dates::long('2026-08-12'));
        self::assertSame('08/12/2026', Dates::short('2026-08-12'));
    }

    public function testAnEmptyOrZeroDateRendersAsNothingRatherThanNineteenSeventy(): void
    {
        foreach ([null, '', '0000-00-00', '0000-00-00 00:00:00'] as $value) {
            self::assertSame('', Dates::long($value), var_export($value, true));
            self::assertSame('', Dates::short($value), var_export($value, true));
        }
    }

    // -------------------------------------- reading agrees with writing

    public function testAnAmbiguousImportedDateIsReadMonthFirst(): void
    {
        // The other half of the change: what Duely renders as 08/12/2026 must be
        // what it reads back from a spreadsheet as 08/12/2026. If the two
        // disagreed, an exported-then-reimported file would silently move every
        // due date.
        $parsed = DateParser::parse('08/12/2026');

        self::assertNotNull($parsed);
        self::assertSame('2026-08-12', $parsed->format('Y-m-d'));
        self::assertSame('08/12/2026', Dates::short($parsed));
    }

    public function testADayFirstFileCanStillBeImportedByChoosingThatLocale(): void
    {
        // The default is US, but the wizard still offers the choice, and a user
        // with a European export must not be forced to edit the file.
        $parsed = DateParser::parse('08/12/2026', DateParser::LOCALE_DMY);

        self::assertNotNull($parsed);
        self::assertSame('2026-12-08', $parsed->format('Y-m-d'));
    }

    // ----------------------------------- no day-first format survives anywhere

    public function testNoViewOrServiceStillWritesADayFirstDate(): void
    {
        // Searched rather than remembered. The formats below all put the day
        // before the month, and any one of them reappearing is the bug coming
        // back on a screen nobody thought to check.
        $offenders = [];

        $patterns = ["'j F Y'", "'j M Y'", "'d/m/Y'", "'d-m-Y'", "'D j M", "'l j F"];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src')
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        foreach (glob(dirname(__DIR__, 2) . '/views/*/*.php') ?: [] as $file) {
            $files[] = $file;
        }

        foreach ($files as $file) {
            // The class that defines the formats names them by definition.
            if (basename($file) === 'Dates.php') {
                continue;
            }

            $contents = (string) file_get_contents($file);

            foreach ($patterns as $pattern) {
                if (str_contains($contents, $pattern)) {
                    $offenders[] = basename($file) . ': ' . $pattern;
                }
            }
        }

        self::assertSame([], $offenders, 'Day-first date format: ' . implode(', ', $offenders));
    }
}
