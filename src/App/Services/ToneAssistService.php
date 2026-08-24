<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use Keel\Core\Auth;
use Keel\Core\Database;
use Throwable;

/**
 * Claude-assisted drafting for reminder templates.
 *
 * Two actions and no more: rewrite one step in a given tone, or draft a whole
 * three-step ladder from a description of the business. Everything the model
 * produces is returned as a proposal — nothing here ever writes to a live
 * sequence.
 *
 * Three rules the implementation is built around:
 *
 *   Nothing identifying leaves the app. The prompt carries merge tags, never a
 *   real client name, invoice number or amount, and the template is scrubbed on
 *   the way out rather than trusted to already be clean.
 *
 *   The output is validated before the user ever sees it. A response that is
 *   not JSON, or that invents a merge tag Duely does not support, is rejected —
 *   a hallucinated {{customer_reference}} would render as a hole in a real
 *   email, and Phase 4 exists precisely to stop that.
 *
 *   Failure is an error message, never a stack trace and never a half-applied
 *   change.
 */
class ToneAssistService
{
    public const ACTION_REWRITE = 'rewrite';
    public const ACTION_SEQUENCE = 'sequence';

    /** Calls per tenant per rolling day. */
    public const DAILY_LIMIT = 20;

    private const TONES = ['polite', 'neutral', 'firm', 'final'];

    /**
     * Short copy needs little room; a three-step ladder needs more. Both are
     * well clear of the model's limits but stop a runaway response.
     */
    private const MAX_TOKENS_REWRITE = 2000;
    private const MAX_TOKENS_SEQUENCE = 6000;

    public function __construct(
        private readonly AiService $ai = new AiService(),
    ) {
    }

    // ------------------------------------------------------------ availability

    public static function isConfigured(): bool
    {
        return trim((string) \Keel\Core\Env::get('ANTHROPIC_API_KEY', '')) !== '';
    }

    /**
     * @return array{allowed:bool, used:int, limit:int, resets_at:?string}
     */
    public function allowance(int $tenantId, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();
        $since = Clock::toDatabase($now->modify('-1 day'));

        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM ai_usage WHERE tenant_id = ? AND created_at >= ?'
        );
        $statement->execute([$tenantId, $since]);
        $used = (int) $statement->fetchColumn();

