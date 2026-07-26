<?php

declare(strict_types=1);

namespace App\Application\Actions;

final readonly class ProcessProductBatchResult
{
    /**
     * @param  list<int>  $recordedProductIds  products whose price actually moved
     */
    public function __construct(
        public int $productsUpserted,
        public int $observationsRecorded,
        public array $recordedProductIds = [],
    ) {}

    /**
     * Observations skipped because the price had not moved and the heartbeat had
     * not elapsed. Worth surfacing: a run where this is near zero usually means
     * the heartbeat is misconfigured and the history table is being flooded.
     */
    public function observationsSkipped(): int
    {
        return max(0, $this->productsUpserted - $this->observationsRecorded);
    }
}
