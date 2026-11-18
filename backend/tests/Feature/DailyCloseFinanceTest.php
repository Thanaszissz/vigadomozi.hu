<?php

namespace Tests\Feature;

use App\Models\Showtime;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A napi zárás jegyei jelzik, hogy színházi / koncert előadásra szólnak-e (pénzügyi kimutatás). */
class DailyCloseFinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_close_tickets_carry_the_theater_flag(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Roles::ADMIN]), ['admin:access']);

        $now = now();
        $movieId = DB::table('movies')->insertGetId(['title' => 'Film', 'created_at' => $now, 'updated_at' => $now]);
        $auditoriumId = DB::table('auditoria')->insertGetId(['name' => 'Terem', 'rows' => 3, 'columns' => 5, 'created_at' => $now, 'updated_at' => $now]);
        $showtime = Showtime::create([
            'movie_id' => $movieId,
            'auditorium_id' => $auditoriumId,
            'starts_at' => now()->addDay(),
            'status' => 'active',
            'pricing_override_json' => ['rows' => ['A' => 2000]],
        ]);

        $id = (int) $this->postJson('/api/pos/hold', [
            'showtime_id' => $showtime->id,
            'seatKeys' => ['A-01'],
            'customer_name' => 'Kiss Pál',
            'customer_phone' => '+36301234567',
        ])->assertSuccessful()->json('reservation.id');
        $this->postJson("/api/pos/reservations/{$id}/pay", ['payment_method' => 'cash'])->assertSuccessful();

        $ticket = $this->getJson('/api/tickets/daily-close')->assertOk()->json('sellers.0.tickets.0');
        $this->assertFalse($ticket['is_theater']);
        $this->assertArrayNotHasKey('showtime', $ticket);
    }
}
