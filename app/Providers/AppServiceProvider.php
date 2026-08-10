<?php

namespace App\Providers;

use App\Services\Monitoring\Contracts\ProbeDriver;
use App\Services\Monitoring\Drivers\FpingDriver;
use App\Services\Monitoring\SettingsService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProbeDriver::class, FpingDriver::class);
        $this->app->singleton(SettingsService::class);
    }

    public function boot(): void
    {
        //
    }
}
