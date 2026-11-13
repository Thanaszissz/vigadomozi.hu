<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\SeatLock;
use App\Models\Showtime;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Online és helyszíni (pénztári) értékesítés között ugyanaz a szék egy előadásra nem adható el kétszer. */
class SeatExclusivityTest extends TestCase
{
    use RefreshDatabase;

    private function showtime(): Showtime
    {
        $now = now();
        $movieId = DB::table('movies')->insertGetId(['title' => 'Teszt', 'created_at' => $now, 'updated_at' => $now]);
        $auditoriumId = DB::table('auditoria')->insertGetId(['name' => 'Terem', 'rows' => 3, 'columns' => 5, 'created_at' => $now, 'updated_at' => $now]);

        return Showtime::create([
            'movie_id' => $movieId,
            'auditorium_id' => $auditoriumId,
            'starts_at' => $now->copy()->addDay(),
            'status' => 'active',
            'pricing_override_json' => ['rows' => ['A' => 2000, 'B' => 3000]],
        ]);
    }

    private function customer(array $seatKeys): array
    {
        return ['email' => 'vevo@example.com', 'name' => 'Teszt Vevő', 'phone' => '+36301234567', 'seatKeys' => $seatKeys];
    }

    private function cashier(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Roles::CASHIER]), ['admin:access']);
    }

    public function test_pos_cannot_sell_a_seat_locked_online(): void
    {
        $showtime = $this->showtime();
        $this->postJson("/api/showtimes/{$showtime->id}/lock", $this->customer(['A-01']))->assertCreated();

        $this->cashier();
        $this->postJson('/api/pos/sell', ['showtime_id' => $showtime->id, 'seatKeys' => ['A-01'], 'payment_method' => 'cash'])->assertStatus(409);

        $this->assertSame(0, Ticket::count());
        $this->assertSame(1, Reservation::count());
    }

    public function test_pos_cannot_sell_a_seat_held_online_without_expiry(): void
    {
        $showtime = $this->showtime();
        $this->postJson("/api/showtimes/{$showtime->id}/hold", $this->customer(['A-02']))->assertOk();

        $this->cashier();
        $this->postJson('/api/pos/sell', ['showtime_id' => $showtime->id, 'seatKeys' => ['A-02'], 'payment_method' => 'cash'])->assertStatus(409);
        $this->postJson('/api/pos/hold', ['showtime_id' => $showtime->id, 'seatKeys' => ['A-02'], 'customer_name' => 'Pénztár', 'customer_phone' => '+36301234567'])->assertStatus(409);

        $this->assertSame(1, SeatLock::count());
    }

    public function test_online_cannot_take_a_seat_sold_at_the_box_office(): void
    {
        $showtime = $this->showtime();
        $this->cashier();
        $this->postJson('/api/pos/sell', ['showtime_id' => $showtime->id, 'seatKeys' => ['A-03'], 'payment_method' => 'cash'])->assertCreated();

        $this->postJson("/api/showtimes/{$showtime->id}/lock", $this->customer(['A-03']))->assertStatus(409);
        $this->postJson("/api/showtimes/{$showtime->id}/hold", $this->customer(['A-03']))->assertStatus(409);

        $this->assertSame(1, Reservation::count());
        $this->assertSame(1, SeatLock::count());
    }

    public function test_online_cannot_take_a_seat_held_at_the_box_office(): void
    {
        $showtime = $this->showtime();
        $this->cashier();
        $this->postJson('/api/pos/hold', ['showtime_id' => $showtime->id, 'seatKeys' => ['A-04'], 'customer_name' => 'Pénztár', 'customer_phone' => '+36301234567'])->assertSuccessful();

        $this->postJson("/api/showtimes/{$showtime->id}/lock", $this->customer(['A-04']))->assertStatus(409);
    }

    public function test_expired_online_lock_frees_the_seat_for_the_box_office(): void
    {
        $showtime = $this->showtime();
        $this->postJson("/api/showtimes/{$showtime->id}/lock", $this->customer(['A-05']))->assertCreated();

        $this->travel(11)->minutes();

        $this->cashier();
        $this->postJson('/api/pos/sell', ['showtime_id' => $showtime->id, 'seatKeys' => ['A-05'], 'payment_method' => 'cash'])->assertCreated();

        $this->assertSame(1, SeatLock::count());
        $this->assertNull(SeatLock::first()->expires_at);
    }

    public function test_late_online_payment_cannot_take_a_seat_sold_meanwhile(): void
    {
        $showtime = $this->showtime();
        $locked = Reservation::find($this->postJson("/api/showtimes/{$showtime->id}/lock", $this->customer(['A-01']))->assertCreated()->json('reservation.id'));

        $this->travel(11)->minutes();
        $this->cashier();
        $this->postJson('/api/pos/sell', ['showtime_id' => $showtime->id, 'seatKeys' => ['A-01'], 'payment_method' => 'cash'])->assertCreated();

        $this->expectException(\App\Exceptions\SeatUnavailableException::class);
        app(\App\Services\SeatClaimService::class)->finalizeClaims($locked->fresh());
    }

    public function test_the_same_seat_has_only_one_lock_row_per_showtime(): void
    {
        $showtime = $this->showtime();
        $this->postJson("/api/showtimes/{$showtime->id}/lock", $this->customer(['A-01']))->assertCreated();

        $this->expectException(\Illuminate\Database\QueryException::class);
        SeatLock::create(['showtime_id' => $showtime->id, 'reservation_id' => Reservation::first()->id, 'seat_key' => 'A-01', 'expires_at' => null]);
    }
}
