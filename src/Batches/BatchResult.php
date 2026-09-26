<?php

namespace Hojabbr\CliProxy\Batches;

/**
 * One request's outcome.
 *
 * `type` is `succeeded`, `errored`, `canceled` or `expired`. A success carries the response body
 * exactly as the gateway returned it; an error carries its kind and text whole, since an error
 * with its message thrown away cannot be diagnosed later.
 */
final readonly class BatchResult
{
    /**
     * @param  array<string, mixed>|null  $message
     * @param  array{type: string, message: string, status: int|null}|null  $error
     */
    public function __construct(
        public string $customId,
        public string $type,
        public ?array $message = null,
        public ?array $error = null,
    ) {}

    public static function from(BatchRequest $request): self
    {
        return new self(
            customId: $request->custom_id,
            type: $request->status->value,
            message: $request->status === BatchRequestStatus::Succeeded ? $request->result : null,
            error: $request->status === BatchRequestStatus::Errored ? [
                'type' => (string) $request->error_type,
                'message' => (string) $request->error_message,
                'status' => $request->http_status,
            ] : null,
        );
    }

    public function succeeded(): bool
    {
        return $this->type === 'succeeded';
    }

    /**
     * The answer's text, from either protocol's response shape, or null when there is none.
     */
    public function text(): ?string
    {
        if ($this->message === null) {
            return null;
        }

        $choice = data_get($this->message, 'choices.0.message.content');

        if (is_string($choice)) {
            return $choice;
        }

        $blocks = is_array($choice) ? $choice : data_get($this->message, 'content');
        $texts = [];

        foreach (is_array($blocks) ? $blocks : [] as $block) {
            $text = is_array($block) && in_array($block['type'] ?? null, ['text', 'output_text'], true) ? ($block['text'] ?? null) : null;

            if (is_string($text)) {
                $texts[] = $text;
            }
        }

        return implode('', $texts);
    }

    /**
     * @return array{custom_id: string, result: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'custom_id' => $this->customId,
            'result' => array_filter([
                'type' => $this->type,
                'message' => $this->message,
                'error' => $this->error,
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }
}
