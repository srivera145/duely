<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Sequence;
use Keel\App\Models\SequenceStep;
use Keel\App\Services\OrganizationService;
use Keel\App\Services\SequenceSeeder;
use Keel\App\Services\TemplateRenderer;
use Keel\App\Services\TenantContext;
use Keel\Core\Database;
use Tests\TestCase;

/**
 * The default ladder and the template renderer.
 *
 * Two things must hold above all: a client never receives an unrendered
 * {{tag}}, and a value containing an ampersand or a quote is escaped exactly
 * once in the HTML part and not at all in the plain-text part.
 */
class SequenceTemplateFeatureTest extends TestCase
{
    // ------------------------------------------------- seeding on signup

    public function testANewTenantIsSeededWithTheDefaultLadder(): void
    {
        $user = $this->createUser(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $organization = (new OrganizationService())->create('Ada Studio', (int) $user['id']);
        $tenantId = (int) $organization['id'];

        self::assertSame(1, Sequence::count($tenantId), 'signup did not seed a sequence');

        $sequence = Sequence::defaultSequence($tenantId);
        self::assertNotNull($sequence);
        self::assertSame(1, (int) $sequence['is_default']);
        self::assertSame(1, (int) $sequence['is_active']);
    }

    public function testTheSeededLadderIsThreeStepsAtThreeFourteenAndThirty(): void
    {
        $tenantId = $this->seededTenant();
        $steps = SequenceStep::forSequence($tenantId, (int) Sequence::defaultSequence($tenantId)['id']);

        self::assertCount(3, $steps);
        self::assertSame([3, 14, 30], array_map(static fn (array $s): int => (int) $s['offset_days'], $steps));
        self::assertSame(['polite', 'firm', 'final'], array_map(static fn (array $s): string => $s['tone'], $steps));
        self::assertSame(1, (int) $steps[2]['is_final'], 'the last step must be marked final');
    }

    public function testTheSendWindowDefaultsToOfficeHoursWithWeekendsSkipped(): void
    {
        $tenantId = $this->seededTenant();
        $sequence = Sequence::defaultSequence($tenantId);

        self::assertSame('09:00:00', $sequence['send_window_start']);
        self::assertSame('16:00:00', $sequence['send_window_end']);
        self::assertSame(1, (int) $sequence['skip_weekends']);
    }

    public function testASoloUserWithoutAnOrganizationIsAlsoSeeded(): void
    {
        $user = $this->createUser(['email' => 'solo@studio.test', 'name' => 'Solo Freelancer']);

        // No organization yet; TenantContext provisions one on first use.
        $tenantId = TenantContext::forUser((int) $user['id']);

        self::assertSame(1, Sequence::count($tenantId));
        self::assertCount(3, SequenceStep::forSequence($tenantId, (int) Sequence::defaultSequence($tenantId)['id']));
    }

    public function testSeedingIsIdempotentAndTenantScoped(): void
    {
        $first = $this->seededTenant('One Studio', 'one@studio.test');
        $second = $this->seededTenant('Two Studio', 'two@studio.test');

        self::assertNull(SequenceSeeder::seed($first), 'a second seed created another ladder');
        self::assertSame(1, Sequence::count($first));

        self::assertNotSame($first, $second);
        self::assertNotSame(
            Sequence::defaultSequence($first)['id'],
            Sequence::defaultSequence($second)['id'],
            'the two tenants share a sequence row'
        );
    }

    // ------------------------------------------- self-check: full render

    public function testAllThreeSeededStepsRenderWithNoUnrenderedTags(): void
    {
        $tenantId = $this->seededTenant();
        $steps = SequenceStep::forSequence($tenantId, (int) Sequence::defaultSequence($tenantId)['id']);

        $renderer = new TemplateRenderer();
        $context = TemplateRenderer::sampleContext('Ada Lovelace');

        foreach ($steps as $index => $step) {
            $result = $renderer->renderMessage($step['subject_template'], $step['body_template'], $context);
            $label = 'step ' . ($index + 1);

            self::assertSame([], $result['warnings'], $label . ' used an unknown tag');

            foreach (['subject', 'text', 'html'] as $part) {
                self::assertNotSame('', trim($result[$part]), $label . ' produced an empty ' . $part);
                self::assertStringNotContainsString('{{', $result[$part], $label . ' left a tag in the ' . $part);
                self::assertStringNotContainsString('}}', $result[$part], $label . ' left a tag in the ' . $part);
            }

            // Both parts are always produced, never one or the other.
            self::assertNotSame('', $result['text']);
            self::assertNotSame('', $result['html']);

            // The values actually landed.
            self::assertStringContainsString('INV-1042', $result['text']);
            self::assertStringContainsString('Ada Lovelace', $result['text']);
        }
    }

    // ------------------------------------------ self-check: escaping once

    public function testAnAmpersandIsEscapedOnceInHtmlAndNotAtAllInText(): void
    {
        $renderer = new TemplateRenderer();
        $context = TemplateRenderer::sampleContext();

        // The sample company is "Whitfield & Partners" — the canary.
        $result = $renderer->renderMessage('About {{company}}', 'Hello from {{company}}.', $context);

        self::assertStringContainsString('Whitfield & Partners', $result['text']);
        self::assertStringNotContainsString('&amp;', $result['text'], 'the text part carries an HTML entity');

        self::assertStringContainsString('Whitfield &amp; Partners', $result['html']);
        self::assertStringNotContainsString('&amp;amp;', $result['html'], 'the HTML part is double-escaped');

        self::assertStringContainsString('Whitfield & Partners', $result['subject']);
    }

    public function testLiteralTemplateTextIsAlsoEscapedExactlyOnce(): void
    {
        $renderer = new TemplateRenderer();

        $result = $renderer->renderMessage('S', 'Terms & conditions apply.', []);

        self::assertStringContainsString('Terms &amp; conditions', $result['html']);
        self::assertStringNotContainsString('&amp;amp;', $result['html']);
        self::assertStringContainsString('Terms & conditions', $result['text']);
    }

    public function testMarkupInAMergeValueNeverBecomesLiveMarkup(): void
    {
        $renderer = new TemplateRenderer();

        $result = $renderer->renderMessage('S', 'Hi {{client_name}}', [
            'client_name' => '<script>alert(1)</script>Bob',
        ]);

        self::assertStringContainsString('&lt;script&gt;', $result['html']);
        self::assertStringNotContainsString('<script>', $result['html']);

        // The plain-text part carries no markup at all.
        self::assertStringNotContainsString('<script>', $result['text']);
        self::assertStringContainsString('Bob', $result['text']);
    }

    public function testQuotesAreEscapedForAttributeSafety(): void
    {
        $renderer = new TemplateRenderer();

        $result = $renderer->renderMessage('S', 'Hi {{client_name}}', ['client_name' => 'O"Brien \'Jr\'']);

        self::assertStringContainsString('&quot;', $result['html']);
        self::assertStringContainsString('&#039;', $result['html']);
    }

    // ------------------------------------------------------ unknown tags

    public function testAnUnknownTagRendersEmptyAndIsReportedNeverLeftLiteral(): void
    {
        $renderer = new TemplateRenderer();
        $context = TemplateRenderer::sampleContext();

        $result = $renderer->renderMessage('Hi {{foo}}', 'Hello {{client_first_name}}, {{bar}} here.', $context);

        // The whole point: a client must never see {{bar}}.
        self::assertStringNotContainsString('{{', $result['text']);
        self::assertStringNotContainsString('{{', $result['html']);
        self::assertStringNotContainsString('bar', $result['text']);

        // Known tags around it still render.
        self::assertStringContainsString('Dana', $result['text']);

        self::assertSame(['foo', 'bar'], $result['warnings']);
    }

    public function testUnknownTagsAreDetectableBeforeSending(): void
    {
        self::assertSame(['foo', 'bar'], TemplateRenderer::unknownTagsIn('{{foo}} {{client_name}} {{bar}}'));
        self::assertSame([], TemplateRenderer::unknownTagsIn('{{client_name}} owes {{amount}}'));
    }

    public function testEveryDocumentedMergeTagResolves(): void
    {
        $renderer = new TemplateRenderer();
        $context = TemplateRenderer::sampleContext();

        $template = implode(' ', array_map(
            static fn (string $tag): string => '{{' . $tag . '}}',
            TemplateRenderer::tagNames()
        ));

        $result = $renderer->renderMessage('S', $template, $context);

        self::assertSame([], $result['warnings'], 'a documented tag is not actually supported');
        self::assertStringNotContainsString('{{', $result['text']);
    }

    public function testContextIsBuiltFromARealInvoiceRow(): void
    {
        $context = TemplateRenderer::contextFor([
            'client_name' => 'Dana Whitfield',
            'client_company' => 'Whitfield & Partners',
            'number' => 'INV-77',
            'amount_cents' => 320050,
            'currency' => 'USD',
            'due_date' => '2026-08-05',
            'days_overdue' => 18,
            'payment_url' => 'https://pay.example.com/77',
        ], 'Ada Lovelace');

        self::assertSame('Dana', $context['client_first_name']);
        self::assertSame('$3,200.50', $context['amount']);
        self::assertSame('5 August 2026', $context['due_date']);
        self::assertSame('18', $context['days_overdue']);
        self::assertSame('Ada Lovelace', $context['sender_name']);
    }

    // ---------------------------------------------------- rendering shape

    public function testAnEmptyMergeValueLeavesNoGapInEitherPart(): void
    {
        $tenantId = $this->seededTenant();
        $steps = SequenceStep::forSequence($tenantId, (int) Sequence::defaultSequence($tenantId)['id']);

        $renderer = new TemplateRenderer();
        $context = array_merge(TemplateRenderer::sampleContext(), ['invoice_url' => '']);

        $result = $renderer->renderMessage($steps[0]['subject_template'], $steps[0]['body_template'], $context);

        self::assertStringNotContainsString("\n\n\n", $result['text'], 'an empty tag left a hole');
        self::assertStringNotContainsString('<p style="margin:0 0 16px;"></p>', $result['html']);
        self::assertStringContainsString('Thanks,', $result['text']);
    }

    public function testOnlyHttpUrlsBecomeLinksAndOnlyInTheHtmlPart(): void
    {
        $renderer = new TemplateRenderer();

        $safe = $renderer->renderMessage('S', 'Pay: {{invoice_url}}', [
            'invoice_url' => 'https://pay.example.com/inv-1042',
        ]);
        self::assertStringContainsString('<a href="https://pay.example.com/inv-1042"', $safe['html']);
        self::assertStringNotContainsString('<a ', $safe['text'], 'the plain-text part must stay plain');

        $unsafe = $renderer->renderMessage('S', 'Click {{invoice_url}}', [
            'invoice_url' => 'javascript:alert(1)',
        ]);
        self::assertStringNotContainsString('<a href="javascript:', $unsafe['html']);
    }

    // ------------------------------------------------------ default copy

    public function testTheDefaultCopyReadsLikeAPersonNotALawyer(): void
    {
        $tenantId = $this->seededTenant();
        $steps = SequenceStep::forSequence($tenantId, (int) Sequence::defaultSequence($tenantId)['id']);

        $corpus = '';
        foreach ($steps as $step) {
            $corpus .= $step['subject_template'] . ' ' . $step['body_template'] . ' ';
        }

        // No shouting.
        self::assertDoesNotMatchRegularExpression('/\b[A-Z]{4,}\b/', $corpus, 'the copy contains ALL CAPS');

        // No legalese and no threats.
        foreach (['legal action', 'debt collect', 'lawyer', 'attorney', 'penalt', 'demand', 'failure to'] as $phrase) {
            self::assertStringNotContainsStringIgnoringCase($phrase, $corpus, 'the copy uses "' . $phrase . '"');
        }

        // Every step gives the client a way out that is not payment.
        $openings = substr_count(strtolower($corpus), 'reply') + substr_count(strtolower($corpus), 'let me know');
        self::assertGreaterThanOrEqual(3, $openings);

        // The final step is firm and factual, not hostile.
        self::assertStringContainsStringIgnoringCase('last reminder', $steps[2]['body_template']);
        self::assertStringContainsStringIgnoringCase('rather resolve this with you', $steps[2]['body_template']);
    }

    public function testSubjectLinesStayShortEnoughToReadInAnInbox(): void
    {
        $tenantId = $this->seededTenant();
        $steps = SequenceStep::forSequence($tenantId, (int) Sequence::defaultSequence($tenantId)['id']);

        foreach ($steps as $step) {
            $rendered = (new TemplateRenderer())->renderMessage(
                $step['subject_template'],
                'x',
                TemplateRenderer::sampleContext()
            );

            self::assertLessThan(70, strlen($rendered['subject']), 'subject is too long: ' . $rendered['subject']);
        }
    }

    // -------------------------------------------------------------- routes

    public function testTheSequencePagesRequireAuthentication(): void
    {
        foreach (['/sequences', '/sequences/1'] as $path) {
            self::assertSame(302, $this->get($path)->status, $path . ' was reachable without a session');
        }
    }

    public function testTheSequenceListRendersTheSeededLadder(): void
    {
        $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);

        $response = $this->get('/sequences');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Default reminders', $response->body);
        self::assertStringContainsString('3 reminders', $response->body);
        self::assertStringContainsString('09:00', $response->body);
        self::assertStringContainsString('Weekends skipped', $response->body);
    }

