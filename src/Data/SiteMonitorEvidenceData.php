<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Data;

use Capell\SiteMonitor\Enums\SiteMonitorEvidenceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Lang;
use Spatie\LaravelData\Data;

final class SiteMonitorEvidenceData extends Data
{
    public function __construct(
        public readonly SiteMonitorEvidenceStatus $status,
        public readonly ?CarbonImmutable $lastCheckedAt,
        public readonly ?CarbonImmutable $overdueAt,
        public readonly bool $invalidStatusRange,
    ) {}

    public function label(): string
    {
        return $this->status->getLabel();
    }

    public function guidance(): ?string
    {
        if ($this->invalidStatusRange) {
            return Lang::string('capell-site-monitor::package.validation.repair_status_range');
        }

        return in_array($this->status, [SiteMonitorEvidenceStatus::Overdue, SiteMonitorEvidenceStatus::NeverChecked], true)
            ? Lang::string('capell-site-monitor::package.evidence.guidance')
            : null;
    }
}
