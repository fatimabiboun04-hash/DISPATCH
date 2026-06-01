<?php

namespace App\Providers;

use App\Models\Planning;
use App\Observers\PlanningObserver;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [];

    public function boot(): void
    {
        Planning::observe(PlanningObserver::class);
    }
}
