<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalesLog;
use App\Models\Ticket;
use App\Services\ActivityLogger;
use App\Support\TicketCategory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketAdminController extends Controller
{
    /**
     * Az évek listája, amelyekhez van eladott jegy (a folyó évvel együtt).
     */
    public function years(): JsonResponse
    {
        $years = Ticket::query()->distinct()->orderByDesc('ticket_year')->pluck('ticket_year')->map(fn($y) => (int) $y);
        $current = (int) now()->timezone('Europe/Budapest')->year;

        return response()->json(
            $years->push($current)->unique()->sortDesc()->values()
        );
    }

    /**
     * Egy év eladott jegyei sorszám szerint, összesítéssel.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'payment_method' => ['nullable', 'in:cash,card,stripe,simplepay,experience_card'],
            'date' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $year = (int) ($request->query('year') ?: now()->timezone('Europe/Budapest')->year);
        $query = $this->filtered($request, $year);

        $summary = (clone $query)
            ->select('payment_method', DB::raw('count(*) as tickets'), DB::raw('sum(price_amount) as amount'))
            ->groupBy('payment_method')
            ->get();

        $byCategory = (clone $query)
            ->select('category', DB::raw('count(*) as tickets'), DB::raw('sum(price_amount) as amount'))
            ->groupBy('category')
            ->get();

        $page = $query->orderByDesc('id')->paginate(50);

        return response()->json([
            'year' => $year,
            'summary' => [
                'tickets' => (int) $summary->sum('tickets'),
                'amount' => (int) $summary->sum('amount'),
                'by_method' => $summary->map(fn($s) => [
                    'payment_method' => $s->payment_method,
                    'tickets' => (int) $s->tickets,
                    'amount' => (int) $s->amount,
                ])->values(),
                'by_category' => $byCategory->map(fn($s) => [
                    'category' => $s->category,
                    'tickets' => (int) $s->tickets,
                    'amount' => (int) $s->amount,
                ])->values(),
            ],
            'tickets' => $page,
        ]);
    }

    public function show(Ticket $ticket): JsonResponse
    {
        return response()->json($ticket);
    }

    /**
     * Napi zárás: az adott nap (budapesti idő) eladott jegyei eladónként, soronként.
     */
    public function dailyClose(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        $date = $request->query('date') ?: now()->timezone('Europe/Budapest')->toDateString();
        [$from, $to] = $this->dayRange($date);

        ActivityLogger::log('report.daily_close', "Napi zárás megtekintése: {$date}", null, ['date' => $date]);

        $scope = $this->closeScope($request);

        $tickets = Ticket::query()
            ->whereBetween('sold_at', [$from, $to])
            ->when($scope === 'own', fn($q) => $q->where('sold_by_user_id', $request->user()->id))
            ->orderBy('sold_at')
            ->orderBy('id')
            ->get();

        $summarize = function ($rows) {
            $byMethod = $rows->groupBy('payment_method')->map(fn($g, $method) => [
                'payment_method' => $method,
                'tickets' => $g->count(),
                'amount' => (int) $g->sum('price_amount'),
            ])->values();

            return [
                'tickets' => $rows->count(),
                'amount' => (int) $rows->sum('price_amount'),
                'by_method' => $byMethod,
            ];
        };

        $sellers = $tickets
            ->groupBy(fn($t) => $t->sold_by_user_id ?? 0)
            ->map(function ($rows, $userId) use ($summarize) {
                return [
                    'user_id' => $userId ?: null,
                    'user_name' => $rows->first()->sold_by_name ?: 'Online / rendszer',
                    'totals' => $summarize($rows),
                    'tickets' => $rows->values(),
                ];
            })
            ->sortBy('user_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return response()->json([
            'date' => $date,
            'generated_at' => now(),
            'scope' => $scope,
            'totals' => $summarize($tickets),
            'sellers' => $sellers,
        ]);
    }

    /**
     * Eladási napló: minden jegyeladás egy sorban (ki, mikor, mely jegyek).
     */
    public function salesLog(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        $date = $request->query('date') ?: now()->timezone('Europe/Budapest')->toDateString();
        [$from, $to] = $this->dayRange($date);

        $scope = $this->closeScope($request);

        $logs = SalesLog::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->when($scope === 'own', fn($q) => $q->where('user_id', $request->user()->id))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        return response()->json(['date' => $date, 'scope' => $scope, 'logs' => $logs]);
    }

    /** A pénztáros csak a saját eladásait látja, a vezető és az adminisztrátor mindenkiét. */
    private function closeScope(Request $request): string
    {
        return $request->user()->hasPermission('daily_close.all') ? 'all' : 'own';
    }

    private function dayRange(string $date): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $date, 'Europe/Budapest')->startOfDay();

        return [$start->copy()->utc(), $start->copy()->endOfDay()->utc()];
    }

    public function export(Request $request)
    {
        $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'payment_method' => ['nullable', 'in:cash,card,stripe,simplepay,experience_card'],
            'date' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $year = (int) $request->query('year');

        // Az export nem lapozott: a szűrésnek megfelelő összes jegyet tartalmazza
        $query = $this->filtered($request, $year);
        $filters = array_filter([
            'payment_method' => $request->query('payment_method'),
            'date' => $request->query('date'),
            'q' => trim((string) $request->query('q')),
        ]);

        ActivityLogger::log(
            'report.export_tickets',
            "Jegyek exportálása (CSV): {$year}" . ($filters ? ' (szűrt: ' . implode(', ', array_keys($filters)) . ')' : ''),
            null,
            ['year' => $year] + $filters
        );

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="jegyek_' . $year . '.csv"',
        ];

        $methods = ['cash' => 'Készpénz', 'card' => 'Bankkártya', 'stripe' => 'Online', 'simplepay' => 'Online (SimplePay)', 'experience_card' => 'Élménykártya'];

        return response()->stream(function () use ($query, $methods) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Jegyszám', 'Eladva', 'Film / szolgáltatás', 'Előadás kezdete', 'Terem', 'Sor', 'Szék', 'Ár (Ft)', 'Fizetés', 'Értékesítő', 'Kategória'], ';');

            $query->orderBy('id')->chunk(500, function ($tickets) use ($out, $methods) {
                foreach ($tickets as $t) {
                    $service = $t->category === TicketCategory::SERVICE;
                    fputcsv($out, [
                        $t->ticket_number,
                        $t->sold_at->copy()->timezone('Europe/Budapest')->format('Y-m-d H:i'),
                        $t->movie_title,
                        $service ? '' : $t->starts_at->copy()->timezone('Europe/Budapest')->format('Y-m-d H:i'),
                        $t->auditorium_name,
                        $service ? '' : $t->row_label,
                        $service ? '' : $t->seat_number,
                        $t->price_amount,
                        $methods[$t->payment_method] ?? $t->payment_method,
                        $t->sold_by_name,
                        TicketCategory::label($t->category),
                    ], ';');
                }
            });

            fclose($out);
        }, 200, $headers);
    }

    private function filtered(Request $request, int $year): Builder
    {
        $query = Ticket::query()->where('ticket_year', $year);

        if ($method = $request->query('payment_method')) {
            $query->where('payment_method', $method);
        }

        if ($date = $request->query('date')) {
            // A nap határai budapesti idő szerint, UTC-ben tárolt értékekkel összevetve
            $start = \Carbon\Carbon::parse($date, 'Europe/Budapest')->startOfDay()->utc();
            $query->whereBetween('sold_at', [$start, $start->copy()->addDay()]);
        }

        if ($q = trim((string) $request->query('q'))) {
            $query->where(function (Builder $w) use ($q) {
                $w->where('ticket_number', 'like', "%{$q}%")
                    ->orWhere('movie_title', 'like', "%{$q}%");
            });
        }

        return $query;
    }
}
