<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\SalesLog;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TicketService
{
    public function __construct(private TicketNumberingService $numbering)
    {
    }

    /**
     * Minden fizetett foglalási tételhez jegyet állít ki. A jegyszám előtagja és folyamatos sorszáma
     * a Beállításokban megadott jegyszámozás szerint alakul (minden eladott jegy egyet emel a sorszámon).
     * Ha a foglaláshoz már vannak jegyek, azokat adja vissza (idempotens).
     */
    public function issueForReservation(
        Reservation $reservation,
        string $paymentMethod,
        ?User $seller = null,
        string $channel = 'pos'
    ): Collection {
        return DB::transaction(function () use ($reservation, $paymentMethod, $seller, $channel) {
            $existing = Ticket::withoutGlobalScopes()->where('reservation_id', $reservation->id)->orderBy('id')->get();
            if ($existing->isNotEmpty()) {
                return $existing->whereNull('voided_at')->values();
            }

            $reservation->loadMissing('items', 'showtime.movie', 'showtime.auditorium');
            $showtime = $reservation->showtime;

            $soldAt = now();
            $year = (int) $soldAt->copy()->timezone('Europe/Budapest')->year;
            $prefix = $this->numbering->currentPrefix();

            $items = $reservation->items
                ->sortBy(fn($item) => sprintf('%s-%05d', $item->row_label, (int) $item->seat_number))
                ->values();

            $tickets = collect();
            foreach ($items as $item) {
                $seq = $this->numbering->nextSequence($prefix);

                $tickets->push(Ticket::create([
                    'ticket_year' => $year,
                    'ticket_prefix' => $prefix,
                    'ticket_seq' => $seq,
                    'ticket_number' => $this->numbering->format($prefix, $seq),
                    'reservation_id' => $reservation->id,
                    'showtime_id' => $showtime?->id,
                    'movie_title' => $showtime?->movie?->title ?? 'Ismeretlen film',
                    'starts_at' => $showtime?->starts_at ?? $soldAt,
                    'auditorium_name' => $showtime?->auditorium?->name,
                    'seat_key' => $item->seat_key,
                    'row_label' => $item->row_label,
                    'seat_number' => $item->seat_number,
                    'price_amount' => $item->price_amount,
                    'currency' => $reservation->currency ?? 'HUF',
                    'payment_method' => $paymentMethod,
                    'channel' => $channel,
                    'sold_by_user_id' => $seller?->id,
                    'sold_by_name' => $seller?->name,
                    'sold_at' => $soldAt,
                ]));
            }

            $this->logSale($reservation, $tickets, $paymentMethod, $seller, $channel, $soldAt);

            return $tickets;
        });
    }

    private function logSale(
        Reservation $reservation,
        Collection $tickets,
        string $paymentMethod,
        ?User $seller,
        string $channel,
        $soldAt
    ): void {
        if ($tickets->isEmpty()) {
            return;
        }

        $numbers = $tickets->pluck('ticket_number')->implode(', ');
        $total = (int) $tickets->sum('price_amount');
        $showtime = $reservation->showtime;

        SalesLog::create([
            'occurred_at' => $soldAt,
            'user_id' => $seller?->id,
            'user_name' => $seller?->name,
            'channel' => $channel,
            'reservation_id' => $reservation->id,
            'showtime_id' => $showtime?->id,
            'movie_title' => $showtime?->movie?->title,
            'starts_at' => $showtime?->starts_at,
            'ticket_count' => $tickets->count(),
            'total_amount' => $total,
            'payment_method' => $paymentMethod,
            'ticket_numbers' => $numbers,
            'ip_address' => request()?->ip(),
        ]);

        Log::info('Jegyeladás', [
            'user_id' => $seller?->id,
            'user' => $seller?->name,
            'channel' => $channel,
            'reservation_id' => $reservation->id,
            'tickets' => $numbers,
            'amount' => $total,
            'payment_method' => $paymentMethod,
        ]);

        if ($seller) {
            $method = ['cash' => 'készpénz', 'card' => 'bankkártya'][$paymentMethod] ?? $paymentMethod;
            $title = $showtime?->movie?->title ?? 'Ismeretlen film';
            $when = $showtime?->starts_at?->copy()->timezone('Europe/Budapest')->format('Y.m.d. H:i');

            ActivityLogger::log(
                'ticket.sale',
                sprintf('Jegyeladás: %d jegy – %s%s, %s Ft, %s [%s]', $tickets->count(), $title, $when ? " ({$when})" : '', number_format($total, 0, ',', ' '), $method, $numbers),
                $reservation,
                [
                    'tickets' => $tickets->pluck('ticket_number')->all(),
                    'seats' => $tickets->pluck('seat_key')->all(),
                    'amount' => $total,
                    'payment_method' => $paymentMethod,
                    'channel' => $channel,
                    'showtime_id' => $showtime?->id,
                ],
                $seller,
                $title
            );
        }
    }
}
