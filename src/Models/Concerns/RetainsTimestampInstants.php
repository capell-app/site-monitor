<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Models\Concerns;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Override;

/** Retain SQL wall times for compatibility, with explicit instants for new writes. */
trait RetainsTimestampInstants
{
    /** @param string $key */
    #[Override]
    public function setAttribute($key, $value): static
    {
        if ($key === 'metadata' && (is_array($value) || $value === null)) {
            // Result/incident metadata may be assigned after dates in the same fill.
            $instants = $this->timestampInstants();
            if ($instants !== []) {
                $value ??= [];
                $value['_timestamp_instants'] = $instants;
            }

            if ($instants === [] && is_array($value)) {
                unset($value['_timestamp_instants']);
            }
        }

        $isTimestamp = ($this->getCasts()[$key] ?? null) === 'immutable_datetime';
        $instant = null;
        if ($isTimestamp && $value !== null && (! is_string($value) || preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $value) === 1)) {
            throw_unless($value instanceof DateTimeInterface || is_string($value), InvalidArgumentException::class, 'A monitor timestamp must be an instant or wall-time string.');
            $instant = $value instanceof DateTimeInterface
                ? CarbonImmutable::instance($value)
                : self::legacyTimestamp($value);
        }

        // Older workers only read SQL wall times. Preserve their application-local
        // storage contract while keeping the exact instant in UTC provenance.
        // Validate legacy SQL strings in UTC so a local spring gap cannot shift
        // their wall time forward before the conservative reader sees it.
        $wall = $isTimestamp && $instant === null && is_string($value)
            ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'))
            : false;
        if ($wall !== false && $wall->format('Y-m-d H:i:s') === $value) {
            $this->attributes[$key] = $value;
        } else {
            parent::setAttribute($key, $instant?->setTimezone(config()->string('app.timezone', 'UTC')) ?? $value);
        }

        if (! $isTimestamp) {
            return $this;
        }

        $metadata = $this->metadata ?? [];
        $instants = $this->timestampInstants();
        if ($instant === null) {
            // An offset-free string is still legacy evidence, even on a new write.
            unset($instants[$key]);
        } else {
            $instants[$key] = [
                'wall' => $this->timestampWall($key),
                'utc' => $instant->utc()->format('Y-m-d\TH:i:s.u\Z'),
            ];
        }

        if ($instants === []) {
            unset($metadata['_timestamp_instants']);
        } else {
            $metadata['_timestamp_instants'] = $instants;
        }

        parent::setAttribute('metadata', $metadata);

        return $this;
    }

    /** @param string $key */
    #[Override]
    public function originalIsEquivalent($key): bool
    {
        if (($this->getCasts()[$key] ?? null) === 'immutable_datetime') {
            // Date normalisation would treat a spring-gap wall time as equal to
            // its shifted value and silently omit the conservative update.
            return array_key_exists($key, $this->original)
                && ($this->attributes[$key] ?? null) === $this->original[$key];
        }

        return parent::originalIsEquivalent($key);
    }

    public function latestPossibleTimestamp(string $key): ?CarbonImmutable
    {
        $value = $this->getAttribute($key);
        if (! $value instanceof CarbonImmutable) {
            return null;
        }

        $evidence = $this->timestampEvidence($key);
        if ($evidence !== null && $evidence['wall'] === $this->timestampWall($key)) {
            return $value;
        }

        $wall = $this->timestampWall($key);

        return $wall === null ? null : self::legacyTimestamp($wall, true);
    }

    /** @param string $key */
    #[Override]
    protected function castAttribute($key, $value): mixed
    {
        if (($this->getCasts()[$key] ?? null) !== 'immutable_datetime' || $value === null) {
            return parent::castAttribute($key, $value);
        }

        throw_unless(is_string($value), InvalidArgumentException::class, 'A stored monitor timestamp must be a wall-time string.');
        $evidence = $this->timestampEvidence($key);
        // A raw legacy writer must not accidentally reuse stale provenance.
        if ($evidence !== null && $evidence['wall'] === $value) {
            return CarbonImmutable::parse($evidence['utc'])->setTimezone(config()->string('app.timezone', 'UTC'));
        }

        return self::legacyTimestamp($value);
    }

    private static function legacyTimestamp(string $value, bool $latest = false): CarbonImmutable
    {
        $timezone = new DateTimeZone(config()->string('app.timezone', 'UTC'));
        $parsed = CarbonImmutable::parse($value, $timezone);
        // Explicit offsets are already instants. For a repeated legacy wall time,
        // choose the earliest occurrence: uncertainty must not renew freshness.
        if (preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $value) === 1) {
            return $parsed;
        }

        $wall = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1
            ? $value : $parsed->format('Y-m-d H:i:s');
        $naive = CarbonImmutable::parse($wall, 'UTC')->getTimestamp();
        $transitions = $timezone->getTransitions($naive - 172800, $naive + 172800);
        $matches = [];
        $candidates = [];
        foreach ($transitions === false ? [] : $transitions as $transition) {
            $candidate = CarbonImmutable::createFromTimestampUTC($naive - $transition['offset'])->setTimezone($timezone);
            $candidates[] = $candidate;
            if ($candidate->format('Y-m-d H:i:s') === $wall) {
                $matches[] = $candidate;
            }
        }

        // Nonexistent spring wall times are uncertain too. Bound both possible
        // offset interpretations instead of silently normalising them forward.
        foreach ($matches === [] ? $candidates : $matches as $candidate) {
            if ($latest ? $candidate->greaterThan($parsed) : $candidate->lessThan($parsed)) {
                $parsed = $candidate;
            }
        }

        return $parsed;
    }

    /** @return array<array-key, mixed> */
    private function timestampInstants(): array
    {
        $metadata = $this->metadata ?? [];
        $instants = $metadata['_timestamp_instants'] ?? [];

        return is_array($instants) ? $instants : [];
    }

    /** @return array{wall: string, utc: string}|null */
    private function timestampEvidence(string $key): ?array
    {
        $evidence = $this->timestampInstants()[$key] ?? null;
        if (! is_array($evidence) || ! is_string($evidence['wall'] ?? null) || ! is_string($evidence['utc'] ?? null)) {
            return null;
        }

        return ['wall' => $evidence['wall'], 'utc' => $evidence['utc']];
    }

    private function timestampWall(string $key): ?string
    {
        $value = $this->attributes[$key] ?? null;
        throw_unless($value === null || is_string($value), InvalidArgumentException::class, 'A stored monitor timestamp must be a wall-time string.');

        return $value;
    }
}