    public function testTheSequenceEditorRendersEveryStepAndTheTagPalette(): void
    {
        $user = $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $tenantId = TenantContext::forUser((int) $user['id']);
        $sequenceId = (int) Sequence::defaultSequence($tenantId)['id'];

        $response = $this->get('/sequences/' . $sequenceId);

        self::assertSame(200, $response->status);

        // All three saved steps are rendered as cards. Counting by field name
        // would also catch the inert <template> used for adding a new step.
        foreach ([0, 1, 2] as $index) {
            self::assertStringContainsString('data-step="' . $index . '"', $response->body);
        }
        self::assertStringNotContainsString('data-step="3"', $response->body);

        // The palette offers every documented tag.
        foreach (TemplateRenderer::tagNames() as $tag) {
            self::assertStringContainsString('data-insert-tag="' . $tag . '"', $response->body, $tag . ' is missing from the palette');
        }

        // Templates are shown as source for editing, HTML-escaped in the markup.
        self::assertStringContainsString('{{client_first_name}}', $response->body);
    }

    public function testThePreviewEndpointRendersWithoutTouchingAnInvoice(): void
    {
        $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);

        $response = $this->postJson('/api/sequences/preview', [
            '_csrf' => $this->csrfToken(),
            'subject_template' => 'Invoice {{invoice_number}}',
            'body_template' => 'Hi {{client_first_name}}, you owe {{amount}} to {{company}}.',
        ]);

