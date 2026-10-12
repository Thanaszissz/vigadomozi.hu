<?php

use App\Services\BackupService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cron-nal is hívható (php artisan schedule:run); ha esedékes, lefuttatja az automatikus adatbázis-mentést
Artisan::command('backups:run', function (BackupService $backups) {
    $meta = $backups->runIfDue();
    $this->info($meta ? "Mentés készült: {$meta['id']}" : 'Nincs esedékes mentés.');
})->purpose('Run the scheduled database backup if it is due');

Schedule::command('backups:run')->everyMinute()->withoutOverlapping();
