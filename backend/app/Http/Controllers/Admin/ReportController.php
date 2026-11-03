<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\ActivityLogger;
use App\Support\PaymentSplit;
use App\Support\TicketCategory;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ReportController extends Controller
{
    private const TZ = 'Europe/Budapest';

    private const KIND_LABELS = [
        'movie' => 'Film',
        'theater' => 'Előadás / program',
        'service' => 'Egyéb szolgáltatás',
    ];

    /**
     * Napi riport: a nap (budapesti idő) minden eladott jegye és szolgáltatás-nyugtája, csatornától (pénztár, admin, online)
     * és típustól (film, előadás / program, egyéb szolgáltatás) függetlenül. A sztornózott jegyek nincsenek benne.
     */
    public function dailyReport(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $request->query('date') ?: now(self::TZ)->toDateString();

        ActivityLogger::log('report.daily_report', "Napi riport megtekintése: {$date}", null, ['date' => $date]);

        $start = Carbon::parse($date, self::TZ)->startOfDay()->utc();
        $end = $start->copy()->addDay();

        $tickets = Ticket::query()
            ->whereBetween('sold_at', [$start, $end])
            ->with(['showtime:id,theater_show_id', 'reservation:id,customer_name'])
            ->orderBy('sold_at')
            ->orderBy('id')
            ->get();

        $kindOf = function (Ticket $t): string {
            if ($t->category === TicketCategory::SERVICE) {
                return 'service';
            }

            return $t->showtime?->theater_show_id ? 'theater' : 'movie';
        };

        $services = $tickets->filter(fn(Ticket $t) => $t->category === TicketCategory::SERVICE);
        $seats = $tickets->reject(fn(Ticket $t) => $t->category === TicketCategory::SERVICE);
        $sales = $this->sales($tickets, $kindOf);

        $summary = [
            'date' => $date,
            'total_amount' => (int) $tickets->sum('price_amount'),
            'total_receipts' => $tickets->count(),
            'total_tickets' => $seats->count(),
            'total_services' => $services->count(),
            'total_sales' => $sales->count(),
            'by_payment_method' => collect(PaymentSplit::summary($tickets))
                ->map(fn($r) => ['method' => $r['payment_method'] ?: 'unknown', 'count' => $r['tickets'], 'amount' => $r['amount']])
                ->sortByDesc('amount')->values()->all(),
            'by_channel' => $this->grouped($tickets, fn(Ticket $t) => $t->channel ?: 'pos', fn($key) => $key),
            'by_kind' => $this->grouped($tickets, $kindOf, fn($key) => self::KIND_LABELS[$key] ?? $key),
            'by_category' => $this->grouped($tickets, fn(Ticket $t) => $t->category ?: TicketCategory::STANDARD, fn($key) => $key),
            'by_seller' => $this->grouped($tickets, fn(Ticket $t) => $t->sold_by_name ?: 'Online / rendszer', fn($key) => $key),
            'by_program' => $this->programs($seats, $kindOf),
            'by_service' => $services->groupBy('movie_title')
                ->map(fn(Collection $g, $name) => ['name' => (string) $name, 'tickets' => $g->count(), 'amount' => (int) $g->sum('price_amount')])
                ->sortByDesc('amount')->values()->all(),
            'hourly' => $tickets
                ->groupBy(fn(Ticket $t) => (int) $t->sold_at->copy()->timezone(self::TZ)->format('G'))
                ->map(fn(Collection $g, $hour) => ['hour' => (int) $hour, 'count' => $g->count(), 'amount' => (int) $g->sum('price_amount')])
                ->sortBy('hour')->values()->all(),
        ];

        return response()->json([
            'summary' => $summary,
            'sales' => $sales->values()->all(),
        ]);
    }

    /**
     * Csoportosított összesítés: kulcsonként darabszám és összeg, bevétel szerint csökkenő sorrendben.
     *
     * @return array<int,array{key:string,label:string,tickets:int,amount:int}>
     */
    private function grouped(Collection $tickets, callable $keyOf, callable $label): array
    {
        return $tickets->groupBy($keyOf)
            ->map(fn(Collection $g, $key) => [
                'key' => (string) $key,
                'label' => (string) $label($key),
                'tickets' => $g->count(),
                'amount' => (int) $g->sum('price_amount'),
            ])
            ->sortByDesc('amount')->values()->all();
    }

    /**
     * Előadásonként / programonként egy sor: az adott napon az előadásra eladott jegyek (az előadás napjától függetlenül).
     *
     * @return array<int,array<string,mixed>>
     */
    private function programs(Collection $seats, callable $kindOf): array
    {
        return $seats
            ->groupBy(fn(Ticket $t) => ($t->showtime_id ?? 0) . '|' . $t->movie_title)
            ->map(function (Collection $g) use ($kindOf) {
                /** @var Ticket $first */
                $first = $g->first();

                return [
                    'showtime_id' => $first->showtime_id,
                    'title' => $first->movie_title,
                    'kind' => $kindOf($first),
                    'starts_at' => $first->starts_at,
                    'auditorium_name' => $first->auditorium_name,
                    'tickets' => $g->count(),
                    'online_tickets' => $g->where('channel', 'online')->count(),
                    'amount' => (int) $g->sum('price_amount'),
                ];
            })
            ->sortBy(fn($r) => $r['starts_at'])
            ->values()->all();
    }

    /**
     * Eladásonként egy sor: egy foglalás jegyei, illetve egy szolgáltatás-eladás nyugtái (azonos időpont és eladó).
     */
    private function sales(Collection $tickets, callable $kindOf): Collection
    {
        return $tickets
            ->groupBy(fn(Ticket $t) => $t->reservation_id
                ? 'r' . $t->reservation_id
                : 's' . $t->sold_at->timestamp . '|' . ($t->sold_by_user_id ?? 0))
            ->map(function (Collection $g) use ($kindOf) {
                /** @var Ticket $first */
                $first = $g->first();
                $isService = $first->category === TicketCategory::SERVICE;
                $titles = $g->groupBy('movie_title')
                    ->map(fn(Collection $x, $title) => $isService && $x->count() > 1 ? $x->count() . '× ' . $title : $title)
                    ->values();

                return [
                    'sold_at' => $first->sold_at,
                    'channel' => $first->channel ?: 'pos',
                    'seller' => $first->sold_by_name ?: 'Online / rendszer',
                    'customer' => $first->reservation?->customer_name,
                    'kind' => $kindOf($first),
                    'title' => $titles->implode(', '),
                    'starts_at' => $isService ? null : $first->starts_at,
                    'tickets' => $g->count(),
                    'amount' => (int) $g->sum('price_amount'),
                    'payments' => collect(PaymentSplit::amounts($g))
                        ->map(fn($amount, $method) => ['method' => (string) $method, 'amount' => (int) $amount])->values()->all(),
                    'numbers' => $first->ticket_number . ($g->count() > 1 ? ' – ' . $g->last()->ticket_number : ''),
                ];
            })
            ->sortBy(fn($s) => $s['sold_at'])
            ->values();
    }
}
