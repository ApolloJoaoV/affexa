<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Promotion\Scoring\Rules\AllTimeLowRule;
use App\Domain\Promotion\Scoring\Rules\BelowHistoricalMedianRule;
use App\Domain\Promotion\Scoring\Rules\DiscountThresholdRule;
use App\Domain\Promotion\Scoring\Rules\FreeShippingRule;
use App\Domain\Promotion\Scoring\Rules\MarketplaceTrustRule;
use App\Domain\Promotion\Scoring\Rules\PrimeRule;
use App\Domain\Promotion\Scoring\Rules\RatingRule;
use App\Domain\Promotion\Scoring\Rules\ReviewVolumeRule;
use App\Domain\Promotion\Scoring\ScoringEngine;
use App\Infrastructure\Marketplace\MarketplaceConnectorManager;
use Illuminate\Support\ServiceProvider;

/**
 * Binds domain contracts to their infrastructure implementations.
 *
 * This is the only place where the two sides are wired together, which is what
 * keeps App\Domain free of framework and vendor types. Swapping an
 * implementation — a different card generator, a different WhatsApp provider —
 * is an edit to the map below and nothing else.
 */
final class DomainServiceProvider extends ServiceProvider
{
    /**
     * Contract to implementation bindings.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [];

    /**
     * Contract to implementation bindings resolved once per container.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        // Shared so its per-marketplace connector cache is not rebuilt on every
        // resolution; a connector carries an authenticated session.
        MarketplaceConnectorManager::class => MarketplaceConnectorManager::class,
    ];

    public function register(): void
    {
        /*
         * The rule set is assembled here rather than discovered, so the order and
         * the membership of the scoring engine are explicit and reviewable. Adding
         * a signal means adding a class and a line below — nothing else in the
         * system changes.
         */
        $this->app->singleton(ScoringEngine::class, fn (): ScoringEngine => new ScoringEngine([
            new DiscountThresholdRule,
            new BelowHistoricalMedianRule,
            new RatingRule,
            new ReviewVolumeRule,
            new PrimeRule,
            new FreeShippingRule,
            new MarketplaceTrustRule,
            new AllTimeLowRule,
        ]));
    }
}
