<?php

namespace Tests\Feature;

use App\Models\Showtime;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Székek kizárása az árusításból a terem elrendezésében. */
class AuditoriumSeatBlockTest extends TestCase
{
    use RefreshDatabase;

    private function showtime(): Showtime
    {
        $now = now();
        $movieId = DB::table('movies')->insertGetId(['title' => 'Film', 'created_at' => $now, 'updated_at' => $now]);
        $auditoriumId = DB::table('auditoria')->insertGetId(['name' => 'Terem', 'rows' => 3, 'columns' => 5, 'created_at' => $now, 'updated_at' => $now]);

        return Showtime::create([
            'movie_id' => $movieId,
            'auditorium_id' => $auditoriumId,
            'starts_at' => now()->addDay(),
            'status' => 'active',
            'pricing_override_json' => ['rows' => ['A' => 2000]],
        ]);
    }

    private function layout(array $blockedKeys): array
    {
        $seats = [];
        foreach ([1, 2, 3] as $col) {
            $key = 'A-0' . $col;
            $seats[] = ['row' => 1, 'column' => $col, 'rowLabel' => 'A', 'key' => $key, 'blocked' => in_array($key, $blockedKeys, true), 'sector' => null, 'price' => null, 'section' => null];
        }

        return ['seats' => $seats, 'sectors' => [], 'sections' => [], 'rows' => [['label' => 'A', 'columns' => 3, 'section' => null]]];
    }

    public function test_blocked_seat_cannot_be_sold_and_is_excluded_from_capacity(): void
    {
        $manager = User::factory()->create(['role' => Roles::ADMIN]);
        Sanctum::actingAs($manager, ['admin:access']);
        $showtime = $this->showtime();

        $this->putJson("/api/auditoria/{$showtime->auditorium_id}/seats", $this->layout(['A-02']))
            ->assertOk()
            ->assertJsonPath('auditorium.total_seats', 2);

        $this->postJson('/api/pos/hold', [
            'showtime_id' => $showtime->id,
            'seatKeys' => ['A-02'],
            'customer_name' => 'Kiss Pál',
            'customer_phone' => '+36301234567',
        ])->assertStatus(422);
    }

    public function test_seat_with_an_upcoming_sale_cannot_be_blocked(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Roles::ADMIN]), ['admin:access']);
        $showtime = $this->showtime();

        $this->postJson('/api/pos/hold', [
            'showtime_id' => $showtime->id,
            'seatKeys' => ['A-01'],
            'customer_name' => 'Kiss Pál',
            'customer_phone' => '+36301234567',
        ])->assertSuccessful();

        $this->putJson("/api/auditoria/{$showtime->auditorium_id}/seats", $this->layout(['A-01']))->assertStatus(422);
        $this->putJson("/api/auditoria/{$showtime->auditorium_id}/seats", $this->layout(['A-03']))->assertOk();
    }
}
