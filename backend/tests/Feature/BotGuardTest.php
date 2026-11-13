<?php

namespace Tests\Feature;

use App\Models\Showtime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BotGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['botguard.enabled' => true, 'botguard.min_age' => 0, 'botguard.difficulty' => 8]);
    }

    private function externalShowtime(): Showtime
    {
        $now = now();
        $movieId = DB::table('movies')->insertGetId(['title' => 'Film', 'created_at' => $now, 'updated_at' => $now]);
        $auditoriumId = DB::table('auditoria')->insertGetId(['name' => 'Nagyterem', 'created_at' => $now, 'updated_at' => $now]);

        return Showtime::create([
            'movie_id' => $movieId,
            'auditorium_id' => $auditoriumId,
            'starts_at' => $now->copy()->addDay(),
            'status' => 'active',
            'is_external' => true,
            'external_url' => 'https://jegy.example.test/eloadas',
        ]);
    }

    private function payload(array $extra = []): array
    {
        return ['email' => 'a@example.test', 'name' => 'Teszt', 'phone' => '+36301234567', 'seatKeys' => ['A-1']] + $extra;
    }

    /** @return array{token: string, solution: string} */
    private function solvedProof(): array
    {
        $challenge = $this->getJson('/api/bot-challenge')->assertOk()->json();

        for ($i = 0; ; $i++) {
            $hash = hash('sha256', $challenge['token'] . ':' . $i, true);
            $zeroBits = true;
            for ($b = 0; $b < $challenge['difficulty']; $b++) {
                if ((ord($hash[intdiv($b, 8)]) >> (7 - $b % 8)) & 1) {
                    $zeroBits = false;
                    break;
                }
            }
            if ($zeroBits) {
                return ['token' => $challenge['token'], 'solution' => (string) $i];
            }
        }
    }

    public function test_reservation_without_proof_is_rejected(): void
    {
        $showtime = $this->externalShowtime();

        foreach (['lock', 'hold'] as $action) {
            $this->postJson("/api/showtimes/{$showtime->id}/{$action}", $this->payload())
                ->assertStatus(422)
                ->assertJsonPath('code', 'captcha_failed');
        }
    }

    public function test_valid_proof_passes_but_cannot_be_reused(): void
    {
        $showtime = $this->externalShowtime();
        $proof = $this->solvedProof();

        // Átjut a védelmen (a külső előadás miatt a foglalás maga 422-t ad, de captcha-hiba nélkül)
        $first = $this->postJson("/api/showtimes/{$showtime->id}/hold", $this->payload(['captcha' => $proof]))->assertStatus(422);
        $this->assertNull($first->json('code'));

        $this->postJson("/api/showtimes/{$showtime->id}/hold", $this->payload(['captcha' => $proof]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'captcha_failed');
    }

    public function test_wrong_solution_and_tampered_token_are_rejected(): void
    {
        $showtime = $this->externalShowtime();
        $proof = $this->solvedProof();

        $this->postJson("/api/showtimes/{$showtime->id}/hold", $this->payload(['captcha' => ['token' => $proof['token'] . 'x', 'solution' => $proof['solution']]]))
            ->assertJsonPath('code', 'captcha_failed');

        config(['botguard.difficulty' => 24]);
        $hard = $this->getJson('/api/bot-challenge')->json();
        $this->postJson("/api/showtimes/{$showtime->id}/hold", $this->payload(['captcha' => ['token' => $hard['token'], 'solution' => '1']]))
            ->assertJsonPath('code', 'captcha_failed');
    }

    public function test_filled_honeypot_is_rejected_even_with_valid_proof(): void
    {
        $showtime = $this->externalShowtime();

        $this->postJson("/api/showtimes/{$showtime->id}/hold", $this->payload(['captcha' => $this->solvedProof(), 'website' => 'http://spam.example']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'captcha_failed');
    }
}
