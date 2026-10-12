<?php

namespace App\Services;

use App\Exceptions\SeatUnavailableException;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\SeatLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Az online (bankkártyás) fizetések jóváírása. Idempotens: ugyanaz a fizetés többször (böngészős visszatérés és IPN)
 * érkezhet, de a foglalás csak egyszer lesz kifizetve és a jegyek csak egyszer készülnek el.
 */
class OnlinePaymentService
{
    public const STATUS_PAID = 'paid';
    public const STATUS_ALREADY = 'already_paid';
    public const STATUS_CONFLICT = 'conflict';

    public function __construct(
        private SeatClaimService $claims,
        private TicketService $tickets,
        private ReservationMailer $mailer
    ) {
    }

    /** @return string self::STATUS_* */
    public function markPaid(Payment $payment, ?string $transactionId = null, array $details = []): string
    {
        $outcome = DB::transaction(function () use ($payment, $transactionId, $details) {
            $locked = Payment::lockForUpdate()->find($payment->id);
            $reservation = Reservation::lockForUpdate()->find($locked->reservation_id);

            if ($locked->status === 'SUCCESS') {
                return self::STATUS_ALREADY;
            }

            $update = [
                'transaction_id' => $transactionId ?: $locked->transaction_id,
                'last_event' => 'SUCCESS',
                'completed_at' => now(),
                'details' => array_merge((array) $locked->details, $details),
            ];

            // Már kifizetett foglalás újabb sikeres fizetése: a vásárló kétszer fizetett, visszatérítés szükséges
            if ($reservation->status === 'PAID') {
                $locked->update($update + ['status' => 'DUPLICATE']);

                return 'duplicate';
            }

            try {
                $this->claims->finalizeClaims($reservation);

                $reservation->update([
                    'status' => 'PAID',
                    'payment_provider' => SimplePayService::PROVIDER,
                    'payment_ref' => $locked->order_ref,
                    'expires_at' => null,
                ]);
                $locked->update($update + ['status' => 'SUCCESS']);

                return self::STATUS_PAID;
            } catch (SeatUnavailableException $e) {
                // A vásárló fizetett, de a széke időközben másé lett: a foglalás megszűnik, visszatérítés szükséges
                $reservation->update(['status' => 'CANCELLED', 'payment_provider' => SimplePayService::PROVIDER, 'payment_ref' => $locked->order_ref]);
                SeatLock::where('reservation_id', $reservation->id)->delete();
                $locked->update($update + ['status' => 'CONFLICT', 'details' => array_merge($update['details'], ['reason' => $e->getMessage()])]);

                return self::STATUS_CONFLICT;
            }
        });

        $reservation = Reservation::find($payment->reservation_id);

        if ($outcome === self::STATUS_PAID) {
            $tickets = $this->tickets->issueForReservation($reservation, SimplePayService::PROVIDER, null, 'online');
            $this->mailer->ticketsPaid($reservation, $tickets);

            ActivityLogger::log(
                'payment.simplepay_paid',
                sprintf('SimplePay fizetés sikeres: #%d foglalás, %d Ft (%s)', $reservation->id, $payment->amount, $payment->order_ref),
                $reservation,
                ['order_ref' => $payment->order_ref, 'transaction_id' => $transactionId, 'amount' => $payment->amount],
                user: null,
                subjectLabel: 'Foglalás #' . $reservation->id
            );
            Log::info('SimplePay: foglalás kifizetve', ['reservation_id' => $reservation->id, 'order_ref' => $payment->order_ref]);
        } elseif ($outcome === self::STATUS_CONFLICT || $outcome === 'duplicate') {
            ActivityLogger::log(
                'reservation.payment_conflict',
                $outcome === 'duplicate'
                    ? "Dupla fizetés: a(z) #{$reservation->id} foglalás már ki volt fizetve, a SimplePay fizetés ({$payment->order_ref}) visszatérítést igényel"
                    : "Fizetési ütközés: a(z) #{$reservation->id} foglalás székei időközben másé lettek, a SimplePay fizetés ({$payment->order_ref}) visszatérítést igényel",
                $reservation,
                ['order_ref' => $payment->order_ref, 'transaction_id' => $transactionId, 'amount' => $payment->amount],
                user: null,
                subjectLabel: 'Foglalás #' . $reservation->id
            );
            Log::critical('SimplePay fizetés: visszatérítés szükséges', ['reservation_id' => $reservation->id, 'order_ref' => $payment->order_ref, 'outcome' => $outcome]);
        }

        return $outcome === 'duplicate' ? self::STATUS_CONFLICT : $outcome;
    }

    /** Sikertelen, megszakított vagy lejárt fizetés rögzítése (a már lezárt fizetést nem bántja). */
    public function markFailed(Payment $payment, string $event, ?string $transactionId = null, array $details = []): void
    {
        $status = in_array($event, ['FAIL', 'CANCEL', 'TIMEOUT'], true) ? $event : 'FAIL';

        $changed = Payment::where('id', $payment->id)->where('status', 'INIT')->update([
            'status' => $status,
            'last_event' => $event,
            'transaction_id' => $transactionId ?: $payment->transaction_id,
            'completed_at' => now(),
            'details' => json_encode(array_merge((array) $payment->details, $details)),
            'updated_at' => now(),
        ]);

        if (!$changed) {
            return;
        }

        $reservation = Reservation::find($payment->reservation_id);
        if ($reservation && $reservation->status === 'PENDING') {
            // A vásárló újra próbálkozhat, de a székek ne maradjanak sokáig feleslegesen zárolva
            $this->claims->adjustHold($reservation, now()->addMinutes(10), onlyShrink: true);

            ActivityLogger::log(
                'payment.simplepay_failed',
                sprintf('SimplePay fizetés nem sikerült (%s): #%d foglalás (%s)', $event, $reservation->id, $payment->order_ref),
                $reservation,
                ['order_ref' => $payment->order_ref, 'event' => $event],
                user: null,
                subjectLabel: 'Foglalás #' . $reservation->id
            );
        }
    }
}
