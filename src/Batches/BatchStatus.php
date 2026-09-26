<?php

namespace Hojabbr\CliProxy\Batches;

use Carbon\CarbonImmutable;

/**
 * Where a whole batch has got to.
 *
 * `processingStatus` is `in_progress`, `canceling` or `ended`. It says nothing about any one
 * request: a batch ends when every request succeeded, errored, was canceled or expired, so an
 * ended batch may hold nothing but failures. Read the per-request verdicts from `results()`.
 */
final readonly class BatchStatus
{
    /**
     * @param  array{processing: int, succeeded: int, errored: int, canceled: int, expired: int}  $requestCounts
     */
    public function __construct(
        public string $id,
        public string $processingStatus,
        public array $requestCounts,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $expiresAt,
        public ?CarbonImmutable $endedAt,
    ) {}

    public function hasEnded(): bool
    {
        return $this->processingStatus === 'ended';
    }

    /**
     * @return array{id: string, type: string, processing_status: string, request_counts: array{processing: int, succeeded: int, errored: int, canceled: int, expired: int}, created_at: string, expires_at: string, ended_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => 'batch',
            'processing_status' => $this->processingStatus,
            'request_counts' => $this->requestCounts,
            'created_at' => $this->createdAt->toIso8601String(),
            'expires_at' => $this->expiresAt->toIso8601String(),
            'ended_at' => $this->endedAt?->toIso8601String(),
        ];
    }
}
