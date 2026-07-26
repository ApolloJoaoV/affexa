<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\Database\ConfigureConnectionTimeouts;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Telescope\Telescope;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerTelescope();
    }

    /**
     * Telescope records a large volume of data and ships as a dev dependency, so
     * it is registered only when present and explicitly enabled. In production
     * it stays off unless TELESCOPE_ENABLED is set, and access is then gated by
     * the viewTelescope gate.
     */
    private function registerTelescope(): void
    {
        if (! class_exists(Telescope::class)) {
            return;
        }

        if (! (bool) config('telescope.enabled', false)) {
            return;
        }

        $this->app->register(TelescopeServiceProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(ConnectionEstablished::class, [ConfigureConnectionTimeouts::class, 'handle']);
    }
}
