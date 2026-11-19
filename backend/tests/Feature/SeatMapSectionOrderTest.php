<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Showtime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A teremnél megadott szintsorrend a nyilvános nézőtér-adatban is megjelenik. */
class SeatMapSectionOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_seat_map_exposes_the_configured_section_order(): void
    {
        $now = now();
        $auditoriumId = DB::table('auditoria')->insertGetId([
            'name' => 'Terem', 'rows' => 1, 'columns' => 2, 'created_at' => $now, 'updated_at' => $now,
            'layout_json' => json_encode([
                'seats' => [],
                'sections' => [['name' => 'Emelet'], ['name' => 'Földszint', 'inline' => false], ['name' => 'Pótszék', 'inline' => true]],
                'rows' => [['label' => '1', 'section' => 'Földszint', 'columns' => 2], ['label' => '1', 'section' => 'Emelet', 'columns' => 2]],
            ]),
        ]);
        $showtime = Showtime::create([
            'movie_id' => Movie::create(['title' => 'Film'])->id,
            'auditorium_id' => $auditoriumId,
            'starts_at' => $now->copy()->addDay(),
            'status' => 'active',
        ]);

        $this->getJson("/api/showtimes/{$showtime->id}")
            ->assertOk()
            ->assertJsonPath('seatMap.layout.sectionOrder', ['Emelet', 'Földszint', 'Pótszék']);
    }
}
