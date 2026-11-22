<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Data;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class SiteMonitorSlackMessageData extends Data
{
    public function __construct(
        public readonly string $text,
        public readonly bool $mrkdwn = false,
        public readonly bool $unfurlLinks = false,
        public readonly bool $unfurlMedia = false,
    ) {}
}
