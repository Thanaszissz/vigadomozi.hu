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

    private function ticket(int $seq, string $title, int $price): void
    {
        Ticket::create([
            'ticket_year' => 2026,
            'ticket_prefix' => 'T',
            'ticket_seq' => $seq,
            'ticket_number' => "T-2026-{$seq}",
            'showtime_id' => null,
            'movie_title' => $title,
            'starts_at' => '2026-11-15 14:00:00',
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
}
