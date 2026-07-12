<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Filament\Resources\SiteMonitorTargets;

use BackedEnum;
use Capell\Core\Facades\CapellCore;
use Capell\SiteMonitor\Actions\RecordSiteMonitorRunAction;
use Capell\SiteMonitor\Actions\RunSiteMonitorCheckAction;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Filament\Resources\SiteMonitorTargets\Pages\CreateSiteMonitorTarget;
use Capell\SiteMonitor\Filament\Resources\SiteMonitorTargets\Pages\EditSiteMonitorTarget;
use Capell\SiteMonitor\Filament\Resources\SiteMonitorTargets\Pages\ListSiteMonitorTargets;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Capell\SiteMonitor\Providers\SiteMonitorServiceProvider;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Override;

final class SiteMonitorTargetResource extends Resource
{
    protected static ?string $slug = 'site-monitor/targets';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $recordTitleAttribute = 'name';

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('capell-site-monitor::package.resources.targets'))
                ->schema([
                    TextInput::make('name')->label(__('capell-site-monitor::package.fields.name'))->required()->maxLength(255),
                    TextInput::make('url')->label(__('capell-site-monitor::package.fields.url'))->url()->required()->maxLength(2048),
                    Select::make('check_type')->label(__('capell-site-monitor::package.fields.check_type'))->options(self::checkTypeOptions())->required(),
                    Toggle::make('enabled')->label(__('capell-site-monitor::package.fields.enabled'))->default(true),
                    TextInput::make('site_id')->label(__('capell-site-monitor::package.fields.site_id'))->numeric(),
                    TextInput::make('language_id')->label(__('capell-site-monitor::package.fields.language_id'))->numeric(),
                    TextInput::make('interval_minutes')->label(__('capell-site-monitor::package.fields.interval_minutes'))->numeric()->required()->minValue(1)->default(self::integerConfig('capell-site-monitor.default_interval_minutes', 5)),
                    TextInput::make('timeout_ms')->label(__('capell-site-monitor::package.fields.timeout_ms'))->numeric()->required()->minValue(100)->default(self::integerConfig('capell-site-monitor.default_timeout_ms', 5000)),
                    TextInput::make('failure_threshold')->label(__('capell-site-monitor::package.fields.failure_threshold'))->numeric()->required()->minValue(1)->default(self::integerConfig('capell-site-monitor.default_failure_threshold', 2)),
                    TextInput::make('expected_status_minimum')->label(__('capell-site-monitor::package.fields.expected_status_minimum'))->numeric()->required()->minValue(100)->maxValue(599)->default(200),
                    TextInput::make('expected_status_maximum')->label(__('capell-site-monitor::package.fields.expected_status_maximum'))->numeric()->required()->minValue(100)->maxValue(599)->default(399),
                    TextInput::make('source_package')->label(__('capell-site-monitor::package.fields.source_package'))->maxLength(255),
                    TextInput::make('source_key')->label(__('capell-site-monitor::package.fields.source_key'))->maxLength(255),
                    TextInput::make('route_name')->label(__('capell-site-monitor::package.fields.route_name'))->maxLength(255),
                    KeyValue::make('metadata')->label(__('capell-site-monitor::package.fields.metadata'))->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('capell-site-monitor::package.fields.name'))->searchable()->sortable(),
            TextColumn::make('url')->label(__('capell-site-monitor::package.fields.url'))->searchable()->limit(56)->copyable(),
            TextColumn::make('check_type')->label(__('capell-site-monitor::package.fields.check_type'))->badge()->sortable(),
            TextColumn::make('current_state')->label(__('capell-site-monitor::package.fields.current_state'))->badge()->sortable(),
            TextColumn::make('latestRun.response_ms')->label(__('capell-site-monitor::package.fields.response_ms'))->numeric()->suffix(' ms')->sortable(),
            TextColumn::make('consecutive_failures')->label(__('capell-site-monitor::package.fields.consecutive_failures'))->numeric()->sortable(),
            TextColumn::make('last_checked_at')->label(__('capell-site-monitor::package.fields.last_checked_at'))->dateTime()->sortable(),
            TextColumn::make('next_check_at')->label(__('capell-site-monitor::package.fields.next_check_at'))->dateTime()->sortable()->toggleable(),
            IconColumn::make('enabled')->label(__('capell-site-monitor::package.fields.enabled'))->boolean()->sortable(),
        ])->recordActions([
            self::runNowAction(),
        ]);
    }

    #[Override]
    public static function getModel(): string
    {
        return SiteMonitorTarget::class;
    }

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['latestRun', 'openIncident']);
    }

    #[Override]
    public static function getNavigationGroup(): string
    {
        return __('capell-admin::navigation.group_monitoring');
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return __('capell-site-monitor::package.resources.targets');
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
            'index' => ListSiteMonitorTargets::route('/'),
            'create' => CreateSiteMonitorTarget::route('/create'),
            'edit' => EditSiteMonitorTarget::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function checkTypeOptions(): array
    {
        return collect(SiteMonitorCheckType::cases())
            ->mapWithKeys(static fn (SiteMonitorCheckType $type): array => [$type->value => $type->getLabel()])
            ->all();
    }

    private static function runNowAction(): Action
    {
        return Action::make('run_now')
            ->label(__('capell-site-monitor::package.actions.run_now'))
            ->icon('heroicon-o-play')
            ->action(function (SiteMonitorTarget $record): void {
                $result = (new RunSiteMonitorCheckAction)->handle($record);

                (new RecordSiteMonitorRunAction)->handle($record, $result);

                Notification::make('capell_site_monitor_target_checked')
                    ->title(__('capell-site-monitor::package.notifications.target_checked', [
                        'state' => $result->state->getLabel(),
                    ]))
                    ->success()
                    ->send();
            });
    }

    private static function integerConfig(string $key, int $default): int
    {
        $value = config($key);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }
}
