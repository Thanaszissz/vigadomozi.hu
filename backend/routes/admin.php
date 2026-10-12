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

// User management
Route::apiResource('users', UserAdminController::class);

// Auditoria (terem) management
Route::apiResource('auditoria', AuditoriumController::class);
Route::get('auditoria/{auditorium}/seats', [AuditoriumController::class, 'getSeats']);
Route::put('auditoria/{auditorium}/seats', [AuditoriumController::class, 'updateSeats']);

// Movie management
Route::apiResource('movies-admin', MovieAdminController::class);
Route::post('movies-admin/{movie}/upload', [MovieAdminController::class, 'uploadPoster']);

// Theater show management
Route::apiResource('theater-shows-admin', TheaterShowAdminController::class)
    ->parameters(['theater-shows-admin' => 'theater_show']);
Route::post('theater-shows-admin/{theater_show}/upload', [TheaterShowAdminController::class, 'uploadPoster']);

// Showtime management
Route::apiResource('showtimes-admin', ShowtimeAdminController::class);

// Reservation queries and export
Route::get('reservations', [ReservationAdminController::class, 'index']);
Route::get('reservations/grouped', [ReservationAdminController::class, 'grouped']);
Route::post('reservations/{reservation}/start-payment', [ReservationAdminController::class, 'startPayment']);
Route::get('showtimes/{showtime}/reservations', [ReservationAdminController::class, 'index']);
Route::get('showtimes/{showtime}/reservations/export', [ReservationAdminController::class, 'export']);

// Reports
Route::get('reports/daily', [ReportController::class, 'dailyReport']);

// Helyszíni jegyeladás (pénztár)
Route::post('pos/sell', [PosController::class, 'sell']);
Route::post('pos/reservations/{reservation}/pay', [PosController::class, 'payReservation']);
Route::get('pos/reservations/{reservation}/tickets', [PosController::class, 'reservationTickets']);

// Tevékenységnapló (csak olvasható felület, törlés/módosítás nincs)
Route::get('activity-logs/meta', [ActivityLogController::class, 'meta']);
Route::get('activity-logs', [ActivityLogController::class, 'index']);
Route::post('activity-logs/event', [ActivityLogController::class, 'clientEvent']);

// Eladott jegyek évenkénti archívuma
Route::get('tickets/years', [TicketAdminController::class, 'years']);
Route::get('tickets/export', [TicketAdminController::class, 'export']);
Route::get('tickets/daily-close', [TicketAdminController::class, 'dailyClose']);
Route::get('tickets/sales-log', [TicketAdminController::class, 'salesLog']);
Route::get('tickets', [TicketAdminController::class, 'index']);
Route::get('tickets/{ticket}', [TicketAdminController::class, 'show'])->whereNumber('ticket');
