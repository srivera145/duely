<?php

namespace Keel\App\Controllers;

use Keel\App\Models\Chase;
use Keel\App\Models\Sequence;
use Keel\App\Models\SequenceStep;
use Keel\App\Services\SequenceSeeder;
use Keel\App\Services\TemplateRenderer;
use Keel\App\Services\TenantContext;
use Keel\Core\Activity;
use Keel\Core\Controller;
use Keel\Core\Request;

/**
 * Sequence management: the ladder, its steps, and the send window.
 *
 * Most tenants never open this screen — the seeded default is the product's
 * opinion and it is meant to be good enough. It exists for the ones who need
 * different words, a different cadence, or different hours.
 */
class SequenceController extends Controller
{
    private const TONES = ['polite', 'neutral', 'firm', 'final'];

    /**
     * GET /sequences
     */
    public function index(Request $request): void
    {
        $tenantId = TenantContext::requireId();

        $this->view('sequences.index', [
            'title' => 'Reminder sequences - Duely',
            'metaDescription' => 'The escalation ladder Duely follows when an invoice goes unpaid.',
            'sequences' => Sequence::withStepCounts($tenantId),
            'hasAny' => Sequence::count($tenantId) > 0,
        ]);
    }

    /**
     * GET /sequences/{id}
     */
    public function edit(Request $request, string $id): void
    {
        $tenantId = TenantContext::requireId();
        $sequence = Sequence::withSteps($tenantId, (int) $id);

        if ($sequence === null) {
            $this->notFound($request);
        }

        $this->view('sequences.edit', [
            'title' => $sequence['name'] . ' - Duely',
            'sequence' => $sequence,
            'tags' => TemplateRenderer::tags(),
            'sampleContext' => TemplateRenderer::sampleContext($this->senderName()),
            'activeChases' => Sequence::activeChaseCount($tenantId, (int) $sequence['id']),
        ]);
    }

    /**
     * POST /api/sequences/{id} — save the sequence and its steps together.
     */
    public function update(Request $request, string $id): void
    {
        $tenantId = TenantContext::requireId();
        $sequenceId = (int) $id;

        if (!Sequence::exists($tenantId, $sequenceId)) {
            $this->json(['error' => 'That sequence does not exist.'], 404);
        }

        $steps = $this->stepInput($request);
        $errors = $this->validate($request, $steps);

        if ($errors !== []) {
            $this->json(['errors' => $errors], 422);
        }

        Sequence::update($tenantId, $sequenceId, [
            'name' => trim((string) $request->input('name', '')),
            'description' => trim((string) $request->input('description', '')) ?: null,
            'send_window_start' => $this->time($request->input('send_window_start'), '09:00:00'),
            'send_window_end' => $this->time($request->input('send_window_end'), '16:00:00'),
            'skip_weekends' => filter_var($request->input('skip_weekends', true), FILTER_VALIDATE_BOOL) ? 1 : 0,
            'is_active' => filter_var($request->input('is_active', true), FILTER_VALIDATE_BOOL) ? 1 : 0,
        ]);

        $this->syncSteps($tenantId, $sequenceId, $steps);

        Activity::log('sequence.updated', 'Sequence', $sequenceId, ['steps' => count($steps)]);

        $this->json([
            'sequence' => Sequence::withSteps($tenantId, $sequenceId),
            'saved' => true,
        ]);
    }

    /**
     * POST /api/sequences/preview — render a template as the user types.
     *
     * Read-only, and never touches a real invoice.
     */
    public function preview(Request $request): void
    {
        TenantContext::requireId();

        $renderer = new TemplateRenderer();
        $context = TemplateRenderer::sampleContext($this->senderName());

        $result = $renderer->renderMessage(
            (string) $request->input('subject_template', ''),
            (string) $request->input('body_template', ''),
            $context
        );

        $this->json([
            'subject' => $result['subject'],
            'text' => $result['text'],
            'html' => $result['html'],
            // The editor shows these beside the field rather than waiting for
            // a client to receive an email with a hole in it.
            'warnings' => $result['warnings'],
        ]);
    }

