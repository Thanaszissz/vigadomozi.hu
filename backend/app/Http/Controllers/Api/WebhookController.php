<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\SeatLock;
use App\Services\StripeService;
use App\Mail\ReservationConfirmedMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function stripe(Request $request, StripeService $stripeService)
    {
        $payload = $request->getContent();
        $sig_header = $request->header('Stripe-Signature');

        try {
            $event = $stripeService->verifyWebhookSignature($payload, $sig_header);
        } catch (\UnexpectedValueException $e) {
            Log::error('Stripe webhook verification failed: ' . $e->getMessage());
            return response('Webhook verification failed', 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Stripe signature verification failed: ' . $e->getMessage());
            return response('Webhook signature verification failed', 400);
        }

        // Handle checkout.session.completed
        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;
            
            $reservationId = $session->metadata->reservation_id ?? null;
            if (!$reservationId) {
                Log::warning('Stripe webhook: reservation_id not found in metadata');
                return response('ok', 200);
            }

            $reservation = Reservation::find($reservationId);
            if (!$reservation) {
                Log::warning('Stripe webhook: reservation not found: ' . $reservationId);
                return response('ok', 200);
            }

            // Mark as PAID
            $reservation->update([
                'status' => 'PAID',
                'payment_provider' => 'stripe',
                'payment_ref' => $session->id,
            ]);

            // Remove seat locks (they're now locked via PAID status)
            SeatLock::where('reservation_id', $reservation->id)->delete();

            // Send confirmation email
            Mail::to($reservation->customer_email)->send(
                new ReservationConfirmedMail($reservation)
            );

            Log::info('Reservation ' . $reservation->id . ' marked as PAID');
        }

        return response('ok', 200);
    }
}
