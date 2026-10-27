<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Filament\Resources\SiteMonitorIncidents\Pages;

use Capell\SiteMonitor\Actions\TransitionSiteMonitorIncidentAction;
use Capell\SiteMonitor\Enums\SiteMonitorIncidentStatus;
use Capell\SiteMonitor\Filament\Resources\SiteMonitorIncidents\SiteMonitorIncidentResource;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Gate;
use Override;

final class EditSiteMonitorIncident extends EditRecord
{
    protected static string $resource = SiteMonitorIncidentResource::class;

    /** @return list<Action> */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            $this->transitionAction('resolve', SiteMonitorIncidentStatus::Resolved),
            $this->transitionAction('reopen', SiteMonitorIncidentStatus::Open),
        ];
    }

    private function transitionAction(string $name, SiteMonitorIncidentStatus $status): Action
    {
        return Action::make($name)
            ->label(__('capell-site-monitor::package.actions.' . $name))
            ->modalDescription(__('capell-site-monitor::package.actions.manual_transition_help'))
            ->authorize(fn (SiteMonitorIncident $record): bool => Gate::allows('update', $record))
            ->visible(fn (SiteMonitorIncident $record): bool => $record->status !== $status)
            ->schema([Textarea::make('reason')->label(__('capell-site-monitor::package.fields.reason'))->required()->maxLength(2000)])
            ->action(function (SiteMonitorIncident $record, array $data) use ($status): void {
                TransitionSiteMonitorIncidentAction::run($record, $status, is_string($data['reason'] ?? null) ? $data['reason'] : '');
                $record->refresh();
                $this->refreshFormData(['status', 'resolved_at', 'metadata']);
            });
    }
}
