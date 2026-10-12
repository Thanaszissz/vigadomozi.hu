<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AuditoriumController;
use App\Http\Controllers\Admin\MovieAdminController;
use App\Http\Controllers\Admin\ShowtimeAdminController;
use App\Http\Controllers\Admin\ReservationAdminController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\UserAdminController;
use App\Http\Controllers\Admin\TheaterShowAdminController;
use App\Http\Controllers\Admin\PosController;
use App\Http\Controllers\Admin\TicketAdminController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\EmailTemplateController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\SimplePaySettingsController;
use App\Http\Controllers\Admin\NavController;

// A jogosultságokat a szerepkörök adják (App\Support\Roles); minden csoport a megfelelő jogot követeli meg.

// Felhasználók és szerepkörök (csak adminisztrátor)
Route::middleware('permission:users.manage')->group(function () {
    Route::get('roles', [UserAdminController::class, 'roles']);
    Route::apiResource('users', UserAdminController::class);
});

// Program: filmek, színházi előadások, előadások, termek (vezető, adminisztrátor)
Route::middleware('permission:catalog.manage')->group(function () {
    Route::apiResource('auditoria', AuditoriumController::class);
    Route::get('auditoria/{auditorium}/seats', [AuditoriumController::class, 'getSeats']);
    Route::put('auditoria/{auditorium}/seats', [AuditoriumController::class, 'updateSeats']);

    Route::apiResource('movies-admin', MovieAdminController::class);
    Route::post('movies-admin/{movie}/upload', [MovieAdminController::class, 'uploadPoster']);

    Route::apiResource('theater-shows-admin', TheaterShowAdminController::class)
        ->parameters(['theater-shows-admin' => 'theater_show']);
    Route::post('theater-shows-admin/{theater_show}/upload', [TheaterShowAdminController::class, 'uploadPoster']);

    Route::apiResource('showtimes-admin', ShowtimeAdminController::class);
});

// Foglalások kezelése és kifizetése (pénztáros, vezető, adminisztrátor)
Route::middleware('permission:reservations.manage')->group(function () {
    Route::get('reservations', [ReservationAdminController::class, 'index']);
    Route::get('reservations/grouped', [ReservationAdminController::class, 'grouped']);
    Route::post('reservations/{reservation}/start-payment', [ReservationAdminController::class, 'startPayment']);
    Route::get('showtimes/{showtime}/reservations', [ReservationAdminController::class, 'index']);
});

// Riportok és exportok (vezető, adminisztrátor)
Route::middleware('permission:reports.view')->group(function () {
    Route::get('showtimes/{showtime}/reservations/export', [ReservationAdminController::class, 'export']);
    Route::get('reports/daily', [ReportController::class, 'dailyReport']);
});

// Helyszíni jegyeladás (pénztáros, vezető, adminisztrátor)
Route::middleware('permission:pos.sell')->group(function () {
    Route::post('pos/sell', [PosController::class, 'sell']);
    Route::post('pos/hold', [PosController::class, 'hold']);
    Route::post('pos/reservations/{reservation}/pay', [PosController::class, 'payReservation']);
    Route::get('pos/reservations/{reservation}/tickets', [PosController::class, 'reservationTickets']);
});

