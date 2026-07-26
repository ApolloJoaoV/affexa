<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Actions\EvaluatePromotionAction;
use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Stage three: score a product and decide what happens to it.
 *
 * Unique per product for a short window, because a capture run and a manual
 * revalidation can easily land on the same product at the same moment, and the
 * second evaluation would be thrown away by the dedupe index anyway.
 */
final class EvaluatePromotionJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $maxExceptions = 2;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 120];

    public int $uniqueFor = 300;

    public function __construct(public readonly int $productId)
    {
        $this->onQueue('evaluate');
    }

    public function uniqueId(): string
    {
        return "evaluate-promotion:{$this->productId}";
    }

    public function handle(EvaluatePromotionAction $action): void
    {
        $product = Product::query()->with('marketplace')->find($this->productId);

        if ($product === null || $product->current_price === null) {
            // Deleted, or captured without a usable price; retrying changes neither.
            return;
        }

        $action->execute($product);
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['pipeline', 'evaluate', "product:{$this->productId}"];
    }
}
