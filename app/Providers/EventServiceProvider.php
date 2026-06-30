<?php

namespace App\Providers;

use App\Models\Pause;
use App\Models\Planning;
use App\Models\Task;
use App\Observers\PauseObserver;
use App\Observers\PlanningObserver;
use App\Observers\TaskObserver;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [];

    public function boot(): void
    {
        Planning::observe(PlanningObserver::class);
        Task::observe(TaskObserver::class);
        Pause::observe(PauseObserver::class);
    }
}
