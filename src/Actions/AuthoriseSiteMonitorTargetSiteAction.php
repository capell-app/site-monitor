<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class AuthoriseSiteMonitorTargetSiteAction
{
    use AsFake;
    use AsObject;

    public function handle(?int $siteId): void
    {
        $access = SiteAccess::current();

        if ($siteId === null) {
            abort_unless($access->isGlobal(), 403);

            return;
        }

        abort_unless($access->canSiteId($siteId), 403);

        if (! Site::query()->whereKey($siteId)->exists()) {
            throw ValidationException::withMessages([
                'site_id' => __('capell-site-monitor::package.validation.site_missing'),
            ]);
        }
    }
}
