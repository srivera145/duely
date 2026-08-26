<?php

declare(strict_types=1);

namespace Tests\Support;

use Keel\App\Services\AiService;
use RuntimeException;

/**
 * Stands in for the Anthropic API.
 *
 * It records the exact prompt and system prompt it was handed, which is how the
 * PII assertions inspect what would actually have left the application — the
 * only way to test that claim without sending anything.
 */
class FakeAiService extends AiService
{
    /** @var string[] */
    public array $prompts = [];

    /** @var string[] */
    public array $systems = [];

    /** @var array<int, array<string, mixed>> */
    public array $options = [];

    /** What the "model" replies with next. */
    public string $reply = '{"subject":"S","body":"B"}';

    /** Set to simulate a network or API failure on the next call. */
    public ?string $throw = null;

    public string $model = 'claude-opus-5';
    public int $inputTokens = 412;
    public int $outputTokens = 168;

    public function completeWithUsage(string $prompt, array $options = []): array
    {
        $this->prompts[] = $prompt;
        $this->systems[] = (string) ($options['system'] ?? '');
        $this->options[] = $options;

        if ($this->throw !== null) {
            $message = $this->throw;
            $this->throw = null;

            throw new RuntimeException($message);
        }

        return [
            'text' => $this->reply,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 0,
        ];
    }

    /**
     * Everything that was ever handed to the API, for a leak sweep.
     */
    public function everythingSent(): string
    {
        return implode("\n", $this->prompts) . "\n" . implode("\n", $this->systems);
    }

    public function callCount(): int
    {
        return count($this->prompts);
    }

    /**
     * Convenience for setting a well-formed step reply.
     */
    // ------------------------------------------------ document extraction

    /** The paths handed to extractFromDocument, in order. */
    public array $documents = [];

    /** The JSON Schemas the extraction calls carried. */
    public array $schemas = [];

    /**
     * Stand in for a schema-constrained document read.
     *
     * The real call cannot come back off-shape, so the fake does not model a
     * malformed reply -- the failure worth testing is a *well-shaped* reply with
     * a wrong value in it, which is what the payload here supplies.
     */
    public function extractFromDocument(
        string $prompt,
        string $path,
        array $schema,
        array $options = []
    ): array {
        $this->prompts[] = $prompt;
        $this->documents[] = $path;
        $this->schemas[] = $schema;
        $this->options[] = $options;

        if ($this->throw !== null) {
            $message = $this->throw;
            $this->throw = null;

            throw new RuntimeException($message);
        }

        $decoded = json_decode($this->reply, true);

        return [
            'data' => is_array($decoded) ? $decoded : [],
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
        ];
    }

    /** The schema the most recent extraction call carried. */
    public function lastSchema(): ?array
    {
        return $this->schemas === [] ? null : $this->schemas[count($this->schemas) - 1];
    }

    /** Queue a well-formed extraction reply. */
    public function replyWithJson(array $payload): void
    {
        $this->reply = (string) json_encode($payload);
    }

    /** Queue a failure on the next call. */
    public function failWith(string $message): void
    {
        $this->throw = $message;
    }

    public function replyWithStep(string $subject, string $body): void
    {
        $this->reply = (string) json_encode(['subject' => $subject, 'body' => $body]);
    }

    /**
     * @param array<int, array{offset_days:int, tone:string, subject:string, body:string}> $steps
     */
    public function replyWithSequence(array $steps): void
    {
        $this->reply = (string) json_encode(['steps' => $steps]);
    }
}
