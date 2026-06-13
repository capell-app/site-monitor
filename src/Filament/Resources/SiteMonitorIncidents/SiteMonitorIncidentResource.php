<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Filament\Resources\SiteMonitorIncidents;

use BackedEnum;
use Capell\Core\Facades\CapellCore;
use Capell\SiteMonitor\Enums\SiteMonitorIncidentStatus;
use Capell\SiteMonitor\Filament\Resources\SiteMonitorIncidents\Pages\EditSiteMonitorIncident;
use Capell\SiteMonitor\Filament\Resources\SiteMonitorIncidents\Pages\ListSiteMonitorIncidents;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Providers\SiteMonitorServiceProvider;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Override;

final class SiteMonitorIncidentResource extends Resource
{
    protected static ?string $slug = 'site-monitor/incidents';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $recordTitleAttribute = 'summary';

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('capell-site-monitor::package.resources.incidents'))
                ->schema([
                    Select::make('status')->label(__('capell-site-monitor::package.fields.status'))->options(self::statusOptions())->required(),
                    TextInput::make('severity')->label(__('capell-site-monitor::package.fields.severity'))->required()->maxLength(120),
                    Textarea::make('summary')->label(__('capell-site-monitor::package.fields.summary'))->required()->rows(3)->columnSpanFull(),
                    TextInput::make('failure_count')->label(__('capell-site-monitor::package.fields.failure_count'))->numeric()->required()->minValue(1),
                    KeyValue::make('metadata')->label(__('capell-site-monitor::package.fields.metadata'))->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('target.name')->label(__('capell-site-monitor::package.fields.target'))->searchable()->sortable(),
            TextColumn::make('status')->label(__('capell-site-monitor::package.fields.status'))->badge()->sortable(),
            TextColumn::make('severity')->label(__('capell-site-monitor::package.fields.severity'))->badge()->sortable(),
            TextColumn::make('summary')->label(__('capell-site-monitor::package.fields.summary'))->searchable()->limit(80),
            TextColumn::make('failure_count')->label(__('capell-site-monitor::package.fields.failure_count'))->numeric()->sortable(),
            TextColumn::make('opened_at')->label(__('capell-site-monitor::package.fields.opened_at'))->dateTime()->sortable(),
            TextColumn::make('last_failure_at')->label(__('capell-site-monitor::package.fields.last_failure_at'))->dateTime()->sortable()->toggleable(),
            TextColumn::make('resolved_at')->label(__('capell-site-monitor::package.fields.resolved_at'))->dateTime()->sortable()->toggleable(),
        ]);
    }

    #[Override]
    public static function getModel(): string
    {
        return SiteMonitorIncident::class;
    }

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['target', 'latestRun']);
    }

    #[Override]
    public static function getNavigationGroup(): string
    {
        return __('capell-admin::navigation.group_monitoring');
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return __('capell-site-monitor::package.resources.incidents');
    }

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return CapellCore::isPackageInstalled(SiteMonitorServiceProvider::$packageName);
    }

    /**
     * @return array<string, PageRegistration>
     */
    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSiteMonitorIncidents::route('/'),
            'edit' => EditSiteMonitorIncident::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return collect(SiteMonitorIncidentStatus::cases())
            ->mapWithKeys(static fn (SiteMonitorIncidentStatus $status): array => [$status->value => $status->getLabel()])
            ->all();
    }
}
