<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\User;
use App\Services\TicketService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DailyReportTest extends TestCase
{
    use RefreshDatabase;

    private function reservation(int $showtimeId, int $seats, int $price): Reservation
    {
        $reservation = Reservation::create([
            'showtime_id' => $showtimeId,
            'customer_email' => 'vevo@example.test',
            'customer_name' => 'Online Vevő',
            'status' => 'PAID',
            'currency' => 'HUF',
            'total_amount' => $seats * $price,
        ]);
        for ($i = 1; $i <= $seats; $i++) {
            ReservationItem::create(['reservation_id' => $reservation->id, 'seat_key' => "A-$i", 'row_label' => 'A', 'seat_number' => $i, 'price_amount' => $price, 'category' => 'standard']);
        }

        return $reservation;
    }

    public function test_daily_report_counts_every_sold_ticket_receipt_and_online_sale(): void
    {
        $now = now();
        $user = User::factory()->create(['role' => Roles::ADMIN, 'name' => 'Pénztáros Pál']);
        Sanctum::actingAs($user, ['admin:access']);

        $movieId = DB::table('movies')->insertGetId(['title' => 'Film', 'created_at' => $now, 'updated_at' => $now]);
        $showId = DB::table('theater_shows')->insertGetId(['title' => 'Színházi előadás', 'created_at' => $now, 'updated_at' => $now]);
        $auditoriumId = DB::table('auditoria')->insertGetId(['name' => 'Nagyterem', 'created_at' => $now, 'updated_at' => $now]);
        $movieShowtime = DB::table('showtimes')->insertGetId(['movie_id' => $movieId, 'auditorium_id' => $auditoriumId, 'starts_at' => $now->copy()->addDay(), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $theaterShowtime = DB::table('showtimes')->insertGetId(['movie_id' => null, 'theater_show_id' => $showId, 'auditorium_id' => $auditoriumId, 'starts_at' => $now->copy()->addDays(2), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);

        $service = app(TicketService::class);
        // pénztár: film (2 jegy), online: film (1 jegy), pénztár: program (1 jegy, részfizetés), szolgáltatás (2 nyugta)
        $service->issueForReservation($this->reservation($movieShowtime, 2, 2000), 'cash', $user, 'pos');
        $service->issueForReservation($this->reservation($movieShowtime, 1, 2000), 'simplepay', null, 'online');
        $service->issueForReservation($this->reservation($theaterShowtime, 1, 3000), 'mixed', $user, 'pos', [
            ['method' => 'cash', 'amount' => 1000],
            ['method' => 'voucher', 'amount' => 2000],
        ]);
        $service->issueForServices([['name' => 'Sárkányvár', 'price' => 1500], ['name' => 'Sárkányvár', 'price' => 1500]], 'card', $user);

        $date = $now->copy()->timezone('Europe/Budapest')->toDateString();
        $json = $this->getJson('/api/reports/daily?date=' . $date)->assertOk()->json();
        $s = $json['summary'];

        $this->assertSame(2000 * 3 + 3000 + 3000, $s['total_amount']);
        $this->assertSame(4, $s['total_tickets']);
        $this->assertSame(2, $s['total_services']);
        $this->assertSame(6, $s['total_receipts']);
        $this->assertSame(4, $s['total_sales']);

        $byMethod = collect($s['by_payment_method'])->pluck('amount', 'method')->all();
        $this->assertSame(4000 + 1000, $byMethod['cash']);
        $this->assertSame(2000, $byMethod['simplepay']);
        $this->assertSame(2000, $byMethod['voucher']);
        $this->assertSame(3000, $byMethod['card']);

        $byChannel = collect($s['by_channel'])->pluck('amount', 'key')->all();
        $this->assertSame(2000, $byChannel['online']);

        $byKind = collect($s['by_kind'])->pluck('tickets', 'key')->all();
        $this->assertSame(3, $byKind['movie']);
        $this->assertSame(1, $byKind['theater']);
        $this->assertSame(2, $byKind['service']);

        $titles = collect($s['by_program'])->pluck('title')->all();
        $this->assertContains('Színházi előadás', $titles);
        $this->assertSame('Sárkányvár', $s['by_service'][0]['name']);
        $this->assertSame(3000, $s['by_service'][0]['amount']);

        // a szolgáltatás két nyugtája egy eladás, az online eladás vevőnévvel szerepel
        $online = collect($json['sales'])->firstWhere('channel', 'online');
        $this->assertSame('Online Vevő', $online['customer']);
        $this->assertSame('Online / rendszer', $online['seller']);
    }
}
