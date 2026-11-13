<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Setting;

/**
 * Webes mérés (Google Tag Manager / GA4 / Meta) beállításai. A jegyrendszer csak a dataLayer eseményeket adja át,
 * a mérőkódokat a Tag Managerben kezelik. Az itt tárolt paraméterek azok az adatok, amelyeket a leírásból nem lehet kiolvasni.
 */
class AnalyticsSettings
{
    public const MODES = ['order_ref', 'reservation'];

    private ?array $config = null;

    public function config(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $get = fn(string $key) => Setting::find('analytics.' . $key)?->value;

        $taxRate = $get('tax_rate');
        $mode = $get('transaction_id_mode');

        return $this->config = [
            'enabled' => $get('enabled') === '1',
            'gtm_id' => strtoupper(trim((string) $get('gtm_id'))),
            // üres = az ÁFA összege nem kerül a purchase eseménybe
            'tax_rate' => ($taxRate === null || $taxRate === '') ? null : max(0, min(100, (int) $taxRate)),
            'variant' => $this->text($get('variant'), 'Felnőtt'),
            'transaction_id_mode' => in_array($mode, self::MODES, true) ? $mode : 'order_ref',
            'transaction_prefix' => $this->text($get('transaction_prefix'), 'VIG'),
            'item_prefix_movie' => $this->text($get('item_prefix_movie'), 'MO'),
            'item_prefix_show' => $this->text($get('item_prefix_show'), 'EL'),
            'send_postal_code' => $get('send_postal_code') !== '0',
        ];
    }

    /** @param array<string, mixed> $data */
    public function save(array $data): void
    {
        $set = fn(string $key, string $value) => Setting::updateOrCreate(['key' => 'analytics.' . $key], ['value' => $value]);

        $set('enabled', $data['enabled'] ? '1' : '0');
        $set('gtm_id', strtoupper(trim((string) $data['gtm_id'])));
        $set('tax_rate', $data['tax_rate'] === null ? '' : (string) (int) $data['tax_rate']);
        $set('variant', trim((string) $data['variant']));
        $set('transaction_id_mode', $data['transaction_id_mode']);
        $set('transaction_prefix', trim((string) $data['transaction_prefix']));
        $set('item_prefix_movie', trim((string) $data['item_prefix_movie']));
        $set('item_prefix_show', trim((string) $data['item_prefix_show']));
        $set('send_postal_code', $data['send_postal_code'] ? '1' : '0');

        $this->config = null;
    }

    /** Be van-e kapcsolva és van-e érvényes tároló azonosító. */
    public function isActive(): bool
    {
        $c = $this->config();

        return $c['enabled'] && preg_match('/^GTM-[A-Z0-9]{4,12}$/', $c['gtm_id']) === 1;
    }

    /** A rendelés azonosítója a mérésben (transaction_id): a SimplePay rendelésszám vagy a foglalásból képzett sorszám. */
    public function transactionId(Payment $payment): string
    {
        $c = $this->config();
        if ($c['transaction_id_mode'] === 'reservation') {
            $year = ($payment->completed_at ?? $payment->created_at)?->format('Y') ?? date('Y');

            return sprintf('%s-%s-%06d', $c['transaction_prefix'], $year, $payment->reservation_id);
        }

        return (string) $payment->order_ref;
    }

    /** A bruttó összegben foglalt ÁFA, ha meg van adva az ÁFA-kulcs. */
    public function tax(int $gross): ?int
    {
        $rate = $this->config()['tax_rate'];
        if ($rate === null) {
            return null;
        }

        return (int) round($gross - $gross / (1 + $rate / 100));
    }

    private function text(?string $value, string $default): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : $default;
    }
}
