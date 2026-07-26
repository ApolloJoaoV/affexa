<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketplace;

use App\Domain\Marketplace\Contracts\MarketplaceConnector;
use App\Domain\Marketplace\Exceptions\ConnectorException;
use App\Models\Marketplace;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the connector for a marketplace.
 *
 * The class name comes from the marketplaces row and is instantiated through the
 * container. There is deliberately no match statement here: registering a new
 * marketplace is an INSERT plus a class, never an edit to this file, which is the
 * whole promise of the connector architecture.
 */
final class MarketplaceConnectorManager
{
    /**
     * @var array<int, MarketplaceConnector>
     */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @throws ConnectorException when the configured class is missing or unsuitable
     */
    public function for(Marketplace $marketplace): MarketplaceConnector
    {
        // Cached per marketplace: a connector holds an authenticated session, and
        // rebuilding it per call would re-read tokens on every request.
        if (isset($this->resolved[$marketplace->id])) {
            return $this->resolved[$marketplace->id];
        }

        $class = $marketplace->connector;

        if (! class_exists($class)) {
            throw new ConnectorException(
                "Marketplace [{$marketplace->slug}] is configured with connector [{$class}], which does not exist."
            );
        }

        if (! is_subclass_of($class, MarketplaceConnector::class)) {
            throw new ConnectorException(
                "Connector [{$class}] for [{$marketplace->slug}] does not implement ".MarketplaceConnector::class.'.'
            );
        }

        /** @var MarketplaceConnector $connector */
        $connector = $this->container->make($class, ['marketplace' => $marketplace]);

        return $this->resolved[$marketplace->id] = $connector;
    }

    public function forSlug(string $slug): MarketplaceConnector
    {
        $marketplace = Marketplace::query()->where('slug', $slug)->first();

        if ($marketplace === null) {
            throw new ConnectorException("No marketplace registered with slug [{$slug}].");
        }

        return $this->for($marketplace);
    }

    /**
     * Drops cached instances, so a test or a long running worker picks up a
     * credential change without a restart.
     */
    public function flush(): void
    {
        $this->resolved = [];
    }
}
