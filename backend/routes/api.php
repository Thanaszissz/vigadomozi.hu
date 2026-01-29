<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MovieController;
use App\Http\Controllers\Api\ShowtimeController;
use App\Http\Controllers\Api\SeatLockController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\AuthController;

// Auth routes (public)
Route::post('/login', [AuthController::class, 'login']);

// Public routes
Route::get('/movies', [MovieController::class, 'index']);
Route::get('/movies/{movie}', [MovieController::class, 'show']);

Route::get('/showtimes', [ShowtimeController::class, 'index']);
Route::get('/showtimes/{showtime}', [ShowtimeController::class, 'show']);

// Seat locking and reservation
Route::post('/showtimes/{showtime}/lock', [SeatLockController::class, 'lock']);
Route::post('/showtimes/{showtime}/hold', [SeatLockController::class, 'hold']);

// Reservation management
Route::get('/reservations/{reservation}', [ReservationController::class, 'show'])
    ->whereNumber('reservation');
Route::post('/reservations/{reservation}/pay', [ReservationController::class, 'pay'])
    ->whereNumber('reservation');

// Protected routes (auth required)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Admin routes
    require __DIR__ . '/admin.php';
});
// Webhooks (CSRF exemption needed)
Route::post('/webhooks/stripe', [WebhookController::class, 'stripe'])
    ->withoutMiddleware('api');
