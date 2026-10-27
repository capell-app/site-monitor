<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class OrderSiteMonitorTimestampsAction
{
    use AsFake;
    use AsObject;

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function handle(Builder $query, string $column, string $direction): Builder
    {
        $model = $query->getModel();
        throw_unless(($model->getCasts()[$column] ?? null) === 'immutable_datetime', InvalidArgumentException::class, 'Expected a monitor timestamp column.');
        throw_unless(in_array($direction, ['asc', 'desc'], true), InvalidArgumentException::class, 'Expected a timestamp sort direction.');

        // Resolve mixed storage before pagination, retaining the caller's scopes.
        // SQL wall-time ordering would put an older autumn occurrence first.
        $records = (clone $query)->reorder()->orderBy($model->getQualifiedKeyName())->get([$model->getQualifiedKeyName(), $column, 'metadata'])
            ->sort(static function (Model $left, Model $right) use ($column, $direction): int {
                $leftInstant = $left->getAttribute($column);
                $rightInstant = $right->getAttribute($column);
                $comparison = (($leftInstant instanceof CarbonImmutable ? $leftInstant : null) <=> ($rightInstant instanceof CarbonImmutable ? $rightInstant : null))
                    ?: ($left->getKey() <=> $right->getKey());

                return $direction === 'desc' ? -$comparison : $comparison;
            })
            ->values();
        if ($records->isEmpty()) {
            return $query;
        }

        $bindings = [];
        foreach ($records as $position => $record) {
            $id = $record->getKey();
            throw_unless(is_int($id), InvalidArgumentException::class, 'Expected an integer monitor identity.');
            $bindings[] = $id;
            $bindings[] = $position;
        }

        throw_unless($model->getKeyName() === 'id', InvalidArgumentException::class, 'Expected the monitor id column.');
        $bindings[] = $records->count();
        // Both monitor resources use their unjoined base table. Keep SQL syntax
        // literal and bind every identity/rank, including the fallback rank.
        $sql = 'CASE id ' . str_repeat('WHEN ? THEN ? ', $records->count()) . 'ELSE ? END';

        return $query->reorder()->orderByRaw($sql, $bindings);
    }
}
