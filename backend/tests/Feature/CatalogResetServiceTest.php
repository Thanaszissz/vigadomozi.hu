<?php

namespace Tests\Feature;

use App\Exceptions\CatalogResetException;
use App\Models\User;
use App\Services\CatalogResetService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogResetServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_removes_program_and_reservation_data_but_preserves_sold_tickets(): void
    {
        $user = User::factory()->create();
        $this->seedProgramWithReservation();
        $service = app(CatalogResetService::class);
        $prepared = $service->prepare($user);

        $summary = $service->apply($user, $prepared['plan']['token'], CatalogResetService::CONFIRM_PHRASE, $prepared['email_code']);

        $this->assertSame([
            'movies' => 1,
            'theater_shows' => 1,
            'showtimes' => 2,
            'reservations' => 1,
        ], $summary);
        $this->assertDatabaseCount('movies', 0);
        $this->assertDatabaseCount('theater_shows', 0);
        $this->assertDatabaseCount('showtimes', 0);
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('reservation_items', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('tickets', ['id' => 1, 'reservation_id' => null, 'showtime_id' => null]);
    }

    public function test_reset_rejects_wrong_phrase_or_email_code_without_deleting_data(): void
    {
        $user = User::factory()->create();
        $this->seedProgramWithReservation();
        $service = app(CatalogResetService::class);
        $prepared = $service->prepare($user);

        try {
            $service->apply($user, $prepared['plan']['token'], 'HIBÁS', $prepared['email_code']);
            $this->fail('A hibás megerősítő szöveget el kellett volna utasítani.');
        } catch (CatalogResetException) {
            $this->assertDatabaseCount('movies', 1);
        }

        try {
            $service->apply($user, $prepared['plan']['token'], CatalogResetService::CONFIRM_PHRASE, '000000');
            $this->fail('A hibás email-kódot el kellett volna utasítani.');
        } catch (CatalogResetException) {
            $this->assertDatabaseCount('movies', 1);
        }
    }

    public function test_reset_requires_a_new_confirmation_if_program_changes_after_prepare(): void
    {
        $user = User::factory()->create();
        $this->seedProgramWithReservation();
        $service = app(CatalogResetService::class);
        $prepared = $service->prepare($user);

        DB::table('movies')->insert([
            'title' => 'Új film',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(CatalogResetException::class);
        $service->apply($user, $prepared['plan']['token'], CatalogResetService::CONFIRM_PHRASE, $prepared['email_code']);
    }

    public function test_settings_api_requires_password_and_email_code_without_exposing_the_code(): void
    {
        $user = User::factory()->create([
            'role' => Roles::ADMIN,
            'email' => 'catalog-admin@example.test',
            'password' => Hash::make('catalog-password'),
        ]);
        Sanctum::actingAs($user, ['admin:access']);
        config(['mail.default' => 'smtp']);
        $emailBody = '';
        Mail::shouldReceive('raw')->once()->withArgs(function (string $text) use (&$emailBody): bool {
            $emailBody = $text;

            return true;
        });

        $prepared = $this->postJson('/api/settings/catalog-reset/prepare', ['password' => 'catalog-password'])
            ->assertOk()
            ->assertJsonMissingPath('email_code');

        $this->assertSame('c************@example.test', $prepared->json('email_hint'));
        preg_match('/kód: (\d{6})/u', $emailBody, $matches);
        $this->assertArrayHasKey(1, $matches);

        $this->postJson('/api/settings/catalog-reset/apply', [
            'token' => $prepared->json('token'),
            'phrase' => CatalogResetService::CONFIRM_PHRASE,
            'email_code' => $matches[1],
            'password' => 'catalog-password',
        ])->assertOk()->assertJsonPath('status', 'applied');
    }

    private function seedProgramWithReservation(): void
    {
        $now = now();
        $movieId = DB::table('movies')->insertGetId(['title' => 'Film', 'created_at' => $now, 'updated_at' => $now]);
        $showId = DB::table('theater_shows')->insertGetId(['title' => 'Színházi előadás', 'created_at' => $now, 'updated_at' => $now]);
        $auditoriumId = DB::table('auditoria')->insertGetId(['name' => 'Nagyterem', 'created_at' => $now, 'updated_at' => $now]);
        $movieShowtimeId = DB::table('showtimes')->insertGetId([
            'movie_id' => $movieId,
            'auditorium_id' => $auditoriumId,
            'starts_at' => $now,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('showtimes')->insert([
            'movie_id' => null,
            'theater_show_id' => $showId,
            'auditorium_id' => $auditoriumId,
            'starts_at' => $now,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $reservationId = DB::table('reservations')->insertGetId([
            'showtime_id' => $movieShowtimeId,
            'customer_email' => 'customer@example.test',
            'status' => 'PENDING',
            'total_amount' => 1000,
            'currency' => 'HUF',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('reservation_items')->insert([
            'reservation_id' => $reservationId,
            'seat_key' => 'A-1',
            'row_label' => 'A',
            'seat_number' => 1,
            'price_amount' => 1000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('payments')->insert([
            'reservation_id' => $reservationId,
            'provider' => 'simplepay',
            'order_ref' => 'TEST-RESET-1',
            'status' => 'INIT',
            'amount' => 1000,
            'currency' => 'HUF',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('tickets')->insert([
            'id' => 1,
            'ticket_year' => 2026,
            'ticket_seq' => 1,
            'ticket_number' => '2026-000001',
            'reservation_id' => $reservationId,
            'showtime_id' => $movieShowtimeId,
            'movie_title' => 'Film',
            'starts_at' => $now,
            'seat_key' => 'A-1',
            'row_label' => 'A',
            'seat_number' => 1,
            'price_amount' => 1000,
            'currency' => 'HUF',
            'payment_method' => 'card',
            'channel' => 'online',
            'sold_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
