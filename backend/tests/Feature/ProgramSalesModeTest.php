<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Showtime;
use App\Models\TheaterShow;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Ingyenes (csak foglalható) és „csak vásárlás” (nem foglalható) filmek / előadások a nyilvános oldalon. */
class ProgramSalesModeTest extends TestCase
{
    use RefreshDatabase;

    private function showtimeFor(array $flags, bool $theater = false): Showtime
    {
        $now = now();
        $auditoriumId = DB::table('auditoria')->insertGetId(['name' => 'Terem', 'rows' => 3, 'columns' => 5, 'created_at' => $now, 'updated_at' => $now]);
        $program = $theater
            ? TheaterShow::create(['title' => 'Előadás'] + $flags)
            : Movie::create(['title' => 'Film'] + $flags);

        return Showtime::create([
            $theater ? 'theater_show_id' : 'movie_id' => $program->id,
            'auditorium_id' => $auditoriumId,
            'starts_at' => $now->copy()->addDay(),
            'status' => 'active',
        ]);
    }

    private function customer(string $seat): array
    {
        return ['email' => 'vevo@example.com', 'name' => 'Teszt Vevő', 'phone' => '+36301234567', 'seatKeys' => [$seat]];
    }

    public function test_free_program_can_only_be_held(): void
    {
        foreach ([false, true] as $theater) {
            $showtime = $this->showtimeFor(['is_free' => true], $theater);

            $this->postJson("/api/showtimes/{$showtime->id}/lock", $this->customer('A-01'))->assertStatus(422);
            $this->postJson("/api/showtimes/{$showtime->id}/hold", $this->customer('A-01'))->assertOk();
        }
    }

    public function test_free_program_reservation_cannot_be_paid_online(): void
    {
        $showtime = $this->showtimeFor(['is_free' => true]);
        $id = $this->postJson("/api/showtimes/{$showtime->id}/hold", $this->customer('A-01'))->assertOk()->json('reservation.id');

        $this->postJson("/api/reservations/{$id}/pay")->assertStatus(422);
    }

    public function test_purchase_only_program_cannot_be_held(): void
    {
        foreach ([false, true] as $theater) {
            $showtime = $this->showtimeFor(['purchase_only' => true], $theater);

            $this->postJson("/api/showtimes/{$showtime->id}/hold", $this->customer('A-01'))->assertStatus(422);
            $this->postJson("/api/showtimes/{$showtime->id}/lock", $this->customer('A-01'))->assertCreated();
        }
    }

    public function test_public_showtime_exposes_the_flags(): void
    {
        $theaterShowtime = $this->showtimeFor(['is_free' => true], true);
        $movieShowtime = $this->showtimeFor(['purchase_only' => true]);

        $this->getJson("/api/showtimes/{$theaterShowtime->id}")->assertOk()->assertJsonPath('showtime.movie.is_free', true);
        $this->getJson("/api/showtimes/{$movieShowtime->id}")->assertOk()->assertJsonPath('showtime.movie.purchase_only', true);
    }

    public function test_admin_cannot_mark_a_program_both_free_and_purchase_only(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Roles::ADMIN]), ['admin:access']);

        $this->postJson('/api/movies-admin', ['title' => 'Film', 'is_free' => '1', 'purchase_only' => '1'])->assertStatus(422);
        $this->postJson('/api/movies-admin', ['title' => 'Film', 'is_free' => '1', 'purchase_only' => '0'])->assertCreated()->assertJsonPath('is_free', true);
    }
}
