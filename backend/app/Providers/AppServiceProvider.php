<?php

namespace App\Providers;

use App\Models\Auditorium;
use App\Models\EmailTemplate;
use App\Models\Movie;
use App\Models\Reservation;
use App\Models\SeatLock;
use App\Models\Showtime;
use App\Models\TheaterShow;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Support\ResponseCache;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // A Plesk jailben (CLI) és a webszerveren (FPM) a projekt útvonala eltér. A gyorsítótárazott konfiguráció a
        // jailes útvonalakat tartalmazná, ezért az útvonalakat futásidőben, a tényleges könyvtárból állítjuk be.
        $storage = storage_path();
        config([
            'view.paths' => [resource_path('views')],
            'view.compiled' => $storage . '/framework/views',
            'cache.stores.file.path' => $storage . '/framework/cache/data',
            'cache.stores.file.lock_path' => $storage . '/framework/cache/data',
            'filesystems.disks.local.root' => $storage . '/app/private',
            'filesystems.disks.public.root' => $storage . '/app/public',
            'filesystems.links' => [public_path('storage') => $storage . '/app/public'],
            'logging.channels.single.path' => $storage . '/logs/laravel.log',
            'logging.channels.daily.path' => $storage . '/logs/laravel.log',
            'logging.channels.emergency.path' => $storage . '/logs/laravel.log',
            'mail.markdown.paths' => [resource_path('views/vendor/mail')],
            'session.files' => $storage . '/framework/sessions',
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([Movie::class, TheaterShow::class, Showtime::class, Auditorium::class, User::class, EmailTemplate::class] as $model) {
            $model::observe(AuditObserver::class);
        }

        // a publikus listák gyorsítótára azonnal törlődik, ha film, előadás, időpont vagy terem változik
        foreach ([Movie::class, TheaterShow::class, Showtime::class, Auditorium::class] as $model) {
            $model::saved(fn () => ResponseCache::flush());
            $model::deleted(fn () => ResponseCache::flush());
        }

        // egy előadás nézőterének gyorsítótára törlődik, ha zárolás vagy foglalás változik
        SeatLock::saved(fn (SeatLock $lock) => ResponseCache::forgetShowtime($lock->showtime_id));
        SeatLock::deleted(fn (SeatLock $lock) => ResponseCache::forgetShowtime($lock->showtime_id));
        Reservation::saved(fn (Reservation $reservation) => ResponseCache::forgetShowtime($reservation->showtime_id));
        Reservation::deleted(fn (Reservation $reservation) => ResponseCache::forgetShowtime($reservation->showtime_id));
    }
}
