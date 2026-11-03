<?php

namespace Tests\Feature;

use App\Models\Showtime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExternalShowtimeTest extends TestCase
{
    use RefreshDatabase;

    private function showtime(array $attributes = []): Showtime
    {
        $now = now();
        $movieId = DB::table('movies')->insertGetId(['title' => 'Film', 'created_at' => $now, 'updated_at' => $now]);
        $auditoriumId = DB::table('auditoria')->insertGetId(['name' => 'Nagyterem', 'created_at' => $now, 'updated_at' => $now]);

        return Showtime::create($attributes + [
            'movie_id' => $movieId,
            'auditorium_id' => $auditoriumId,
            'starts_at' => $now->copy()->addDay(),
            'status' => 'active',
        ]);
    }

    public function test_public_listing_exposes_the_external_link(): void
    {
        $this->showtime(['is_external' => true, 'external_url' => 'https://jegy.example.test/eloadas']);

        $row = $this->getJson('/api/showtimes')->assertOk()->json('0');

        $this->assertTrue($row['is_external']);
        $this->assertSame('https://jegy.example.test/eloadas', $row['external_url']);
    }

    public function test_external_showtime_has_no_seat_map_and_cannot_be_locked(): void
    {
        $showtime = $this->showtime(['is_external' => true, 'external_url' => 'https://jegy.example.test/eloadas']);

        $this->getJson('/api/showtimes/' . $showtime->id)
            ->assertOk()
            ->assertJsonPath('seatMap', null)
            ->assertJsonPath('showtime.external_url', 'https://jegy.example.test/eloadas');

        $payload = ['email' => 'a@example.test', 'name' => 'Teszt', 'phone' => '+36301234567', 'seatKeys' => ['A-1']];
        $this->postJson('/api/showtimes/' . $showtime->id . '/lock', $payload)->assertStatus(422);
        $this->postJson('/api/showtimes/' . $showtime->id . '/hold', $payload)->assertStatus(422);
    }
}
