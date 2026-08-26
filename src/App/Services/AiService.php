<?php

namespace Keel\App\Services;

use Keel\Core\Env;

class AiService
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    public function complete(string $prompt, array $options = []): string
    {
        $response = $this->request([
            'model' => $this->model(),
            'max_tokens' => $this->maxTokens($options),
            'messages' => [[
                'role' => 'user',
                'content' => $prompt,
            ]],
        ]);

        return $this->extractText($response);
    }

    public function completeWithImage(string $prompt, string $imagePath, array $options = []): string
    {
        if (!is_file($imagePath)) {
            throw new \RuntimeException('Image file not found.');
        }

        $mimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($imagePath);
        if (!in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
            throw new \RuntimeException('Only JPEG and PNG images are supported for multimodal requests.');
        }

        $contents = file_get_contents($imagePath);
        if ($contents === false) {
            throw new \RuntimeException('Failed to read image file.');
        }

        $response = $this->request([
            'model' => $this->model(),
            'max_tokens' => $this->maxTokens($options),
            'messages' => [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $mimeType,
                            'data' => base64_encode($contents),
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => $prompt,
                    ],
                ],
            ]],
        ]);

        return $this->extractText($response);
    }

    /**
     * Complete, and report what it cost.
     *
     * The plain complete() throws the usage block away, which makes per-tenant
     * cost tracking impossible. This returns the text alongside the token
     * counts the API reported.
     *
     * @return array{
     *     text:string, model:string,
     *     input_tokens:int, output_tokens:int,
     *     cache_read_tokens:int, cache_write_tokens:int
     * }
     */
    public function completeWithUsage(string $prompt, array $options = []): array
    {
        $payload = [
            'model' => $this->model(),
            'max_tokens' => $this->maxTokens($options),
            'messages' => [[
                'role' => 'user',
                'content' => $prompt,
            ]],
        ];

        if (isset($options['system'])) {
            $payload['system'] = (string) $options['system'];
        }

        // effort tunes how much reasoning the model spends. Short copy does not
        // need the default, and this feature is rate limited per tenant.
        if (isset($options['effort'])) {
            $payload['output_config'] = ['effort' => (string) $options['effort']];
        }

        $response = $this->request($payload);
        $usage = $response['usage'] ?? [];

        return [
            'text' => $this->extractText($response),
            'model' => (string) ($response['model'] ?? $payload['model']),
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'cache_read_tokens' => (int) ($usage['cache_read_input_tokens'] ?? 0),
            'cache_write_tokens' => (int) ($usage['cache_creation_input_tokens'] ?? 0),
        ];
    }

    /**
     * Read a document — a PDF or a photo — against a JSON Schema.
     *
     * `output_config.format` constrains the response to the schema at the API
     * level, so the reply cannot come back as prose, as fenced markdown, or with
     * a field that does not exist. That matters more here than for the writing
     * assistant: a hallucinated due date silently schedules a reminder on the
     * wrong day, and a hallucinated amount emails a client a number the user
     * never wrote.
     *
     * The document block goes before the text block, which is the order the API
     * expects.
     *
     * @param array<string, mixed> $schema a JSON Schema for the reply
     * @return array{data:array<string, mixed>, model:string, input_tokens:int, output_tokens:int}
     */
    public function extractFromDocument(
        string $prompt,
        string $path,
        array $schema,
        array $options = []
    ): array {
        if (!is_file($path)) {
            throw new \RuntimeException('Document not found.');
        }

        $mimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException('Failed to read the document.');
        }

        // A PDF is a document block; a photograph of an invoice is an image
        // block. Both are base64, and the base64 must carry no newlines.
        $source = ['type' => 'base64', 'media_type' => $mimeType, 'data' => base64_encode($contents)];

        $block = match ($mimeType) {
            'application/pdf' => ['type' => 'document', 'source' => $source],
            'image/jpeg', 'image/png' => ['type' => 'image', 'source' => $source],
            default => throw new \RuntimeException(
                'Only PDF, JPEG and PNG documents can be read. This file is ' . $mimeType . '.'
            ),
        };

        $response = $this->request([
            'model' => $this->model(),
            'max_tokens' => $this->maxTokens($options),
            'output_config' => [
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
            'messages' => [[
                'role' => 'user',
                'content' => [$block, ['type' => 'text', 'text' => $prompt]],
            ]],
        ]);

        // A refusal is a 200 with no usable content, so it has to be checked
        // before the reply is read rather than after.
        if (($response['stop_reason'] ?? null) === 'refusal') {
            throw new \RuntimeException('Claude declined to read that document.');
        }

        $text = $this->extractText($response);
        $decoded = json_decode($text, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('The reply did not match the requested shape.');
        }

        $usage = $response['usage'] ?? [];

        return [
            'data' => $decoded,
            'model' => (string) ($response['model'] ?? $this->model()),
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
        ];
    }

    public function completeJson(string $prompt, array $options = []): array
    {
        $jsonPrompt = $prompt . "\n\nReturn only valid JSON. Do not include markdown fences or commentary.";
        $text = $this->complete($jsonPrompt, $options);
        $decoded = json_decode($text, true);

        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Anthropic returned malformed JSON: ' . json_last_error_msg());
        }

        return $decoded;
    }

    private function request(array $payload): array
    {
        $apiKey = trim((string) Env::get('ANTHROPIC_API_KEY', ''));
        if ($apiKey === '') {
            throw new \RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new \RuntimeException('Failed to encode Anthropic request payload.');
        }

        $handle = curl_init(self::API_URL);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 30,
        ]);

        $rawResponse = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);

        if ($rawResponse === false || $curlError !== '') {
            $this->logFailure('Anthropic request failed', ['status' => $status, 'curl_error' => $curlError]);
            throw new \RuntimeException('Anthropic request failed or timed out.');
        }

        $decoded = json_decode($rawResponse, true);

        if ($status !== 200) {
            $this->logFailure('Anthropic returned an error response', [
                'status' => $status,
                'body' => $decoded ?? $rawResponse,
            ]);
            throw new \RuntimeException('Anthropic request failed with status ' . $status . '.');
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Anthropic returned an unreadable response.');
        }

        return $decoded;
    }

    private function extractText(array $response): string
    {
        $parts = $response['content'] ?? [];

        foreach ($parts as $part) {
            if (($part['type'] ?? null) === 'text' && isset($part['text'])) {
                return trim((string) $part['text']);
            }
        }

        throw new \RuntimeException('Anthropic response did not contain any text content.');
    }

    private function model(): string
    {
        // Claude Opus 5 is the current default model. Override per install with
        // ANTHROPIC_MODEL; use an exact id from Anthropic's lineup, never a
        // date-suffixed variant.
        return trim((string) Env::get('ANTHROPIC_MODEL', 'claude-opus-5'));
    }

    private function maxTokens(array $options): int
    {
        return max(1, (int) ($options['maxTokens'] ?? 1024));
    }

    private function logFailure(string $message, array $context): void
    {
        if ((bool) Env::get('APP_DEBUG', false)) {
            error_log('[Keel] ' . $message . ': ' . json_encode($context));
            return;
        }

        $sanitized = $context;
        unset($sanitized['body']);
        error_log('[Keel] ' . $message . ': ' . json_encode($sanitized));
    }
}