<?php

namespace Fika\Laravel;

/** Stable across retries of a queued job; isolated between jobs in long-lived workers. */
class SendContext
{
    private ?string $jobUuid = null;

    private int $sequence = 0;

    public function begin(?string $jobUuid): void
    {
        $this->jobUuid = $jobUuid;
        $this->sequence = 0;
    }

    public function end(): void
    {
        $this->begin(null);
    }

    public function nextKey(): ?string
    {
        return $this->jobUuid === null ? null : 'fika-job-'.hash('sha256', $this->jobUuid.':'.$this->sequence++);
    }
}
