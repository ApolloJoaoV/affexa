<?php

use App\Providers\AppServiceProvider;
use App\Providers\DomainServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    DomainServiceProvider::class,
    HorizonServiceProvider::class,
    /*
     * TelescopeServiceProvider is deliberately absent: Telescope is a dev-only
     * dependency, so listing it here would break boot on a production
     * `composer install --no-dev`. AppServiceProvider registers it only when the
     * package is actually installed.
     */
];
