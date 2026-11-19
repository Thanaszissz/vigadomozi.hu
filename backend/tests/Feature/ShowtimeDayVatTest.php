<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Az előadások napi eladása: bruttó / nettó / ÁFA (27%) bontás. */
class ShowtimeDayVatTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(int $seq, string $title, int $price, string $startsAt = '2026-11-15 14:00:00'): void
    {
        Ticket::create([
            'ticket_year' => 2026,
            'ticket_prefix' => 'T',
            'ticket_seq' => $seq,
            'ticket_number' => "T-2026-{$seq}",
            'showtime_id' => null,
            'movie_title' => $title,
            'starts_at' => $startsAt,
            'auditorium_name' => 'Terem',
            'seat_key' => 'A-0' . $seq,
            'row_label' => 'A',
            'seat_number' => $seq,
            'price_amount' => $price,
            'currency' => 'HUF',
            'payment_method' => 'cash',
            'channel' => 'pos',
            'category' => 'standard',
            'sold_at' => now(),
        ]);
    }

    public function test_totals_split_gross_into_net_and_vat(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Roles::ADMIN]), ['admin:access']);
        $this->ticket(1, 'Egy', 1700);
        $this->ticket(2, 'Egy', 1700);
        $this->ticket(3, 'Kettő', 2000);

        $json = $this->getJson('/api/tickets/showtime-day?date=2026-11-15')->assertOk()->json();

        $this->assertSame(27, $json['vat_rate']);
        $this->assertSame('day', $json['period']);
        $this->assertTrue($json['detail']);
        $this->assertSame([], $json['breakdown']);
        $this->assertSame(5400, $json['totals']['amount']);

        $egy = collect($json['shows'])->firstWhere('movie_title', 'Egy');
        $this->assertSame(3400, $egy['amount']);
        $this->assertSame(2677, $egy['net']);
        $this->assertSame(723, $egy['vat']);

        foreach ($json['shows'] as $show) {
            $this->assertSame($show['amount'], $show['net'] + $show['vat']);
        }
        $this->assertSame($json['totals']['amount'], $json['totals']['net'] + $json['totals']['vat']);
    }

    public function test_week_month_and_year_periods(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Roles::ADMIN]), ['admin:access']);
        $this->ticket(1, 'Vasárnap', 1000, '2026-11-15 14:00:00');
        $this->ticket(2, 'Hétfő', 2000, '2026-11-16 14:00:00');
        $this->ticket(3, 'December', 3000, '2026-12-01 14:00:00');

        $week = $this->getJson('/api/tickets/showtime-day?date=2026-11-15&period=week')->assertOk()->json();
        $this->assertSame('2026-11-09', $week['from']);
        $this->assertSame('2026-11-15', $week['to']);
        $this->assertSame(1000, $week['totals']['amount']);
        $this->assertCount(1, $week['breakdown']);

        $month = $this->getJson('/api/tickets/showtime-day?date=2026-11-20&period=month')->assertOk()->json();
        $this->assertSame(3000, $month['totals']['amount']);
        $this->assertFalse($month['detail']);
        $this->assertSame([], $month['shows']);
        $this->assertSame(['2026-11-15', '2026-11-16'], array_column($month['breakdown'], 'key'));

        $year = $this->getJson('/api/tickets/showtime-day?date=2026-06-01&period=year')->assertOk()->json();
        $this->assertSame(6000, $year['totals']['amount']);
        $this->assertSame(['2026-11', '2026-12'], array_column($year['breakdown'], 'key'));
        $this->assertSame($year['totals']['net'], array_sum(array_column($year['breakdown'], 'net')));

        $this->get('/api/tickets/showtime-day/export?date=2026-11-20&period=month&type=summary')->assertOk();
    }
}
