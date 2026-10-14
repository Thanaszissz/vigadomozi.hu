<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NavSubmission;
use App\Models\Reservation;
use App\Models\SalesLog;
use App\Models\SeatLock;
use App\Models\Showtime;
use App\Models\Ticket;
use App\Services\ActivityLogger;
use App\Services\NavReceiptService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rosszul eladott jegy sztornózása: a jegy kikerül minden statisztikából, a széke újra szabad lesz.
 */
class TicketVoidController extends Controller
{
    /** Egy előadás érvényes (nem sztornózott) jegyei. */
    public function index(Showtime $showtime): JsonResponse
    {
        $tickets = Ticket::where('showtime_id', $showtime->id)
            ->orderBy('row_label')
            ->orderBy('seat_number')
            ->get()
            ->map(fn(Ticket $t) => $t->toArray() + ['voidable' => $this->isVoidable($t)]);

        return response()->json($tickets->values());
    }

    public function void(Ticket $ticket, Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $result = DB::transaction(function () use ($ticket, $data, $request) {
            $locked = Ticket::whereKey($ticket->id)->lockForUpdate()->first();

            if (!$locked) {
                return ['message' => 'A jegy már sztornózva van.', 'status' => 409];
            }
            if (!$this->isVoidable($locked)) {
                return ['message' => 'Az online fizetett jegy nem sztornózható itt, a visszatérítést a fizetési szolgáltatónál kell intézni.', 'status' => 422];
            }

            $user = $request->user();
            $locked->update([
                'voided_at' => now(),
                'voided_by_user_id' => $user?->id,
                'voided_by_name' => $user?->name,
                'void_reason' => $data['reason'] ?? null,
            ]);

            $this->releaseSeat($locked);
            $this->adjustSalesLog($locked);

            ActivityLogger::log(
                'ticket.void',
                sprintf('Jegy sztornózva: %s (%s, %s, %s Ft)%s', $locked->ticket_number, $locked->movie_title, $locked->seat_key, number_format($locked->price_amount, 0, ',', ' '), !empty($data['reason']) ? ' – ' . $data['reason'] : ''),
                $locked->reservation,
                [
                    'ticket' => $locked->ticket_number,
                    'seat' => $locked->seat_key,
                    'amount' => $locked->price_amount,
                    'payment_method' => $locked->payment_method,
                    'sold_at' => $locked->sold_at?->toIso8601String(),
                    'reason' => $data['reason'] ?? null,
                    'showtime_id' => $locked->showtime_id,
                ],
                $user,
                $locked->movie_title
            );

            return ['ticket' => $locked];
        });

        if (isset($result['message'])) {
            return response()->json(['message' => $result['message']], $result['status']);
        }

        $sold = $result['ticket']->sold_at;

        return response()->json([
            'voided' => $result['ticket']->ticket_number,
            'nav_correction_needed' => $sold ? $this->navAlreadySent($sold) : false,
        ]);
    }

    /** Online (bankkártyás, SimplePay) jegy pénzét a szolgáltatónál kell visszautalni, ezért azt itt nem sztornózzuk. */
    private function isVoidable(Ticket $ticket): bool
    {
        return $ticket->channel !== 'online';
    }

    /** A jegy székének felszabadítása: a foglalási tétel és a zárolás törlése, a foglalás végösszegének frissítése. */
    private function releaseSeat(Ticket $ticket): void
    {
        $reservation = $ticket->reservation_id ? Reservation::whereKey($ticket->reservation_id)->lockForUpdate()->first() : null;
        if (!$reservation) {
            return;
        }

        $reservation->items()->where('seat_key', $ticket->seat_key)->delete();
        SeatLock::where('reservation_id', $reservation->id)->where('seat_key', $ticket->seat_key)->delete();

        $remaining = $reservation->items()->count();
        $reservation->update([
            'total_amount' => (int) $reservation->items()->sum('price_amount'),
            'status' => $remaining === 0 ? 'CANCELLED' : $reservation->status,
        ]);
    }

    /** Az eladási napló sora is csökken (megszűnik, ha már egyetlen jegy sem marad benne), így a napi zárásban sem szerepel. */
    private function adjustSalesLog(Ticket $ticket): void
    {
        // A szolgáltatás-nyugtáknak nincs foglalásuk: az eladási napló sorát a jegyszám alapján keressük meg
        $log = $ticket->reservation_id
            ? SalesLog::where('reservation_id', $ticket->reservation_id)->orderByDesc('id')->first()
            : SalesLog::whereNull('reservation_id')->where('ticket_numbers', 'like', '%' . $ticket->ticket_number . '%')->orderByDesc('id')->first();
        if (!$log) {
            return;
        }

        $numbers = array_values(array_filter(
            array_map('trim', explode(',', (string) $log->ticket_numbers)),
            fn($n) => $n !== '' && $n !== $ticket->ticket_number
        ));

        if (!$numbers) {
            $log->delete();

            return;
        }

        $log->update([
            'ticket_numbers' => implode(', ', $numbers),
            'ticket_count' => count($numbers),
            'total_amount' => max(0, (int) $log->total_amount - (int) $ticket->price_amount),
        ]);
    }

    /** Ha az eladás napjára már volt sikeres NAV-beküldés, azt helyesbíteni kell. */
    private function navAlreadySent(Carbon $soldAt): bool
    {
        $nav = app(NavReceiptService::class);
        $date = $soldAt->copy()->timezone(NavReceiptService::TZ)->toDateString();

        return NavSubmission::where('applicable_date', $date)
            ->where('environment', $nav->config()['environment'])
            ->where('status', 'SENT')
            ->exists();
    }
}
