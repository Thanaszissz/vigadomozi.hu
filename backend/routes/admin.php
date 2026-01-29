<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AuditoriumController;
use App\Http\Controllers\Admin\MovieAdminController;
use App\Http\Controllers\Admin\ShowtimeAdminController;
use App\Http\Controllers\Admin\ReservationAdminController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\UserAdminController;

// User management
Route::apiResource('users', UserAdminController::class);

// Auditoria (terem) management
Route::apiResource('auditoria', AuditoriumController::class);
Route::get('auditoria/{auditorium}/seats', [AuditoriumController::class, 'getSeats']);
Route::put('auditoria/{auditorium}/seats', [AuditoriumController::class, 'updateSeats']);

// Movie management
Route::apiResource('movies-admin', MovieAdminController::class);
Route::post('movies-admin/{movie}/upload', [MovieAdminController::class, 'uploadPoster']);

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