        self::assertSame(200, $response->status, $response->body);

        $body = json_decode($response->body, true);
        self::assertSame('Invoice INV-1042', $body['subject']);
        self::assertStringContainsString('Whitfield &amp; Partners', $body['html']);
        self::assertStringNotContainsString('&amp;amp;', $body['html']);
        self::assertStringContainsString('Whitfield & Partners', $body['text']);
        self::assertSame([], $body['warnings']);
    }

    public function testThePreviewEndpointReportsUnknownTags(): void
    {
        $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);

        $response = $this->postJson('/api/sequences/preview', [
            '_csrf' => $this->csrfToken(),
            'subject_template' => 'Hi',
            'body_template' => 'Hello {{nope}}',
        ]);

        self::assertSame(['nope'], json_decode($response->body, true)['warnings']);
    }

    public function testSavingASequenceRejectsAnUnknownMergeTag(): void
    {
        $user = $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $tenantId = TenantContext::forUser((int) $user['id']);
        $sequenceId = (int) Sequence::defaultSequence($tenantId)['id'];

        $response = $this->postJson('/api/sequences/' . $sequenceId, [
            '_csrf' => $this->csrfToken(),
            'name' => 'Edited',
            'send_window_start' => '09:00',
            'send_window_end' => '16:00',
            'steps' => [
                ['offset_days' => 3, 'tone' => 'polite', 'subject_template' => 'Hi', 'body_template' => 'Hello {{nope}}'],
            ],
        ]);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('nope', json_encode(json_decode($response->body, true)['errors']));
    }

    public function testSavingRewritesTheStepsAndKeepsThemOrdered(): void
    {
        $user = $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $tenantId = TenantContext::forUser((int) $user['id']);
        $sequenceId = (int) Sequence::defaultSequence($tenantId)['id'];

        $response = $this->postJson('/api/sequences/' . $sequenceId, [
            '_csrf' => $this->csrfToken(),
            'name' => 'My ladder',
            'send_window_start' => '10:00',
            'send_window_end' => '15:00',
            'skip_weekends' => false,
            'is_active' => true,
            // Deliberately out of order; the controller sorts by offset.
            'steps' => [
                ['offset_days' => 21, 'tone' => 'final', 'subject_template' => 'Later', 'body_template' => 'Hi {{client_first_name}}'],
                ['offset_days' => 5, 'tone' => 'polite', 'subject_template' => 'Sooner', 'body_template' => 'Hi {{client_first_name}}'],
            ],
        ]);

        self::assertSame(200, $response->status, $response->body);

        $steps = SequenceStep::forSequence($tenantId, $sequenceId);
        self::assertCount(2, $steps);
        self::assertSame([5, 21], array_map(static fn (array $s): int => (int) $s['offset_days'], $steps));
        self::assertSame([1, 2], array_map(static fn (array $s): int => (int) $s['position'], $steps));
        self::assertSame(1, (int) $steps[1]['is_final'], 'the last step should be flagged final');

        $sequence = Sequence::find($tenantId, $sequenceId);
        self::assertSame('10:00:00', $sequence['send_window_start']);
        self::assertSame('15:00:00', $sequence['send_window_end']);
        self::assertSame(0, (int) $sequence['skip_weekends']);
    }

    public function testTwoStepsCannotShareAnOffset(): void
    {
        $user = $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $tenantId = TenantContext::forUser((int) $user['id']);
        $sequenceId = (int) Sequence::defaultSequence($tenantId)['id'];

        $response = $this->postJson('/api/sequences/' . $sequenceId, [
            '_csrf' => $this->csrfToken(),
            'name' => 'Clashing',
            'send_window_start' => '09:00',
            'send_window_end' => '16:00',
            'steps' => [
                ['offset_days' => 7, 'tone' => 'polite', 'subject_template' => 'A', 'body_template' => 'A'],
                ['offset_days' => 7, 'tone' => 'firm', 'subject_template' => 'B', 'body_template' => 'B'],
            ],
        ]);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('7 days', json_encode(json_decode($response->body, true)['errors']));
    }

    public function testASendWindowThatEndsBeforeItStartsIsRejected(): void
    {
        $user = $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $tenantId = TenantContext::forUser((int) $user['id']);
        $sequenceId = (int) Sequence::defaultSequence($tenantId)['id'];

        $response = $this->postJson('/api/sequences/' . $sequenceId, [
            '_csrf' => $this->csrfToken(),
            'name' => 'Backwards',
            'send_window_start' => '17:00',
            'send_window_end' => '09:00',
            'steps' => [
                ['offset_days' => 3, 'tone' => 'polite', 'subject_template' => 'A', 'body_template' => 'A'],
            ],
        ]);

        self::assertSame(422, $response->status);
        self::assertArrayHasKey('send_window_end', json_decode($response->body, true)['errors']);
    }

    public function testAnotherTenantsSequenceIsNotReachable(): void
    {
        $owner = $this->createUser(['email' => 'owner@studio.test', 'name' => 'Owner']);
        $ownerTenant = TenantContext::forUser((int) $owner['id']);
        $sequenceId = (int) Sequence::defaultSequence($ownerTenant)['id'];

        $this->actingAs(['email' => 'mallory@rival.test', 'name' => 'Mallory']);

        self::assertSame(404, $this->postJson('/api/sequences/' . $sequenceId, [
            '_csrf' => $this->csrfToken(),
            'name' => 'Hijacked',
            'steps' => [['offset_days' => 3, 'tone' => 'polite', 'subject_template' => 'A', 'body_template' => 'A']],
        ])->status);

        self::assertNotSame('Hijacked', Sequence::find($ownerTenant, $sequenceId)['name']);
    }

    // -------------------------------------------------------------- helpers

    private function seededTenant(string $name = 'Acme Design', string $email = 'seed@studio.test'): int
    {
        $user = $this->createUser(['email' => $email, 'name' => 'Seed User']);

        return (int) (new OrganizationService())->create($name, (int) $user['id'])['id'];
    }
}
