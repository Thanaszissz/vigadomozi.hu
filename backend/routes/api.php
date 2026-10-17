<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MovieController;
use App\Http\Controllers\Api\ShowtimeController;
use App\Http\Controllers\Api\SeatLockController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\SimplePayController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TheaterShowController;
use App\Http\Controllers\Api\SiteStatusController;
use App\Http\Middleware\BlockDuringMaintenance;
use App\Http\Middleware\StorePublicListCache;

// Auth routes (public)
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:30,1');

// Public routes
Route::get('/site-status', [SiteStatusController::class, 'show'])->middleware('throttle:120,1');
Route::get('/movies', [MovieController::class, 'index'])->middleware(StorePublicListCache::class);
Route::get('/movies/{movie}', [MovieController::class, 'show']);

Route::get('/theater-shows', [TheaterShowController::class, 'index'])->middleware(StorePublicListCache::class);
Route::get('/theater-shows/{theater_show}', [TheaterShowController::class, 'show']);

Route::get('/showtimes', [ShowtimeController::class, 'index'])->middleware(StorePublicListCache::class);
Route::get('/showtimes/{showtime}', [ShowtimeController::class, 'show'])->middleware(StorePublicListCache::class);

// Seat locking and reservation (a hold e-mailt is küld, ezért korlátozzuk a gyakoriságát)
Route::post('/showtimes/{showtime}/lock', [SeatLockController::class, 'lock'])->middleware([BlockDuringMaintenance::class, 'throttle:20,1']);
Route::post('/showtimes/{showtime}/hold', [SeatLockController::class, 'hold'])->middleware([BlockDuringMaintenance::class, 'throttle:10,1']);

// Reservation management
Route::get('/reservations/{reservation}', [ReservationController::class, 'show'])
    ->whereNumber('reservation')->middleware('throttle:60,1');
Route::get('/reservations/{reservation}/tickets', [ReservationController::class, 'tickets'])
    ->whereNumber('reservation')->middleware('throttle:30,1');
Route::post('/reservations/{reservation}/pay', [ReservationController::class, 'pay'])
    ->whereNumber('reservation')->middleware([BlockDuringMaintenance::class, 'throttle:20,1']);

// Protected routes (auth required)
Route::middleware(['auth:sanctum', 'abilities:admin:access'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Admin routes
    require __DIR__ . '/admin.php';
});
// Webhooks (CSRF exemption needed)
Route::post('/webhooks/stripe', [WebhookController::class, 'stripe'])
    ->withoutMiddleware('api');

// OTP SimplePay: a vásárló visszatérése és a szerver-szerver értesítés (IPN, aláírással hitelesítve)
Route::get('/payments/simplepay/return', [SimplePayController::class, 'back'])->middleware('throttle:60,1');
Route::post('/webhooks/simplepay', [SimplePayController::class, 'ipn']);
