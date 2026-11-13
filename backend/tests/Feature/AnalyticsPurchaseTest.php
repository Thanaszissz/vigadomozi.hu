<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\Showtime;
use App\Services\AnalyticsSettings;
use App\Services\TicketAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnalyticsPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private function paidReservation(): array
    {
        $now = now();
        $movieId = DB::table('movies')->insertGetId(['title' => 'Film', 'created_at' => $now, 'updated_at' => $now]);
        $auditoriumId = DB::table('auditoria')->insertGetId(['name' => 'Nagyterem', 'created_at' => $now, 'updated_at' => $now]);
        $showtime = Showtime::create(['movie_id' => $movieId, 'auditorium_id' => $auditoriumId, 'starts_at' => $now->copy()->addDay(), 'status' => 'active']);

        $reservation = Reservation::create([
            'showtime_id' => $showtime->id,
            'customer_email' => 'a@example.test',
            'customer_name' => 'Teszt',
            'customer_phone' => '+36301234567',
            'customer_zip' => '5310',
            'status' => 'PAID',
            'total_amount' => 3400,
            'currency' => 'HUF',
        ]);

        $payment = Payment::create([
            'reservation_id' => $reservation->id,
            'provider' => 'simplepay',
            'order_ref' => 'VIG-1-ABCD1234',
            'transaction_id' => '501234567',
            'status' => 'SUCCESS',
            'amount' => 3400,
            'currency' => 'HUF',
            'completed_at' => $now,
        ]);

        return [$reservation, $payment];
    }

    private function enable(array $extra = []): void
    {
        $values = ['enabled' => '1', 'gtm_id' => 'GTM-ABC1234', 'tax_rate' => '27'] + $extra;
        foreach ($values as $key => $value) {
            Setting::updateOrCreate(['key' => 'analytics.' . $key], ['value' => $value]);
        }
    }

    public function test_config_is_hidden_until_analytics_is_active(): void
    {
        $this->getJson('/api/analytics-config')->assertOk()->assertExactJson(['enabled' => false]);

        $this->enable();

        $this->getJson('/api/analytics-config')->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('gtm_id', 'GTM-ABC1234')
            ->assertJsonPath('variant', 'Felnőtt');
    }

    public function test_purchase_event_is_granted_only_once_per_order(): void
    {
        $this->enable();
        [$reservation] = $this->paidReservation();
        $url = '/api/reservations/' . $reservation->id . '/analytics-purchase?k=' . TicketAccess::key($reservation->id);

        $first = $this->postJson($url)->assertOk()->json();
        $this->assertTrue($first['first']);
        $this->assertSame('VIG-1-ABCD1234', $first['transaction_id']);
        $this->assertSame(3400, $first['value']);
        $this->assertSame(723, $first['tax']);
        $this->assertSame('5310', $first['postal_code']);

        $this->postJson($url)->assertOk()->assertExactJson(['first' => false]);
    }

    public function test_purchase_event_needs_the_access_key_and_a_paid_order(): void
    {
        $this->enable();
        [$reservation] = $this->paidReservation();

        $this->postJson('/api/reservations/' . $reservation->id . '/analytics-purchase?k=rossz')->assertOk()->assertExactJson(['first' => false]);

        $reservation->update(['status' => 'PENDING']);
        $this->postJson('/api/reservations/' . $reservation->id . '/analytics-purchase?k=' . TicketAccess::key($reservation->id))
            ->assertOk()->assertExactJson(['first' => false]);
    }

    public function test_transaction_id_can_follow_the_reservation_number(): void
    {
        $this->enable(['transaction_id_mode' => 'reservation', 'transaction_prefix' => 'KSJ']);
        [$reservation, $payment] = $this->paidReservation();

        $id = app(AnalyticsSettings::class)->transactionId($payment);

        $this->assertSame(sprintf('KSJ-%s-%06d', date('Y'), $reservation->id), $id);
    }
}