        return [
            'allowed' => $used < self::DAILY_LIMIT,
            'used' => $used,
            'limit' => self::DAILY_LIMIT,
            'resets_at' => $used < self::DAILY_LIMIT ? null : $this->oldestCallExpiry($tenantId, $now),
        ];
    }

    // ----------------------------------------------------------- action one

    /**
     * Rewrite a single step in a given tone.
     *
     * @return array{ok:bool, proposal:?array, error:?string, allowance:array, redactions:array}
     */
    public function rewriteStep(
        int $tenantId,
        string $subjectTemplate,
        string $bodyTemplate,
        string $tone,
        string $instruction = '',
        ?DateTimeImmutable $now = null
    ): array {
        $now ??= Clock::now();
        $tone = in_array($tone, self::TONES, true) ? $tone : 'polite';

        $guard = $this->guard($tenantId, $now);

        if ($guard !== null) {
            return $guard;
        }

        // Scrub before building the prompt, not after.
        $subject = PromptScrubber::scrub($subjectTemplate);
        $body = PromptScrubber::scrub($bodyTemplate);
        $note = PromptScrubber::scrubDescription($instruction, 300);

        $redactions = $this->mergeRedactions($subject['redactions'], $body['redactions'], $note['redactions']);

        $prompt = $this->rewritePrompt($subject['text'], $body['text'], $tone, $note['text']);

        return $this->dispatch(
            $tenantId,
            self::ACTION_REWRITE,
            $prompt,
            self::MAX_TOKENS_REWRITE,
            fn (array $decoded): array => $this->validateStep($decoded),
            $redactions,
            $now
        );
    }

    // ----------------------------------------------------------- action two

    /**
     * Draft a complete three-step ladder from a description of the business.
     *
     * @return array{ok:bool, proposal:?array, error:?string, allowance:array, redactions:array}
     */
    public function generateSequence(
        int $tenantId,
        string $businessDescription,
        ?DateTimeImmutable $now = null
    ): array {
        $now ??= Clock::now();

        $guard = $this->guard($tenantId, $now);

        if ($guard !== null) {
            return $guard;
        }

        $description = PromptScrubber::scrubDescription($businessDescription);

        if (trim($description['text']) === '') {
            return $this->refusal(
                'Describe your work and how you tend to deal with clients, and Duely will draft a ladder from that.',
                $tenantId,
                $now
            );
        }

        $prompt = $this->sequencePrompt($description['text']);

        return $this->dispatch(
            $tenantId,
            self::ACTION_SEQUENCE,
            $prompt,
            self::MAX_TOKENS_SEQUENCE,
            fn (array $decoded): array => $this->validateSequence($decoded),
            $description['redactions'],
            $now
        );
    }

    // --------------------------------------------------------------- prompts

    private function systemPrompt(): string
    {
        $tags = implode(', ', array_map(
            static fn (string $tag): string => '{{' . $tag . '}}',
            TemplateRenderer::tagNames()
        ));

        return <<<PROMPT
        You write payment reminder emails for independent freelancers and small studios.

        House style, which matters more than anything else here:
        - Write as one person to another. Short sentences, contractions, no corporate register.
        - Never threaten. No legal language, no debt collection, no capitalised shouting.
        - Always give the client an easy way out that is not payment: invite them to reply
          and say what is holding it up. Most late invoices are stuck on an approval or a
          purchase order, not on unwillingness to pay.
        - A firmer tone means clearer and more direct, never hostile.
        - Keep subjects under about 60 characters.
        - British or American spelling: match whatever the input uses.

        Merge tags are placeholders the application fills in before sending. You may use
        ONLY these, spelled exactly, including the double braces:
        {$tags}

        Never invent a merge tag. Never write a real name, company, amount, date, invoice
        number or link — use the merge tag instead. If the input contains something that
        looks like real data, replace it with the right tag in your output.
        PROMPT;
    }

    private function rewritePrompt(string $subject, string $body, string $tone, string $instruction): string
    {
        $toneGuidance = match ($tone) {
            'firm' => 'Firm: direct and clear that payment is overdue, still warm, still offering help.',
            'final' => 'Final: this is the last automatic reminder. Factual about where things stand, '
                . 'stating plainly that you would rather resolve it directly. Not hostile, no threats.',
            'neutral' => 'Neutral: matter of fact, neither apologetic nor pushed.',
            default => 'Polite: a light nudge that assumes it simply slipped through.',
        };

        $extra = $instruction === '' ? '' : "\n\nThe user also asked for: {$instruction}";

        return <<<PROMPT
        Rewrite this payment reminder.

        Target tone — {$toneGuidance}{$extra}

        Current subject:
        {$subject}

        Current message:
        {$body}

        Respond with a single JSON object and nothing else — no preamble, no explanation,
        no markdown code fences:

        {"subject": "...", "body": "..."}

        Use \\n inside the body string for line breaks.
        PROMPT;
    }

    private function sequencePrompt(string $description): string
    {
        return <<<PROMPT
        Draft a three-step payment reminder ladder for this person.

        About their work and how they deal with clients:
        {$description}

        The three steps escalate:
        1. Day 3 after the due date, tone "polite" — a light nudge.
        2. Day 14, tone "firm" — direct, asking when payment will arrive.
        3. Day 30, tone "final" — the last automatic reminder, factual and calm.

        Match the vocabulary and formality of their description. A wedding photographer
        and a security consultancy should not send the same words.

        Respond with a single JSON object and nothing else — no preamble, no explanation,
        no markdown code fences:

        {"steps": [
          {"offset_days": 3, "tone": "polite", "subject": "...", "body": "..."},
          {"offset_days": 14, "tone": "firm", "subject": "...", "body": "..."},
          {"offset_days": 30, "tone": "final", "subject": "...", "body": "..."}
        ]}

        Use \\n inside each body string for line breaks.
        PROMPT;
    }

    // -------------------------------------------------------------- dispatch

    /**
     * Call the model, parse and validate, and record what it cost either way.
     *
     * @param callable(array):array $validator returns ['ok'=>bool, 'value'=>?array, 'error'=>?string]
     */
    private function dispatch(
        int $tenantId,
        string $action,
        string $prompt,
        int $maxTokens,
        callable $validator,
        array $redactions,
        DateTimeImmutable $now
    ): array {
        $startedAt = microtime(true);

        try {
            $result = $this->ai->completeWithUsage($prompt, [
                'maxTokens' => $maxTokens,
                'system' => $this->systemPrompt(),
                // Short copy does not need the default reasoning budget, and
                // this feature is capped per tenant.
                'effort' => 'medium',
            ]);
        } catch (Throwable $exception) {
            // Network failure, bad key, rate limit upstream. The user gets a
            // sentence; the detail goes to the log.
            error_log('[Duely] Tone assist request failed: ' . $exception->getMessage());

            $this->record($tenantId, $action, 'unknown', [], 'failed', 'request_failed', $startedAt, $now);

            return $this->failure(
                'Duely could not reach the writing assistant just now. Your template is unchanged.',
                $tenantId,
                $now
            );
        }

        $decoded = $this->decodeJson($result['text']);

        if ($decoded === null) {
            $this->record($tenantId, $action, $result['model'], $result, 'rejected', 'unparseable_json', $startedAt, $now);

            return $this->failure(
                'The assistant returned something Duely could not read. Nothing has been changed — try again.',
                $tenantId,
                $now
            );
        }

        $validated = $validator($decoded);

        if (!$validated['ok']) {
            $this->record($tenantId, $action, $result['model'], $result, 'rejected', $validated['error'], $startedAt, $now);

            return $this->failure($validated['error'], $tenantId, $now);
        }

        $this->record($tenantId, $action, $result['model'], $result, 'accepted', null, $startedAt, $now);

        return [
            'ok' => true,
            'proposal' => $validated['value'],
            'error' => null,
            'allowance' => $this->allowance($tenantId, $now),
            'redactions' => $redactions,
        ];
    }

    /**
     * Parse the model's reply into an array, coping with the ways a model
     * wraps JSON even when told not to.
     */
    public function decodeJson(string $text): ?array
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        // Strip a fenced block, with or without a language hint.
        if (preg_match('/^```[a-z]*\s*\n(.*?)\n?```$/is', $text, $matches) === 1) {
            $text = trim($matches[1]);
        }

        // A stray leading fence with no closer, or a trailing one.
        $text = preg_replace('/^```[a-z]*\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $text = trim($text);

        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        // Last resort: the model wrote a sentence before the object. Take the
        // outermost braces and try again.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    // ------------------------------------------------------------ validation

    /**
     * @return array{ok:bool, value:?array, error:?string}
     */
    private function validateStep(array $decoded): array
    {
        $subject = trim((string) ($decoded['subject'] ?? ''));
        $body = trim((string) ($decoded['body'] ?? ''));

        if ($subject === '' || $body === '') {
            return ['ok' => false, 'value' => null, 'error' => 'The assistant did not return both a subject and a message.'];
        }

        $unknown = array_merge(
            TemplateRenderer::unknownTagsIn($subject),
            TemplateRenderer::unknownTagsIn($body)
        );

        if ($unknown !== []) {
            // A tag Duely does not know renders as an empty gap in a real
            // email, so a draft containing one never reaches the user.
            return [
                'ok' => false,
                'value' => null,
                'error' => 'The assistant used a merge tag Duely does not support ({{'
                    . implode('}}, {{', array_unique($unknown)) . '}}), so the draft was discarded.',
            ];
        }

        if (mb_strlen($subject) > 200) {
            return ['ok' => false, 'value' => null, 'error' => 'The assistant returned an unusably long subject line.'];
        }

        return [
            'ok' => true,
            'value' => ['subject' => $subject, 'body' => $body],
            'error' => null,
        ];
    }

    /**
     * @return array{ok:bool, value:?array, error:?string}
     */
    private function validateSequence(array $decoded): array
    {
        $steps = $decoded['steps'] ?? null;

        if (!is_array($steps) || $steps === []) {
            return ['ok' => false, 'value' => null, 'error' => 'The assistant did not return a usable sequence.'];
        }

        $validated = [];
        $seenOffsets = [];

        foreach (array_slice($steps, 0, 5) as $index => $step) {
            if (!is_array($step)) {
                continue;
            }

            $one = $this->validateStep([
                'subject' => $step['subject'] ?? '',
                'body' => $step['body'] ?? '',
            ]);

            if (!$one['ok']) {
                return ['ok' => false, 'value' => null, 'error' => $one['error']];
            }

            $offset = (int) ($step['offset_days'] ?? 0);
            $tone = strtolower(trim((string) ($step['tone'] ?? 'polite')));

            if (isset($seenOffsets[$offset])) {
                return ['ok' => false, 'value' => null, 'error' => 'The assistant proposed two reminders on the same day.'];
            }

            $seenOffsets[$offset] = true;

            $validated[] = [
                'position' => $index + 1,
                'offset_days' => $offset,
                'tone' => in_array($tone, self::TONES, true) ? $tone : 'polite',
                'subject' => $one['value']['subject'],
                'body' => $one['value']['body'],
            ];
        }

        if (count($validated) < 2) {
            return ['ok' => false, 'value' => null, 'error' => 'The assistant returned too few reminders to be useful.'];
        }

        // Offsets must climb, because they are measured from the due date.
        usort($validated, static fn (array $a, array $b): int => $a['offset_days'] <=> $b['offset_days']);

        foreach ($validated as $index => $step) {
            $validated[$index]['position'] = $index + 1;
        }

        return ['ok' => true, 'value' => ['steps' => $validated], 'error' => null];
    }

    // -------------------------------------------------------------- internals

    /**
     * Refuse before spending anything, when the feature cannot or should not run.
     */
    private function guard(int $tenantId, DateTimeImmutable $now): ?array
    {
        if (!self::isConfigured()) {
            return $this->refusal(
                'Writing help is not switched on for this install. An ANTHROPIC_API_KEY is needed.',
                $tenantId,
                $now
            );
        }

        $allowance = $this->allowance($tenantId, $now);

        if (!$allowance['allowed']) {
            return $this->refusal(
                'You have used all ' . self::DAILY_LIMIT . ' writing requests for today. '
                . 'They free up as the day rolls on.',
                $tenantId,
                $now
            );
        }

        return null;
    }

    private function refusal(string $message, int $tenantId, DateTimeImmutable $now): array
    {
        return [
            'ok' => false,
            'proposal' => null,
            'error' => $message,
            'allowance' => $this->allowance($tenantId, $now),
            'redactions' => [],
        ];
    }

    private function failure(string $message, int $tenantId, DateTimeImmutable $now): array
    {
        return $this->refusal($message, $tenantId, $now);
    }

    /**
     * Record the call for cost tracking and for the rate limit.
     *
     * Deliberately records failures too: a rejected response still burned
     * tokens, and a run of rejections is worth being able to see.
     */
    private function record(
        int $tenantId,
        string $action,
        string $model,
        array $usage,
        string $outcome,
        ?string $reason,
        float $startedAt,
        ?DateTimeImmutable $now = null
    ): void {
        try {
            $statement = Database::connection()->prepare(
                'INSERT INTO ai_usage
                    (tenant_id, user_id, action, model, input_tokens, output_tokens,
                     cache_read_tokens, cache_write_tokens, outcome, failure_reason,
                     duration_ms, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $statement->execute([
                $tenantId,
                Auth::id(),
                $action,
                mb_substr($model, 0, 64),
                (int) ($usage['input_tokens'] ?? 0),
                (int) ($usage['output_tokens'] ?? 0),
                (int) ($usage['cache_read_tokens'] ?? 0),
                (int) ($usage['cache_write_tokens'] ?? 0),
                $outcome,
                $reason === null ? null : mb_substr($reason, 0, 255),
                (int) round((microtime(true) - $startedAt) * 1000),
                Clock::toDatabase($now ?? Clock::now()),
            ]);
        } catch (Throwable $exception) {
            // Usage accounting must never be the thing that breaks the feature.
            error_log('[Duely] Could not record AI usage: ' . $exception->getMessage());
        }
    }

    /**
     * Token totals for a tenant, for a billing or usage screen.
     *
     * @return array{calls:int, input_tokens:int, output_tokens:int, accepted:int, rejected:int, failed:int}
     */
    public function usageSummary(int $tenantId, int $days = 30): array
    {
        $since = Clock::toDatabase(Clock::now()->modify('-' . max(1, $days) . ' days'));

        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) AS calls,
                    COALESCE(SUM(input_tokens), 0) AS input_tokens,
                    COALESCE(SUM(output_tokens), 0) AS output_tokens,
                    COALESCE(SUM(outcome = ?), 0) AS accepted,
                    COALESCE(SUM(outcome = ?), 0) AS rejected,
                    COALESCE(SUM(outcome = ?), 0) AS failed
             FROM ai_usage
             WHERE tenant_id = ? AND created_at >= ?'
        );
        $statement->execute(['accepted', 'rejected', 'failed', $tenantId, $since]);
        $row = $statement->fetch() ?: [];

        return [
            'calls' => (int) ($row['calls'] ?? 0),
            'input_tokens' => (int) ($row['input_tokens'] ?? 0),
            'output_tokens' => (int) ($row['output_tokens'] ?? 0),
            'accepted' => (int) ($row['accepted'] ?? 0),
            'rejected' => (int) ($row['rejected'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
        ];
    }

    private function oldestCallExpiry(int $tenantId, DateTimeImmutable $now): ?string
    {
        $statement = Database::connection()->prepare(
            'SELECT MIN(created_at) FROM ai_usage WHERE tenant_id = ? AND created_at >= ?'
        );
        $statement->execute([$tenantId, Clock::toDatabase($now->modify('-1 day'))]);

        $oldest = $statement->fetchColumn();

        if (!is_string($oldest) || $oldest === '') {
            return null;
        }

        return Clock::toDatabase(Clock::fromDatabase($oldest)?->modify('+1 day'));
    }

    private function mergeRedactions(array ...$sets): array
    {
        $merged = [];

        foreach ($sets as $set) {
            foreach ($set as $kind => $count) {
                $merged[$kind] = ($merged[$kind] ?? 0) + $count;
            }
        }

        return $merged;
    }
}
