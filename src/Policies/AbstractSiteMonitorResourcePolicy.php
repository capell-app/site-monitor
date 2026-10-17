<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Policies;

use Capell\Admin\Policies\Concerns\ResolvesShieldPermission;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Throwable;

abstract class AbstractSiteMonitorResourcePolicy
{
    use ResolvesShieldPermission;

    abstract protected static function subject(): string;

    public function viewAny(User $user): bool
    {
        return $this->hasAnyPermission($user, ['view_any', 'view']);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->hasAnyPermission($user, ['view_any', 'view'])
            && $this->canUseRecordSite($user, $record);
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'create');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->hasPermission($user, 'update')
            && $this->canUseRecordSite($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->hasPermission($user, 'delete')
            && $this->canUseRecordSite($user, $record);
    }

    public function deleteAny(User $user): bool
    {
        return $this->hasPermission($user, 'delete_any');
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->hasPermission($user, 'restore')
            && $this->canUseRecordSite($user, $record);
    }

    public function restoreAny(User $user): bool
    {
        return $this->hasPermission($user, 'restore_any');
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return $this->hasPermission($user, 'force_delete')
            && $this->canUseRecordSite($user, $record);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->hasPermission($user, 'force_delete_any');
    }

    public function replicate(User $user, Model $record): bool
    {
        return $this->hasPermission($user, 'replicate')
            && $this->canUseRecordSite($user, $record);
    }

    public function reorder(User $user): bool
    {
        return $this->hasPermission($user, 'reorder');
    }

    protected function canUseRecordSite(User $user, Model $record): bool
    {
        $siteId = $this->recordSiteId($record);

        if (SiteScope::isGlobalActor($user)) {
            return true;
        }

        return $siteId !== null && SiteAccess::forActor($user)->canSiteId($siteId);
    }

    protected function recordSiteId(Model $record): ?int
    {
        $siteId = $record->getAttribute('site_id');

        return is_numeric($siteId) ? (int) $siteId : null;
    }

    /**
     * @param  list<string>  $abilities
     */
    private function hasAnyPermission(User $user, array $abilities): bool
    {
        return array_any($abilities, fn (string $ability): bool => $this->hasPermission($user, $ability));
    }

    private function hasPermission(User $user, string $ability): bool
    {
        if (SiteScope::isGlobalActor($user)) {
            return true;
        }

        try {
            return $user->checkPermissionTo(self::permission($ability, static::subject()));
        } catch (Throwable) {
            return false;
        }
    }
}
