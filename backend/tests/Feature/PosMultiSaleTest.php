<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Showtime;
use App\Models\Ticket;
use App\Models\User;
use App\Support\PaymentSplit;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosMultiSaleTest extends TestCase
{
    use RefreshDatabase;

    private function showtime(string $title, array $attributes = []): Showtime
    {
        $now = now();
        $movieId = DB::table('movies')->insertGetId(['title' => $title, 'created_at' => $now, 'updated_at' => $now]);
        $auditoriumId = DB::table('auditoria')->insertGetId(['name' => 'Terem ' . $title, 'rows' => 3, 'columns' => 5, 'created_at' => $now, 'updated_at' => $now]);

        return Showtime::create($attributes + [
            'movie_id' => $movieId,
            'auditorium_id' => $auditoriumId,
            'starts_at' => $now->copy()->addDay(),
            'status' => 'active',
            'pricing_override_json' => ['rows' => ['A' => 2000, 'B' => 3000]],
        ]);
    }

    private function cashier(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Roles::CASHIER]), ['admin:access']);
    }

    public function test_one_transaction_sells_tickets_for_several_programs(): void
    {
        $this->cashier();
        $a = $this->showtime('Egyik');
        $b = $this->showtime('Masik');

        $response = $this->postJson('/api/pos/sell-multi', [
            'items' => [
                ['showtime_id' => $a->id, 'seatKeys' => ['A-01', 'A-02']],
                ['showtime_id' => $b->id, 'seatKeys' => ['B-01']],
            ],
            'payment_method' => 'card',
        ])->assertCreated();

        $this->assertCount(3, $response->json('tickets'));
        $this->assertCount(2, $response->json('reservations'));
        $this->assertSame(2, Reservation::where('status', 'PAID')->count());
        $this->assertSame([$a->id, $a->id, $b->id], Ticket::orderBy('id')->pluck('showtime_id')->all());
        $this->assertSame(7000, (int) Ticket::sum('price_amount'));
        $this->assertSame(['card' => 7000], PaymentSplit::amounts(Ticket::all()));
        $this->assertSame(3, Ticket::distinct()->count('ticket_number'));
    }

    public function test_split_payment_is_spread_over_all_programs(): void
    {
        $this->cashier();
        $a = $this->showtime('Egyik');
        $b = $this->showtime('Masik');

        $this->postJson('/api/pos/sell-multi', [
            'items' => [
                ['showtime_id' => $a->id, 'seatKeys' => ['A-01', 'A-02']],
                ['showtime_id' => $b->id, 'seatKeys' => ['B-01']],
            ],
            'payments' => [['method' => 'cash', 'amount' => 5000], ['method' => 'card', 'amount' => 2000]],
        ])->assertCreated();

        $this->assertSame(['cash' => 5000, 'card' => 2000], PaymentSplit::amounts(Ticket::all()));
    }

    public function test_wrong_split_total_rolls_everything_back(): void
    {
        $this->cashier();
        $a = $this->showtime('Egyik');
        $b = $this->showtime('Masik');

        $this->postJson('/api/pos/sell-multi', [
            'items' => [
                ['showtime_id' => $a->id, 'seatKeys' => ['A-01']],
                ['showtime_id' => $b->id, 'seatKeys' => ['B-01']],
            ],
            'payments' => [['method' => 'cash', 'amount' => 1000]],
        ])->assertStatus(422);

        $this->assertSame(0, Reservation::count());
        $this->assertSame(0, Ticket::count());
    }

    public function test_unavailable_seat_in_one_program_rolls_everything_back(): void
    {
        $this->cashier();
        $a = $this->showtime('Egyik');
        $b = $this->showtime('Masik');

        $this->postJson('/api/pos/sell', ['showtime_id' => $b->id, 'seatKeys' => ['B-01'], 'payment_method' => 'cash'])->assertCreated();

        $this->postJson('/api/pos/sell-multi', [
            'items' => [
                ['showtime_id' => $a->id, 'seatKeys' => ['A-01']],
                ['showtime_id' => $b->id, 'seatKeys' => ['B-01']],
            ],
            'payment_method' => 'cash',
        ])->assertStatus(409);

        $this->assertSame(1, Reservation::count());
        $this->assertSame(1, Ticket::count());
    }

    public function test_same_program_twice_and_closed_programs_are_rejected(): void
    {
        $this->cashier();
        $a = $this->showtime('Egyik');
        $external = $this->showtime('Kulso', ['is_external' => true, 'external_url' => 'https://jegy.example.test']);

        $this->postJson('/api/pos/sell-multi', [
            'items' => [
                ['showtime_id' => $a->id, 'seatKeys' => ['A-01']],
                ['showtime_id' => $a->id, 'seatKeys' => ['A-02']],
            ],
            'payment_method' => 'cash',
        ])->assertStatus(422);

        $this->postJson('/api/pos/sell-multi', [
            'items' => [
                ['showtime_id' => $a->id, 'seatKeys' => ['A-01']],
                ['showtime_id' => $external->id, 'seatKeys' => ['A-01']],
            ],
            'payment_method' => 'cash',
        ])->assertStatus(422);

        $this->assertSame(0, Ticket::count());
    }
}
