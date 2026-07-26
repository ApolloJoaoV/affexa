<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\FetchDealsJob;
use App\Models\Marketplace;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Queues a capture run for the marketplaces that are due.
 *
 * Dispatches rather than fetches: the command must return promptly whether one
 * marketplace or twenty are due, and the work belongs on the queue where it can be
 * retried and rate limited.
 */
final class FetchMarketplacesCommand extends Command
{
    protected $signature = 'promohub:marketplaces:fetch
                            {slug? : Only this marketplace, ignoring its interval}
                            {--max-items= : Cap the products captured in this run}
                            {--force : Dispatch even if the interval has not elapsed}';

    protected $description = 'Dispatch deal capture for marketplaces that are due';

    public function handle(): int
    {
        $slug = $this->argument('slug');
        $marketplaces = $this->targets(is_string($slug) ? $slug : null);

        if ($marketplaces->isEmpty()) {
            $this->components->info('No marketplace is due for capture.');

            return self::SUCCESS;
        }

        $maxItems = $this->option('max-items');

        foreach ($marketplaces as $marketplace) {
            FetchDealsJob::dispatch(
                $marketplace->id,
                $maxItems === null ? null : (int) $maxItems,
            );

            $this->components->twoColumnDetail($marketplace->slug, 'capture queued');
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Marketplace>
     */
    private function targets(?string $slug): Collection
    {
        $query = Marketplace::query()->where('is_active', true);

        if ($slug !== null) {
            return $query->where('slug', $slug)->get();
        }

        return $query
            /*
             * A marketplace whose breaker is open is skipped entirely: the circuit
             * exists so a dead API stops consuming queue capacity, and dispatching
             * to it anyway would defeat that.
             */
            ->where(function ($query): void {
                $query->whereNull('circuit_open_until')->orWhere('circuit_open_until', '<=', now());
            })
            ->when(! $this->option('force'), function ($query): void {
                // Due when never fetched, or when its own interval has elapsed.
                $query->where(function ($query): void {
                    $query->whereNull('last_fetched_at')
                        ->orWhereRaw("last_fetched_at <= now() - (fetch_interval_minutes || ' minutes')::interval");
                });
            })
            ->orderByRaw('last_fetched_at NULLS FIRST')
            ->get();
    }
}
