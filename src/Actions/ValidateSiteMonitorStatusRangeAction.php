<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Illuminate\Support\Facades\Validator;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ValidateSiteMonitorStatusRangeAction
{
    use AsFake;
    use AsObject;

    public function handle(SiteMonitorTarget $target): void
    {
        if ($target->check_type !== SiteMonitorCheckType::HttpStatus) {
            return;
        }

        Validator::make([
            'expected_status_minimum' => $target->expected_status_minimum ?? 200,
            'expected_status_maximum' => $target->expected_status_maximum ?? 399,
        ], [
            'expected_status_minimum' => ['required', 'integer', 'between:100,599'],
            'expected_status_maximum' => ['required', 'integer', 'between:100,599', 'gte:expected_status_minimum'],
        ], ['expected_status_maximum.gte' => __('capell-site-monitor::package.validation.status_range')])->validate();
    }
}
