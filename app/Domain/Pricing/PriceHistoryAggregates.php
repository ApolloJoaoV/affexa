<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Promotion\PromotionConfidence;
use Carbon\CarbonImmutable;

/**
 * The historical baseline a promotion is judged against.
 *
 * Built entirely from our own observations. The marketplace's advertised
 * reference price never appears here, because it is routinely inflated and would
 * turn a fake discount into a high score.
 */
final readonly class PriceHistoryAggregates
{
    public function __construct(
        public ?Money $minimum30Days,
        public ?Money $minimum60Days,
        public ?Money $minimum90Days,
        public ?Money $minimum180Days,
        public ?Money $average90Days,
        public ?Money $median90Days,
        public int $samplesLast30Days,
        public int $samplesTotal,
        public ?CarbonImmutable $historySince,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            minimum30Days: self::money($row['min_30'] ?? null),
            minimum60Days: self::money($row['min_60'] ?? null),
            minimum90Days: self::money($row['min_90'] ?? null),
            minimum180Days: self::money($row['min_180'] ?? null),
            average90Days: self::money($row['avg_90'] ?? null),
            median90Days: self::money($row['median_90'] ?? null),
            samplesLast30Days: (int) ($row['samples_30'] ?? 0),
            samplesTotal: (int) ($row['samples_total'] ?? 0),
            historySince: isset($row['history_since']) && is_string($row['history_since'])
                ? CarbonImmutable::parse($row['history_since'])
                : null,
        );
    }

    /**
     * A synthetic baseline that reproduces a known median and confidence.
     *
     * Used only by the weight simulator, which replays past evaluations through
     * the real rules. The sample counts below are the smallest values that yield
     * each confidence level under confidence() — they are a reconstruction, never
     * a measurement, and must not be persisted.
     */
    public static function replayed(?Money $median, PromotionConfidence $confidence): self
    {
        [$samples30, $historyDays] = match ($confidence) {
            PromotionConfidence::High => [40, 120],
            PromotionConfidence::Medium => [10, 60],
            PromotionConfidence::Low => [0, 0],
        };

        return new self(
            minimum30Days: $median,
            minimum60Days: $median,
            minimum90Days: $median,
            minimum180Days: $median,
            average90Days: $median,
            median90Days: $median,
            samplesLast30Days: $samples30,
            samplesTotal: $median === null ? 0 : max(1, $samples30),
            historySince: $historyDays === 0 ? null : CarbonImmutable::now()->subDays($historyDays),
        );
    }

    /**
     * How deep the history goes, in days.
     */
    public function historyDays(): int
    {
        if ($this->historySince === null) {
            return 0;
        }

        return (int) $this->historySince->diffInDays(CarbonImmutable::now());
    }

    /**
     * Confidence in this baseline, from sample density and history depth.
     *
     * Thin history yields Low, which bars automatic publication no matter how
     * high the promotion scores — a 90% discount computed from three data points
     * is not evidence of anything.
     *
     * The specification leaves one case open: at least 7 samples but between 30
     * and 60 days of history is neither its Low nor its Medium definition. It is
     * treated as Medium here, since the sample requirement is met and the depth
     * requirement only partially.
     */
    public function confidence(): PromotionConfidence
    {
        $historyDays = $this->historyDays();

        if ($this->samplesLast30Days < 7 || $historyDays < 30) {
            return PromotionConfidence::Low;
        }

        if ($this->samplesLast30Days > 30 && $historyDays >= 60) {
            return PromotionConfidence::High;
        }

        return PromotionConfidence::Medium;
    }

    public function hasHistory(): bool
    {
        return $this->samplesTotal > 0;
    }

    private static function money(mixed $value): ?Money
    {
        if ($value === null) {
            return null;
        }

        return Money::fromNumericString((string) $value);
    }
}
