<?php

use App\Services\BackupService;
use App\Services\NavReceiptService;
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

Artisan::command('nav:run', function (NavReceiptService $nav) {
    $results = $nav->runDue();
    $this->info($results === null ? 'Nincs esedékes NAV beküldés.' : count($results) . ' nap feldolgozva.');
})->purpose('Send the daily NAV receipt data report if it is due');

Schedule::command('nav:run')->everyMinute()->withoutOverlapping();
