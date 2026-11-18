<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\SeatUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\NavSubmission;
use App\Models\Reservation;
use App\Models\SalesLog;
use App\Models\SeatLock;
use App\Models\Showtime;
use App\Models\Ticket;
use App\Services\ActivityLogger;
use App\Services\NavReceiptService;
use App\Services\SeatClaimService;
use App\Support\SeatKey;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rosszul eladott jegy sztornózása: a jegy kikerül minden statisztikából, a széke újra szabad lesz.
 */
class TicketVoidController extends Controller
{
    public function __construct(private SeatClaimService $claims)
    {
    }

    /** Egy előadás érvényes (nem sztornózott) jegyei, a tulajdonos adataival együtt. */
    public function index(Showtime $showtime): JsonResponse
    {
        $tickets = Ticket::where('showtime_id', $showtime->id)
            ->with('reservation:id,customer_name,customer_phone,customer_email')
            ->orderBy('row_label')
            ->orderBy('seat_number')
            ->get()
            ->map(function (Ticket $t) {
                $reservation = $t->reservation;
                $email = $reservation?->customer_email;
                $data = $t->toArray();
                unset($data['reservation']);

                return $data + [
                    'voidable' => $this->isVoidable($t),
                    'owner_name' => $reservation?->customer_name,
                    'owner_phone' => $reservation?->customer_phone,
                    'owner_email' => $email && $email !== 'helyszini@vigadojegy.hu' ? $email : null,
                ];
            });

        return response()->json($tickets->values());
    }

    /** Eladott jegy áthelyezése egy szabad székre ugyanazon az előadáson (az ár és a fizetés nem változik). */
    public function move(Ticket $ticket, Request $request): JsonResponse
    {
        $data = $request->validate([
            'seat_key' => ['required', 'string', 'max:120'],
        ]);

        try {
            $result = DB::transaction(function () use ($ticket, $data, $request) {
                $locked = Ticket::whereKey($ticket->id)->lockForUpdate()->first();
                if (!$locked || !$locked->showtime_id || !$locked->reservation_id) {
                    return ['message' => 'Ez a jegy nem helyezhető át (már sztornózva van, vagy nem székhez tartozik).', 'status' => 409];
                }
                if ($locked->seat_key === $data['seat_key']) {
                    return ['message' => 'A jegy már ezen a széken van.', 'status' => 422];
                }

                $showtime = Showtime::find($locked->showtime_id);
                $reservation = Reservation::whereKey($locked->reservation_id)->lockForUpdate()->first();
                if (!$showtime || !$reservation) {
                    return ['message' => 'Az előadás vagy a foglalás nem található.', 'status' => 409];
                }

                $from = $locked->seat_key;
                $this->claims->moveSeat($showtime, $reservation, $from, $data['seat_key']);

                $parsed = SeatKey::parse($data['seat_key']);
                $locked->update(['seat_key' => $data['seat_key'], 'row_label' => $parsed['row'], 'seat_number' => $parsed['number']]);

                ActivityLogger::log(
                    'ticket.move',
                    sprintf('Jegy áthelyezve: %s (%s, %s → %s)', $locked->ticket_number, $locked->movie_title, SeatKey::label($from), SeatKey::label($data['seat_key'])),
                    $reservation,
                    ['ticket' => $locked->ticket_number, 'from' => $from, 'to' => $data['seat_key'], 'showtime_id' => $locked->showtime_id],
                    $request->user(),
                    $locked->movie_title
                );

                return ['ticket' => $locked];
            });
        } catch (SeatUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        if (isset($result['message'])) {
            return response()->json(['message' => $result['message']], $result['status']);
        }

        return response()->json(['moved' => $result['ticket']->ticket_number, 'seat_key' => $result['ticket']->seat_key]);
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
