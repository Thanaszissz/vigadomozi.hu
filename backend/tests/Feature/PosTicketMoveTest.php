<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\SeatLock;
use App\Models\Showtime;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Eladott jegy áthelyezése szabad székre a pénztárban. */
class PosTicketMoveTest extends TestCase
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
            'pricing_override_json' => ['rows' => ['A' => 2000, 'B' => 3000]],
        ]);
    }

    /** @return array{0: Showtime, 1: Ticket} */
    private function soldTicket(string $seat): array
    {
        $showtime = $this->showtime();
        $id = (int) $this->postJson('/api/pos/hold', [
            'showtime_id' => $showtime->id,
            'seatKeys' => [$seat],
            'customer_name' => 'Kiss Pál',
            'customer_phone' => '+36301234567',
        ])->assertSuccessful()->json('reservation.id');

        $this->postJson("/api/pos/reservations/{$id}/pay", ['payment_method' => 'cash'])->assertSuccessful();

        return [$showtime, Ticket::where('reservation_id', $id)->firstOrFail()];
    }

    public function test_ticket_list_shows_the_owner_and_move_relocates_the_ticket(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Roles::CASHIER]), ['admin:access']);
        [$showtime, $ticket] = $this->soldTicket('A-01');

        $this->getJson("/api/pos/showtimes/{$showtime->id}/tickets")
            ->assertOk()
            ->assertJsonPath('0.owner_name', 'Kiss Pál')
            ->assertJsonPath('0.owner_phone', '+36301234567')
            ->assertJsonPath('0.owner_email', null);

        $this->postJson("/api/pos/tickets/{$ticket->id}/move", ['seat_key' => 'A-04'])
            ->assertOk()
            ->assertJsonPath('seat_key', 'A-04')
            ->assertJsonPath('ticket.seat_key', 'A-04')
            ->assertJsonPath('ticket.row_label', 'A');

        $ticket->refresh();
        $this->assertSame('A-04', $ticket->seat_key);
        $this->assertSame('A', $ticket->row_label);
        $this->assertSame(4, (int) $ticket->seat_number);
        $this->assertSame(['A-04'], Reservation::find($ticket->reservation_id)->items()->pluck('seat_key')->all());
        $this->assertSame(['A-04'], SeatLock::where('showtime_id', $showtime->id)->pluck('seat_key')->all());
    }

    public function test_move_to_a_taken_seat_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Roles::CASHIER]), ['admin:access']);
        [$showtime, $ticket] = $this->soldTicket('A-01');

        $this->postJson('/api/pos/hold', [
            'showtime_id' => $showtime->id,
            'seatKeys' => ['A-02'],
            'customer_name' => 'Nagy Éva',
            'customer_phone' => '+36301234568',
        ])->assertSuccessful();

        $this->postJson("/api/pos/tickets/{$ticket->id}/move", ['seat_key' => 'A-02'])->assertStatus(409);
        $this->assertSame('A-01', $ticket->fresh()->seat_key);
    }
}
