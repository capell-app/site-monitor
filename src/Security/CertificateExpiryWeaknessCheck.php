<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Security;

use Capell\Core\Contracts\Security\SecurityWeaknessCheck;
use Capell\Core\Data\Security\SecurityPostureInventoryData;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;
use Capell\Core\Enums\Security\SecurityFindingSource;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Override;

/** Stale or missing observations produce an incomplete medium finding for the same target, never a silent resolution. */
final class CertificateExpiryWeaknessCheck implements SecurityWeaknessCheck
{
    #[Override]
    public function weaknessId(): string
    {
        return 'site_monitor.certificate_expiry';
    }

    #[Override]
    public function detect(SecurityPostureInventoryData $inventory): array
    {
        $count = $inventory->flag('site_monitor.target_count');
        if (! is_int($count) || $count < 0) {
            return [$this->finding('inventory', SecurityWeaknessSeverity::Medium, ['observation' => 'missing'])];
        }
        $findings = [];
        for ($i = 0; $i < $count; $i++) {
            $id = $inventory->flag("site_monitor.target_{$i}_id");
            if (! is_int($id)) {
                $findings[] = $this->finding('inventory', SecurityWeaknessSeverity::Medium, ['observation' => 'missing']);
                break;
            }
            $observation = $inventory->flag("site_monitor.target_{$i}_observation");
            $expired = $inventory->flag("site_monitor.target_{$i}_expired");
            $approaching = $inventory->flag("site_monitor.target_{$i}_approaching");
            if ($observation === 'fresh' && $expired === false && $approaching === false) {
                continue;
            }
            $severity = $observation === 'fresh' && $expired === true ? SecurityWeaknessSeverity::High : SecurityWeaknessSeverity::Medium;
            $findings[] = $this->finding((string) $id, $severity, [
                'target_id' => $id,
                'observation' => $observation === 'fresh' && is_bool($expired) && is_bool($approaching) ? 'fresh' : ($observation === 'stale' ? 'stale' : 'missing'),
                'expires_at' => $inventory->flag("site_monitor.target_{$i}_expires_at"),
                'checked_at' => $inventory->flag("site_monitor.target_{$i}_checked_at"),
                'warning_days' => $inventory->flag('site_monitor.warning_days'),
            ]);
        }

        return $findings;
    }

    /** @param array<string, mixed> $evidence */
    private function finding(string $key, SecurityWeaknessSeverity $severity, array $evidence): SecurityWeaknessFindingData
    {
        return new SecurityWeaknessFindingData(
            weaknessId: $this->weaknessId(),
            fingerprint: SecurityWeaknessFindingData::fingerprintFor($this->weaknessId(), $key),
            severity: $severity,
            source: SecurityFindingSource::Builtin,
            title: __('capell-site-monitor::security.title'),
            summary: __('capell-site-monitor::security.summary'),
            evidence: $evidence,
            composerName: 'capell-app/site-monitor',
            recommendedAction: __('capell-site-monitor::security.action'),
        );
    }
}
