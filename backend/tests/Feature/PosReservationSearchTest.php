<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Showtime;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A pénztár foglalási szám alapján megtalálja a függő foglalást és az előadását. */
class PosReservationSearchTest extends TestCase
{
    use RefreshDatabase;

    private function showtime(string $title, $startsAt): Showtime
    {
        $now = now();
        $movieId = DB::table('movies')->insertGetId(['title' => $title, 'created_at' => $now, 'updated_at' => $now]);
        $auditoriumId = DB::table('auditoria')->insertGetId(['name' => 'Terem', 'rows' => 3, 'columns' => 5, 'created_at' => $now, 'updated_at' => $now]);

        return Showtime::create([
            'movie_id' => $movieId,
            'auditorium_id' => $auditoriumId,
            'starts_at' => $startsAt,
            'status' => 'active',
            'pricing_override_json' => ['rows' => ['A' => 2000, 'B' => 3000]],
        ]);
    }

    private function hold(Showtime $showtime, string $seat): int
    {
        return (int) $this->postJson('/api/pos/hold', [
            'showtime_id' => $showtime->id,
            'seatKeys' => [$seat],
            'customer_name' => 'Kiss Pál',
            'customer_phone' => '+36301234567',
        ])->assertSuccessful()->json('reservation.id');
    }

    public function test_search_finds_pending_reservation_by_number_prefix(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Roles::CASHIER]), ['admin:access']);
        $showtime = $this->showtime('Keresett film', now()->addDay());
        $id = $this->hold($showtime, 'A-01');

        $hits = $this->getJson('/api/pos/reservations/search?q=' . substr((string) $id, 0, 1))->assertOk()->json();
        $this->assertContains($id, array_column($hits, 'id'));

        $hit = collect($hits)->firstWhere('id', $id);
        $this->assertSame($showtime->id, $hit['showtime_id']);
        $this->assertSame('Keresett film', $hit['movie_title']);
        $this->assertSame(['A-01'], $hit['seat_keys']);

        $this->getJson('/api/pos/reservations/search?q=%23' . $id)->assertOk()->assertJsonPath('0.id', $id);
    }

    public function test_search_ignores_paid_reservations_and_past_showtimes(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Roles::CASHIER]), ['admin:access']);
        $future = $this->showtime('Jövő', now()->addDay());
        $past = $this->showtime('Múlt', now()->subDay());

        $paidId = $this->hold($future, 'A-02');
        Reservation::whereKey($paidId)->update(['status' => 'PAID']);

        $pastId = $this->hold($future, 'A-03');
        Reservation::whereKey($pastId)->update(['showtime_id' => $past->id]);

        foreach ([$paidId, $pastId] as $id) {
            $this->assertNotContains($id, array_column($this->getJson('/api/pos/reservations/search?q=' . $id)->json(), 'id'));
        }
    }

    public function test_search_without_digits_returns_nothing(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Roles::CASHIER]), ['admin:access']);

        $this->getJson('/api/pos/reservations/search?q=abc')->assertOk()->assertExactJson([]);
        $this->getJson('/api/pos/reservations/search')->assertOk()->assertExactJson([]);
    }
}
