<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SeatUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\SeatLock;
use App\Services\ActivityLogger;
use App\Services\SeatClaimService;
use App\Services\StripeService;
use App\Services\ReservationMailer;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

            // A Stripe újraküldheti ugyanazt az eseményt: a már kifizetett foglalást nem dolgozzuk fel újra
            if ($reservation->status === 'PAID') {
                return response('ok', 200);
            }

            try {
                // A székek zárolása véglegessé válik. Ha a zárolás közben lejárt és a széket már más megszerezte,
                // a fizetés nem véglegesíthető (így ugyanazt a széket nem lehet kétszer eladni).
                DB::transaction(function () use ($reservation, $session) {
                    app(SeatClaimService::class)->finalizeClaims($reservation);

                    $reservation->update([
                        'status' => 'PAID',
                        'payment_provider' => 'stripe',
                        'payment_ref' => $session->id,
                    ]);
                });
            } catch (SeatUnavailableException $e) {
                // A vásárló már fizetett, de a széke időközben másé lett: visszatérítés szükséges
                $reservation->update([
                    'status' => 'CANCELLED',
                    'payment_provider' => 'stripe',
                    'payment_ref' => $session->id,
                ]);
                SeatLock::where('reservation_id', $reservation->id)->delete();

                ActivityLogger::log(
                    'reservation.payment_conflict',
                    "Fizetési ütközés: a(z) #{$reservation->id} foglalás székei időközben másé lettek, a bankkártyás fizetés visszatérítést igényel",
                    $reservation,
                    ['stripe_session' => $session->id, 'amount' => $reservation->total_amount, 'reason' => $e->getMessage()],
                    user: null,
                    subjectLabel: 'Foglalás #' . $reservation->id
                );
                Log::critical('Stripe fizetés ütközés: visszatérítés szükséges', ['reservation_id' => $reservation->id, 'session' => $session->id]);

                return response('ok', 200);
            }
            $tickets = app(TicketService::class)->issueForReservation($reservation, 'stripe', null, 'online');

            // A megvásárolt jegyek kiküldése e-mailben
            app(ReservationMailer::class)->ticketsPaid($reservation, $tickets);

            Log::info('Reservation ' . $reservation->id . ' marked as PAID');
        }

        return response('ok', 200);
    }
}
