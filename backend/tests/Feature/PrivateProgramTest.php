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

/** Zártkörű filmek / előadások: a nyilvános felületen rejtettek, a helyszíni jegyeladásban elérhetők. */
class PrivateProgramTest extends TestCase
{
    use RefreshDatabase;

    private function showtimeFor(bool $theater, bool $private): Showtime
    {
        $now = now();
        $auditoriumId = DB::table('auditoria')->insertGetId(['name' => 'Terem', 'rows' => 3, 'columns' => 5, 'created_at' => $now, 'updated_at' => $now]);
        $program = $theater
            ? TheaterShow::create(['title' => 'Előadás', 'is_private' => $private])
            : Movie::create(['title' => 'Film', 'is_private' => $private]);

        return Showtime::create([
            $theater ? 'theater_show_id' : 'movie_id' => $program->id,
            'auditorium_id' => $auditoriumId,
            'starts_at' => $now->copy()->addDay(),
            'status' => 'active',
        ]);
    }

    public function test_private_programs_are_hidden_from_public_lists(): void
    {
        $publicMovie = $this->showtimeFor(false, false);
        $privateMovie = $this->showtimeFor(false, true);
        $publicShow = $this->showtimeFor(true, false);
        $privateShow = $this->showtimeFor(true, true);

        $this->getJson('/api/movies')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $publicMovie->movie_id);
        $this->getJson('/api/theater-shows')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $publicShow->theater_show_id);

        $ids = collect($this->getJson('/api/showtimes')->assertOk()->json())->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$publicMovie->id, $publicShow->id], $ids);

        $this->getJson('/api/showtimes?include_private=1')->assertOk()->assertJsonCount(2);
        $this->getJson("/api/movies/{$privateMovie->movie_id}")->assertNotFound();
        $this->getJson("/api/theater-shows/{$privateShow->theater_show_id}")->assertNotFound();
        $this->getJson("/api/showtimes/{$privateMovie->id}")->assertNotFound();
        $this->getJson("/api/showtimes/{$privateShow->id}")->assertNotFound();
    }

    public function test_private_showtime_cannot_be_reserved_publicly(): void
    {
        $showtime = $this->showtimeFor(false, true);
        $payload = ['email' => 'vevo@example.com', 'name' => 'Teszt Vevő', 'phone' => '+36301234567', 'seatKeys' => ['A-01']];

        $this->postJson("/api/showtimes/{$showtime->id}/lock", $payload)->assertNotFound();
        $this->postJson("/api/showtimes/{$showtime->id}/hold", $payload)->assertNotFound();
    }

    public function test_cashier_sees_private_programs_in_the_pos_list(): void
    {
        $public = $this->showtimeFor(false, false);
        $private = $this->showtimeFor(true, true);

        Sanctum::actingAs(User::factory()->create(['role' => Roles::CASHIER]), ['admin:access']);

        $ids = collect($this->getJson('/api/showtimes?include_private=1')->assertOk()->json())->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$public->id, $private->id], $ids);
        $this->getJson("/api/showtimes/{$private->id}")->assertOk();

        // a kapcsoló nélkül a pénztáros is a nyilvános listát kapja
        $this->getJson('/api/showtimes')->assertOk()->assertJsonCount(1);
    }

    public function test_admin_can_mark_a_program_private(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Roles::ADMIN]), ['admin:access']);

        $this->postJson('/api/movies-admin', ['title' => 'Film', 'is_private' => '1'])->assertCreated()->assertJsonPath('is_private', true);
        $this->postJson('/api/theater-shows-admin', ['title' => 'Előadás', 'description' => 'Leírás', 'is_private' => '0'])->assertCreated()->assertJsonPath('is_private', false);
    }
}
