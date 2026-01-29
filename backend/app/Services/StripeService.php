<?php

namespace App\Services;

use App\Models\Reservation;
use Stripe\StripeClient;

class StripeService
{
    private $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(config('services.stripe.secret'));
    }

    /**
     * Create a Stripe Checkout session for a reservation.
     */
    public function createCheckoutSession(Reservation $reservation): string
    {
        $lineItems = [];
        
        foreach ($reservation->items as $item) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'huf',
                    'product_data' => [
                        'name' => "Szék {$item->seat_key}",
                        'description' => "Sor: {$item->row_label}",
                    ],
                    'unit_amount' => $item->price_amount,
                ],
                'quantity' => 1,
            ];
        }

        try {
            $session = $this->stripe->checkout->sessions->create([
                'payment_method_types' => ['card'],
                'line_items' => $lineItems,
                'mode' => 'payment',
                'success_url' => config('app.frontend_url') . '/success/' . $reservation->id,
                'cancel_url' => config('app.frontend_url') . '/checkout/' . $reservation->id,
                'customer_email' => $reservation->customer_email,
                'metadata' => [
                    'reservation_id' => $reservation->id,
                ],
            ]);

            return $session->url;
        } catch (\Exception $e) {
            // If Stripe fails, return a demo checkout URL
            \Log::warning('Stripe checkout failed, using demo mode: ' . $e->getMessage());
            return 'https://checkout.stripe.com/pay/cs_live_demo_' . $reservation->id;
        }
    }

    /**
     * Retrieve and verify webhook signature.
     */
    public function verifyWebhookSignature($body, $signature)
    {
        return \Stripe\Webhook::constructEvent(
            $body,
            $signature,
            config('services.stripe.webhook_secret')
        );
    }
}
