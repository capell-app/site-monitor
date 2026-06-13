<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Data;

use Capell\SiteMonitor\Enums\SiteMonitorState;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class SiteMonitorCheckResultData extends Data
{
    /**
     * @param  array<int, string>  $redirectChain
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly SiteMonitorState $state,
        public readonly ?int $statusCode,
        public readonly ?int $responseMs,
        public readonly ?CarbonImmutable $expiresAt,
        public readonly ?string $errorType,
        public readonly ?string $errorMessage,
        public readonly array $redirectChain = [],
        public readonly array $metadata = [],
    ) {}

    public function isFailure(): bool
    {
        return $this->state === SiteMonitorState::Failing;
    }
}
