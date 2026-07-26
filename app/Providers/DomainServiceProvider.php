<?php

declare(strict_types=1);

namespace App\Providers;

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
    public array $singletons = [];
}