// Beállítások: jegyszámozás (csak adminisztrátor; a módosítás jelszóval és kétlépcsős megerősítéssel védett)
Route::middleware('permission:settings.manage')->group(function () {
    Route::get('settings/ticket-numbering', [SettingsController::class, 'ticketNumbering']);
    Route::post('settings/ticket-numbering/prepare', [SettingsController::class, 'prepareTicketNumbering'])->middleware('throttle:10,1');
    Route::post('settings/ticket-numbering/apply', [SettingsController::class, 'applyTicketNumbering'])->middleware('throttle:10,1');

    // NAV nyugtaadat-szolgáltatás (KOBAK): kapcsolati adatok, napi automatikus és kézi beküldés
    Route::get('settings/nav', [NavController::class, 'show']);
    Route::put('settings/nav', [NavController::class, 'update'])->middleware('throttle:20,1,nav-save');
    Route::put('settings/nav/schedule', [NavController::class, 'updateSchedule']);
    Route::post('settings/nav/test', [NavController::class, 'test'])->middleware('throttle:20,1,nav-test');
    Route::post('settings/nav/software', [NavController::class, 'registerSoftware'])->middleware('throttle:10,1,nav-software');
    Route::get('settings/nav/preview', [NavController::class, 'preview']);
    Route::post('settings/nav/send/prepare', [NavController::class, 'prepareSend'])->middleware('throttle:15,1,nav-prepare');
    Route::post('settings/nav/send/apply', [NavController::class, 'applySend'])->middleware('throttle:15,1,nav-apply');

    // OTP SimplePay online fizetés: kapcsolati adatok, kapcsolat-ellenőrzés
    Route::get('settings/simplepay', [SimplePaySettingsController::class, 'show']);
    Route::put('settings/simplepay', [SimplePaySettingsController::class, 'update'])->middleware('throttle:20,1');
    Route::post('settings/simplepay/test', [SimplePaySettingsController::class, 'test'])->middleware('throttle:10,1');

    // Biztonsági mentés: teljes adatbázis-mentés, letöltés, feltöltés, ütemezés, többszörösen védett visszatöltés
    Route::get('settings/backups', [BackupController::class, 'index']);
    Route::post('settings/backups', [BackupController::class, 'store'])->middleware('throttle:10,1');
    Route::post('settings/backups/upload', [BackupController::class, 'upload'])->middleware('throttle:10,1');
    Route::put('settings/backups/schedule', [BackupController::class, 'updateSchedule']);
    Route::get('settings/backups/{id}/download', [BackupController::class, 'download'])->where('id', '[A-Za-z0-9_\-]+');
    Route::delete('settings/backups/{id}', [BackupController::class, 'destroy'])->where('id', '[A-Za-z0-9_\-]+');
    Route::post('settings/backups/{id}/restore/prepare', [BackupController::class, 'prepareRestore'])->where('id', '[A-Za-z0-9_\-]+')->middleware('throttle:10,1');
    Route::post('settings/backups/{id}/restore/apply', [BackupController::class, 'applyRestore'])->where('id', '[A-Za-z0-9_\-]+')->middleware('throttle:10,1');
});

// E-mail sablonok (vezető, adminisztrátor)
Route::middleware('permission:email.manage')->group(function () {
    Route::get('email-templates', [EmailTemplateController::class, 'index']);
    Route::put('email-templates/{key}', [EmailTemplateController::class, 'update']);
    Route::post('email-templates/{key}/reset', [EmailTemplateController::class, 'reset']);
    Route::post('email-templates/{key}/preview', [EmailTemplateController::class, 'preview']);
    Route::post('email-templates/{key}/test', [EmailTemplateController::class, 'test']);
});

// Tevékenységnapló (csak adminisztrátor; csak olvasható, törlés/módosítás nincs)
Route::middleware('permission:activity.view')->group(function () {
    Route::get('activity-logs/meta', [ActivityLogController::class, 'meta']);
    Route::get('activity-logs', [ActivityLogController::class, 'index']);
});
// A böngészőből bejelentett események (nyomtatás) bármely bejelentkezett felhasználótól jöhetnek
Route::post('activity-logs/event', [ActivityLogController::class, 'clientEvent']);

// Napi zárás és eladási napló: a pénztáros csak a saját eladásait látja, a vezető mindenkiét
Route::middleware('permission:daily_close.view')->group(function () {
    Route::get('tickets/daily-close', [TicketAdminController::class, 'dailyClose']);
    Route::get('tickets/sales-log', [TicketAdminController::class, 'salesLog']);
});

// Eladott jegyek évenkénti archívuma (vezető, adminisztrátor)
Route::middleware('permission:tickets.archive')->group(function () {
    Route::get('tickets/years', [TicketAdminController::class, 'years']);
    Route::get('tickets/export', [TicketAdminController::class, 'export']);
    Route::get('tickets', [TicketAdminController::class, 'index']);
    Route::get('tickets/{ticket}', [TicketAdminController::class, 'show'])->whereNumber('ticket');
});
