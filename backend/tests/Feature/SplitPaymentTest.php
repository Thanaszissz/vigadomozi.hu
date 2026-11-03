<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use App\Support\PaymentSplit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SplitPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function reservationWithSeats(int $count, int $price = 2000, array $categories = []): Reservation
    {
        $now = now();
        $movieId = DB::table('movies')->insertGetId(['title' => 'Film', 'created_at' => $now, 'updated_at' => $now]);
        $auditoriumId = DB::table('auditoria')->insertGetId(['name' => 'Nagyterem', 'created_at' => $now, 'updated_at' => $now]);
        $showtimeId = DB::table('showtimes')->insertGetId([
            'movie_id' => $movieId,
            'auditorium_id' => $auditoriumId,
            'starts_at' => $now,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $reservation = Reservation::create([
            'showtime_id' => $showtimeId,
            'customer_email' => 'helyszini@vigadojegy.hu',
            'status' => 'PAID',
            'currency' => 'HUF',
            'total_amount' => $count * $price,
        ]);

        for ($i = 1; $i <= $count; $i++) {
            ReservationItem::create([
                'reservation_id' => $reservation->id,
                'seat_key' => 'A-' . $i,
                'row_label' => 'A',
                'seat_number' => $i,
                'price_amount' => $price,
                'category' => $categories[$i] ?? 'standard',
            ]);
        }

        return $reservation;
    }

    public function test_split_payment_spreads_amounts_over_tickets_and_totals_per_method(): void
    {
        $user = User::factory()->create();
        $reservation = $this->reservationWithSeats(5);

        $tickets = app(TicketService::class)->issueForReservation($reservation, 'mixed', $user, 'pos', [
            ['method' => 'cash', 'amount' => 3000],
            ['method' => 'experience_card', 'amount' => 2000],
            ['method' => 'card', 'amount' => 1000],
            ['method' => 'voucher', 'amount' => 4000],
        ]);

        $this->assertCount(5, $tickets);
        $stored = Ticket::where('reservation_id', $reservation->id)->orderBy('id')->get();

        $this->assertSame('cash', $stored[0]->payment_method);
        $this->assertNull($stored[0]->payment_split);
        $this->assertSame('mixed', $stored[1]->payment_method);
        $this->assertSame(
            [['method' => 'cash', 'amount' => 1000], ['method' => 'experience_card', 'amount' => 1000]],
            $stored[1]->payment_split
        );
        $this->assertSame('voucher', $stored[4]->payment_method);

        $this->assertSame(
            ['cash' => 3000, 'experience_card' => 2000, 'card' => 1000, 'voucher' => 4000],
            PaymentSplit::amounts($stored)
        );

        $this->assertDatabaseHas('sales_logs', ['reservation_id' => $reservation->id, 'payment_method' => 'mixed', 'total_amount' => 10000]);
    }

    public function test_archive_pdf_endpoint_returns_a_pdf_with_summary_and_split_tickets(): void
    {
        $user = User::factory()->create(['role' => \App\Support\Roles::ADMIN]);
        \Laravel\Sanctum\Sanctum::actingAs($user, ['admin:access']);
        $reservation = $this->reservationWithSeats(3);

        app(TicketService::class)->issueForReservation($reservation, 'mixed', $user, 'pos', [
            ['method' => 'cash', 'amount' => 3000],
            ['method' => 'voucher', 'amount' => 3000],
        ]);

        $year = now()->timezone('Europe/Budapest')->year;
        $response = $this->get('/api/tickets/export-pdf?year=' . $year);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        // ismeretlen fizetési mód szűrőként érvénytelen kérés
        $this->getJson('/api/tickets/export-pdf?year=' . $year . '&payment_method=nincs')->assertStatus(422);
    }

    public function test_experience_card_category_tickets_stay_outside_the_split(): void
    {
        $user = User::factory()->create();
        $reservation = $this->reservationWithSeats(3, 2000, [1 => 'experience_card']);

        app(TicketService::class)->issueForReservation($reservation, 'mixed', $user, 'pos', [
            ['method' => 'cash', 'amount' => 1000],
            ['method' => 'szep_card', 'amount' => 3000],
        ]);

        $stored = Ticket::where('reservation_id', $reservation->id)->orderBy('id')->get();

        $this->assertSame('experience_card', $stored[0]->payment_method);
        $this->assertSame(
            ['experience_card' => 2000, 'cash' => 1000, 'szep_card' => 3000],
            PaymentSplit::amounts($stored)
        );
    }
}
