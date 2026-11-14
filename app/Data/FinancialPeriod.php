<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * Value object representing a station-time reporting period.
 */
final readonly class FinancialPeriod
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $timezone,
    ) {}

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * @return array{from: string, to: string, days: int, timezone: string}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'days' => $this->days(),
            'timezone' => $this->timezone,
        ];
    }
}
