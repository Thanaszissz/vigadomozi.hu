<?php

namespace App\Providers;

use App\Models\Auditorium;
use App\Models\EmailTemplate;
use App\Models\Movie;
use App\Models\Showtime;
use App\Models\TheaterShow;
use App\Models\User;
use App\Observers\AuditObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([Movie::class, TheaterShow::class, Showtime::class, Auditorium::class, User::class, EmailTemplate::class] as $model) {
            $model::observe(AuditObserver::class);
        }
    }
}
