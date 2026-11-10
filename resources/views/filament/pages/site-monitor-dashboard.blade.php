<x-filament-panels::page>
    @php
        $dashboard = $this->dashboard();
        $stats = [
            __('capell-site-monitor::package.dashboard.total_targets') => \Illuminate\Support\Number::format($dashboard->totalTargets, precision: 0, locale: app()->getLocale()),
            __('capell-site-monitor::package.dashboard.enabled_targets') => \Illuminate\Support\Number::format($dashboard->enabledTargets, precision: 0, locale: app()->getLocale()),
            __('capell-site-monitor::package.dashboard.passing_targets') => \Illuminate\Support\Number::format($dashboard->passingTargets, precision: 0, locale: app()->getLocale()),
            __('capell-site-monitor::package.dashboard.warning_targets') => \Illuminate\Support\Number::format($dashboard->warningTargets, precision: 0, locale: app()->getLocale()),
            __('capell-site-monitor::package.dashboard.failing_targets') => \Illuminate\Support\Number::format($dashboard->failingTargets, precision: 0, locale: app()->getLocale()),
            __('capell-site-monitor::package.dashboard.overdue_targets') => \Illuminate\Support\Number::format($dashboard->overdueTargets, precision: 0, locale: app()->getLocale()),
            __('capell-site-monitor::package.dashboard.never_checked_targets') => \Illuminate\Support\Number::format($dashboard->neverCheckedTargets, precision: 0, locale: app()->getLocale()),
            __('capell-site-monitor::package.dashboard.open_incidents') => \Illuminate\Support\Number::format($dashboard->openIncidents, precision: 0, locale: app()->getLocale()),
        ];
    @endphp

    <p data-site-monitor-evidence-guidance>{{ __('capell-site-monitor::package.evidence.policy') }} {{ __('capell-site-monitor::package.evidence.guidance') }}</p>

    <div class="grid gap-4 md:grid-cols-3 xl:grid-cols-4">
        @foreach ($stats as $label => $value)
            <div
                class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900"
            >
                <div
                    class="text-sm font-medium text-gray-500 dark:text-gray-400"
                >
                    {{ $label }}
                </div>
                <div
                    class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white"
                >
                    {{ $value }}
                </div>
            </div>
        @endforeach
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        <div
            class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900"
        >
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400">
                {{ __('capell-site-monitor::package.dashboard.latest_checked_at') }}
            </div>
            <div
                class="mt-1 text-base font-semibold text-gray-950 dark:text-white"
            >
                {{ $dashboard->latestCheckedAt?->toDayDateTimeString() ?? __('capell-site-monitor::package.dashboard.no_data') }}
            </div>
        </div>

        <div
            class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900"
        >
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400">
                {{ __('capell-site-monitor::package.dashboard.oldest_open_incident_at') }}
            </div>
            <div
                class="mt-1 text-base font-semibold text-gray-950 dark:text-white"
            >
                {{ $dashboard->oldestOpenIncidentAt?->toDayDateTimeString() ?? __('capell-site-monitor::package.dashboard.no_data') }}
            </div>
        </div>

        <div
            class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900"
        >
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400">
                {{ __('capell-site-monitor::package.dashboard.median_response_ms') }}
            </div>
            <div
                class="mt-1 text-base font-semibold text-gray-950 dark:text-white"
            >
                @if ($dashboard->medianResponseMs === null)
                    {{ __('capell-site-monitor::package.dashboard.no_data') }}
                @else
                    {{ __('capell-site-monitor::package.dashboard.milliseconds', ['value' => \Illuminate\Support\Number::format($dashboard->medianResponseMs, precision: 0, locale: app()->getLocale())]) }}
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>