    /**
     * POST /api/sequences/{id}/default — make this the sequence new chases use.
     */
    public function makeDefault(Request $request, string $id): void
    {
        $tenantId = TenantContext::requireId();
        $sequenceId = (int) $id;

        if (!Sequence::exists($tenantId, $sequenceId)) {
            $this->json(['error' => 'That sequence does not exist.'], 404);
        }

        Sequence::makeDefault($tenantId, $sequenceId);
        Activity::log('sequence.made_default', 'Sequence', $sequenceId);

        $this->json(['is_default' => true]);
    }

    /**
     * POST /api/sequences/restore-default — put Duely's ladder back.
     *
     * The escape hatch for a tenant who has edited their sequence into a
     * corner, and the recovery path if seeding ever failed at signup.
     */
    public function restoreDefault(Request $request): void
    {
        $tenantId = TenantContext::requireId();

        // Seeding is a no-op when a sequence already exists, so an explicit
        // restore creates a fresh copy alongside rather than overwriting the
        // words someone may have spent time on.
        $definition = SequenceSeeder::definition();
        $name = $this->uniqueName($tenantId, $definition['name']);

        $sequenceId = Sequence::createWithSteps($tenantId, [
            'name' => $name,
            'description' => $definition['description'],
            'tone' => $definition['tone'],
            'send_window_start' => $definition['send_window_start'],
            'send_window_end' => $definition['send_window_end'],
            'skip_weekends' => $definition['skip_weekends'],
            'is_active' => 1,
        ], $definition['steps']);

        Activity::log('sequence.restored_default', 'Sequence', $sequenceId);

        $this->json(['id' => $sequenceId, 'name' => $name]);
    }

    /**
     * POST /api/sequences/{id}/delete
     */
    public function destroy(Request $request, string $id): void
    {
        $tenantId = TenantContext::requireId();
        $sequenceId = (int) $id;

        $sequence = Sequence::find($tenantId, $sequenceId);

        if ($sequence === null) {
            $this->json(['error' => 'That sequence does not exist.'], 404);
        }

        // Deleting a sequence cascades to its chases, which would silently stop
        // reminders that are mid-flight. Refuse and explain instead.
        $active = Sequence::activeChaseCount($tenantId, $sequenceId);

        if ($active > 0) {
            $this->json([
                'error' => 'This sequence is running against ' . $active . ' invoice' . ($active === 1 ? '' : 's')
                    . '. Stop those chases first, or switch them to another sequence.',
                'active_chases' => $active,
            ], 409);
        }

        if ((int) $sequence['is_default'] === 1 && Sequence::count($tenantId) > 1) {
            $this->json(['error' => 'Make another sequence the default before deleting this one.'], 409);
        }

        Sequence::delete($tenantId, $sequenceId);
        Activity::log('sequence.deleted', 'Sequence', $sequenceId);

        $this->json(['deleted' => true]);
    }

    // -------------------------------------------------------------- internals

