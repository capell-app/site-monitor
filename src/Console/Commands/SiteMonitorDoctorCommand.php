<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Console\Commands;

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\SiteMonitor\Health\SiteMonitorHealthCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Override;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

final class SiteMonitorDoctorCommand extends Command
{
    protected $signature = 'capell:site-monitor:doctor {--json : Output diagnostic results as JSON}';

    protected $description = 'Run Site Monitor diagnostics without executing monitor checks.';

    #[Override]
    public function getDescription(): string
    {
        return (string) __('capell-site-monitor::package.commands.doctor.description');
    }

    public function handle(): int
    {
        $results = SiteMonitorHealthCheck::runDiagnostics();

        if ((bool) $this->option('json')) {
            $this->output->writeln(json_encode($this->payloadFor($results), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $this->exitCodeFor($results);
        }

        $this->components->info((string) __('capell-site-monitor::package.commands.doctor.completed', [
            'passed' => $results->filter(static fn (DoctorCheckResultData $result): bool => $result->passed)->count(),
            'total' => $results->count(),
        ]));

        $this->table([
            __('capell-site-monitor::package.commands.doctor.columns.check'),
            __('capell-site-monitor::package.commands.doctor.columns.status'),
            __('capell-site-monitor::package.commands.doctor.columns.message'),
        ], $results
            ->map(static fn (DoctorCheckResultData $result): array => [
                'check' => $result->label,
                'status' => $result->passed
                    ? __('capell-site-monitor::package.commands.doctor.passed')
                    : __('capell-site-monitor::package.commands.doctor.failed'),
                'message' => $result->message,
            ])
            ->all());

        return $this->exitCodeFor($results);
    }

    /**
     * @param  Collection<int, DoctorCheckResultData>  $results
     * @return list<array{label: string, passed: bool, message: string|null, remediation: string|null}>
     */
    private function payloadFor(Collection $results): array
    {
        $payload = [];

        foreach ($results as $result) {
            $payload[] = [
                'label' => $result->label,
                'passed' => $result->passed,
                'message' => $result->message,
                'remediation' => $result->remediation,
            ];
        }

        return $payload;
    }

    /**
     * @param  Collection<int, DoctorCheckResultData>  $results
     */
    private function exitCodeFor(Collection $results): int
    {
        return $results->every(static fn (DoctorCheckResultData $result): bool => $result->passed)
            ? SymfonyCommand::SUCCESS
            : SymfonyCommand::FAILURE;
    }
}
