<?php

namespace Tests\Feature;

use App\Models\Auditorium;
use App\Models\Movie;
use App\Models\Showtime;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogImportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/catalog-import';

    private Auditorium $auditorium;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->create(['role' => Roles::ADMIN]), ['admin:access']);
        $this->auditorium = Auditorium::create(['name' => 'Nagyterem', 'rows' => 1, 'columns' => 1, 'total_seats' => 1]);
    }

    private function pngDataUri(): string
    {
        $im = imagecreatetruecolor(2400, 1200);
        ob_start();
        imagepng($im);
        return 'data:image/png;base64,' . base64_encode(ob_get_clean());
    }

    public function test_imports_movies_first_then_showtimes_with_arrays(): void
    {
        $payload = ['movies' => [[
            'title' => 'Teszt film',
            'duration_min' => 100,
            'rating' => 12,
            'badges' => ['premier'],
            'auditorium' => 'nagyterem',
            'showtimes' => [
                ['starts_at' => ['2030-01-01 17:00', '2030-01-02 17:00']],
                ['auditorium' => $this->auditorium->id, 'starts_at' => '2030-01-03 19:00'],
            ],
        ]]];

        $response = $this->postJson(self::URL, ['data' => $payload])->assertOk();

        $response->assertJsonPath('summary.movies_created', 1)->assertJsonPath('summary.showtimes_created', 3);
        $this->assertSame(1, Movie::count());
        $this->assertSame(3, Showtime::where('movie_id', Movie::first()->id)->count());
        // a magyar helyi idő (téli: UTC+1) UTC-ben tárolódik, ahogy a felület is küldi
        $this->assertSame('2030-01-01 16:00:00', Showtime::orderBy('starts_at')->first()->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2030-01-01 17:00', $response->json('movies.0.showtimes.0.starts_at'));

        // ismételt feltöltés nem duplikál
        $again = $this->postJson(self::URL, ['data' => $payload])->assertOk();
        $again->assertJsonPath('summary.movies_updated', 1)->assertJsonPath('summary.showtimes_skipped', 3);
        $this->assertSame(1, Movie::count());
        $this->assertSame(3, Showtime::count());
    }

    public function test_dry_run_writes_nothing_and_reports_errors(): void
    {
        $response = $this->postJson(self::URL, [
            'dry_run' => true,
            'data' => [
                ['title' => 'A', 'showtimes' => [['auditorium' => 'Nagyterem', 'starts_at' => '2030-01-01 10:00']]],
                ['title' => '', 'rating' => '99'],
                ['title' => 'B', 'showtimes' => [['auditorium' => 'Nincs ilyen', 'starts_at' => '2030-01-01 10:00']]],
            ],
        ])->assertOk();

        $response->assertJsonPath('summary.movies_created', 2)
            ->assertJsonPath('summary.movies_failed', 1)
            ->assertJsonPath('summary.showtimes_created', 1)
            ->assertJsonPath('summary.showtimes_failed', 1);
        $this->assertSame(0, Movie::count());
        $this->assertSame(0, Showtime::count());
    }

    public function test_rejects_invalid_payload(): void
    {
        $this->postJson(self::URL, ['data' => ['semmi' => 1]])->assertStatus(422);
    }

    public function test_poster_is_converted_to_webp(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP nem elérhető.');
        }

        $response = $this->postJson(self::URL, ['data' => [['title' => 'Plakátos', 'poster' => $this->pngDataUri()]]])->assertOk();

        $response->assertJsonPath('movies.0.poster.status', 'converted')->assertJsonPath('movies.0.poster.width', 1600);
        $files = Storage::disk('public')->files('posters');
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.webp', $files[0]);
        $this->assertStringContainsString('/storage/posters/', Movie::first()->poster_path);
        $this->assertSame('RIFF', substr(Storage::disk('public')->get($files[0]), 0, 4));
    }

    public function test_internal_urls_are_refused(): void
    {
        $response = $this->postJson(self::URL, ['data' => [['title' => 'SSRF', 'poster' => 'http://127.0.0.1/x.png']]])->assertOk();

        $response->assertJsonPath('movies.0.poster.status', 'failed')->assertJsonPath('summary.movies_created', 1);
        $this->assertNull(Movie::first()->poster_path);
    }

    public function test_sample_is_importable_in_dry_run(): void
    {
        $sample = $this->getJson(self::URL . '/sample')->assertOk()->json();

        $this->postJson(self::URL, ['data' => $sample, 'dry_run' => true])
            ->assertOk()
            ->assertJsonPath('summary.movies_failed', 0)
            ->assertJsonPath('summary.showtimes_failed', 0)
            ->assertJsonPath('summary.showtimes_created', 6);
    }
}