    /**
     * Replace the sequence's steps with the submitted set, in one transaction.
     *
     * Steps are rewritten rather than diffed: the editor owns the whole ladder,
     * and a partial update could leave two steps sharing a position, which the
     * unique index would reject halfway through.
     *
     * @param array<int, array<string, mixed>> $steps
     */
    private function syncSteps(int $tenantId, int $sequenceId, array $steps): void
    {
        $connection = \Keel\Core\Database::connection();
        $connection->beginTransaction();

        try {
            foreach (SequenceStep::forSequence($tenantId, $sequenceId) as $existing) {
                SequenceStep::delete($tenantId, (int) $existing['id']);
            }

            $position = 1;

            foreach ($steps as $step) {
                SequenceStep::create($tenantId, [
                    'sequence_id' => $sequenceId,
                    'position' => $position,
                    'offset_days' => $step['offset_days'],
                    'tone' => $step['tone'],
                    'is_final' => $position === count($steps) ? 1 : 0,
                    'subject_template' => $step['subject_template'],
                    'body_template' => $step['body_template'],
                ]);
                $position++;
            }

            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @return array<int, array{offset_days:int, tone:string, subject_template:string, body_template:string}>
     */
    private function stepInput(Request $request): array
    {
        $raw = $request->input('steps', []);

        if (!is_array($raw)) {
            return [];
        }

        $steps = [];

        foreach ($raw as $step) {
            if (!is_array($step)) {
                continue;
            }

            $tone = strtolower(trim((string) ($step['tone'] ?? 'polite')));

            $steps[] = [
                'offset_days' => (int) ($step['offset_days'] ?? 0),
                'tone' => in_array($tone, self::TONES, true) ? $tone : 'polite',
                'subject_template' => trim((string) ($step['subject_template'] ?? '')),
                'body_template' => trim((string) ($step['body_template'] ?? '')),
            ];
        }

        // Offsets count from the due date, so the ladder must climb.
        usort($steps, static fn (array $a, array $b): int => $a['offset_days'] <=> $b['offset_days']);

        return $steps;
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     * @return array<string, string>
     */
    private function validate(Request $request, array $steps): array
    {
        $errors = [];

        if (trim((string) $request->input('name', '')) === '') {
            $errors['name'] = 'Give this sequence a name.';
        }

        $start = $this->time($request->input('send_window_start'), '09:00:00');
        $end = $this->time($request->input('send_window_end'), '16:00:00');

        if ($start >= $end) {
            $errors['send_window_end'] = 'The send window has to end after it starts.';
        }

        if ($steps === []) {
            $errors['steps'] = 'A sequence needs at least one reminder.';

            return $errors;
        }

        $seenOffsets = [];

        foreach ($steps as $index => $step) {
            $label = 'Reminder ' . ($index + 1);

            if ($step['subject_template'] === '') {
                $errors['steps.' . $index . '.subject_template'] = $label . ' needs a subject line.';
            }

            if ($step['body_template'] === '') {
                $errors['steps.' . $index . '.body_template'] = $label . ' needs a message.';
            }

            if (isset($seenOffsets[$step['offset_days']])) {
                $errors['steps.' . $index . '.offset_days'] =
                    'Two reminders cannot both send ' . $step['offset_days'] . ' days after the due date.';
            }

            $seenOffsets[$step['offset_days']] = true;

            // An unknown tag would silently render as a hole in a real email.
            foreach ([$step['subject_template'], $step['body_template']] as $template) {
                $unknown = TemplateRenderer::unknownTagsIn($template);

                if ($unknown !== []) {
                    $errors['steps.' . $index . '.body_template'] =
                        $label . ' uses a merge tag Duely does not know: {{' . implode('}}, {{', $unknown) . '}}.';
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Coerce a submitted time to HH:MM:SS, falling back rather than storing junk.
     */
    private function time(mixed $value, string $default): string
    {
        $value = trim((string) $value);

        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/', $value, $matches) !== 1) {
            return $default;
        }

        return $matches[1] . ':' . $matches[2] . ':' . ($matches[4] ?? '00');
    }

    private function uniqueName(int $tenantId, string $base): string
    {
        $name = $base;
        $suffix = 2;

        while (Sequence::findByName($tenantId, $name) !== null) {
            $name = $base . ' (' . $suffix . ')';
            $suffix++;

            if ($suffix > 50) {
                return $base . ' ' . bin2hex(random_bytes(3));
            }
        }

        return $name;
    }

    /**
     * The name reminders are signed with, defaulting to the user's own.
     */
    private function senderName(): string
    {
        $user = TenantContext::user();
        $account = \Keel\App\Models\EmailAccount::defaultAccount(TenantContext::requireId());

        $fromName = trim((string) ($account['from_name'] ?? ''));

        if ($fromName !== '') {
            return $fromName;
        }

        return trim((string) ($user['name'] ?? '')) ?: (string) $user['email'];
    }

    private function notFound(Request $request): never
    {
        if ($request->wantsJson()) {
            $this->json(['error' => 'That sequence does not exist.'], 404);
        }

        $this->redirect('/sequences?missing=1');
    }
}
