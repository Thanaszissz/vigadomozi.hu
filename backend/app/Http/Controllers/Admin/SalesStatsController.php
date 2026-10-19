<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\ActivityLogger;
use App\Support\TicketCategory;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SalesStatsController extends Controller
{
    private const TZ = 'Europe/Budapest';
    private const MAX_BUCKETS = 400;

    /**
     * Értékesítési statisztika a megadott intervallumra (budapesti napok, a határok is benne vannak):
     * idősoros bontás napra / hétre / hónapra / évre, irányítószám, fizetési mód, program és kategória szerint.
     * A sztornózott jegyek nem számítanak (a Ticket alap hatóköre kiszűri őket).
     */
    public function sales(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group' => ['sometimes', 'in:hour,day,week,month,year'],
        ]);
        $group = $data['group'] ?? 'day';

        $from = Carbon::createFromFormat('Y-m-d', $data['from'], self::TZ)->startOfDay();
        $to = Carbon::createFromFormat('Y-m-d', $data['to'], self::TZ)->endOfDay();

        $buckets = $this->buckets($from, $to, $group);
        if (count($buckets) > self::MAX_BUCKETS) {
            throw ValidationException::withMessages(['group' => 'Túl sok időszak: válasszon nagyobb bontást vagy rövidebb intervallumot.']);
        }

        $rows = Ticket::query()
            ->leftJoin('reservations', 'reservations.id', '=', 'tickets.reservation_id')
            ->whereBetween('tickets.sold_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->orderBy('tickets.sold_at')
            ->get([
                'tickets.sold_at', 'tickets.price_amount', 'tickets.payment_method', 'tickets.category',
                'tickets.movie_title', 'reservations.customer_zip',
            ]);

        $series = $buckets;
        $zip = $payment = $program = $services = $category = [];
        $summary = ['tickets' => 0, 'services' => 0, 'revenue' => 0];

        foreach ($rows as $row) {
            $amount = (int) $row->price_amount;
            $isService = $row->category === TicketCategory::SERVICE;
            $method = $row->payment_method ?: 'unknown';

            $summary['revenue'] += $amount;
            $this->add($payment, $method, $method, 1, $amount);

            // A szolgáltatás-nyugták a bevételben szerepelnek, de nem jegyek (nem növelik a jegyszámot)
            $key = $this->bucketKey($row->sold_at->copy()->timezone(self::TZ), $group);
            if (isset($series[$key])) {
                $series[$key]['amount'] += $amount;
                if (!$isService) {
                    $series[$key]['tickets']++;
                }
            }

            if ($isService) {
                $summary['services']++;
                $this->add($services, $row->movie_title, $row->movie_title, 1, $amount);
                continue;
            }

            $summary['tickets']++;

            $zipValue = filled($row->customer_zip) ? mb_strtoupper(trim($row->customer_zip)) : null;
            $this->add($zip, $zipValue ?? '', $zipValue ?? 'Nincs megadva', 1, $amount);
            $this->add($program, $row->movie_title ?: 'Ismeretlen', $row->movie_title ?: 'Ismeretlen', 1, $amount);
            $this->add($category, $row->category ?: TicketCategory::STANDARD, TicketCategory::label($row->category), 1, $amount);
        }

        ActivityLogger::log('report.sales_stats', "Értékesítési statisztika megtekintése: {$data['from']} – {$data['to']} ({$group})", null, $data);

        return response()->json([
            'from' => $data['from'],
            'to' => $data['to'],
            'group' => $group,
            'summary' => $summary,
            'series' => array_values($series),
            'by_zip' => $this->sorted($zip, fn($a, $b) => ($a['key'] === '') <=> ($b['key'] === '') ?: $b['tickets'] <=> $a['tickets']),
            'by_payment' => $this->sorted($payment),
            'by_program' => $this->sorted($program),
            'by_category' => $this->sorted($category),
            'services' => $this->sorted($services),
        ]);
    }

    /** @return array<string,array{key:string,label:string,tickets:int,amount:int}> */
    private function buckets(Carbon $from, Carbon $to, string $group): array
    {
        $buckets = [];
        $cursor = $this->bucketStart($from, $group);

        while ($cursor->lte($to)) {
            $key = $this->bucketKey($cursor, $group);
            $buckets[$key] = ['key' => $key, 'label' => $this->bucketLabel($cursor, $group), 'tickets' => 0, 'amount' => 0];
            $cursor = match ($group) {
                'week' => $cursor->copy()->addWeek(),
                'month' => $cursor->copy()->addMonthNoOverflow(),
                'year' => $cursor->copy()->addYear(),
                'hour' => $cursor->copy()->addHour(),
                default => $cursor->copy()->addDay(),
            };
            if (count($buckets) > self::MAX_BUCKETS) {
                break;
            }
        }

        return $buckets;
    }

    private function bucketStart(Carbon $date, string $group): Carbon
    {
        return match ($group) {
            'week' => $date->copy()->startOfWeek(CarbonInterface::MONDAY),
            'month' => $date->copy()->startOfMonth(),
            'year' => $date->copy()->startOfYear(),
            'hour' => $date->copy()->startOfHour(),
            default => $date->copy()->startOfDay(),
        };
    }

    private function bucketKey(Carbon $date, string $group): string
    {
        return match ($group) {
            'week' => $date->copy()->startOfWeek(CarbonInterface::MONDAY)->format('Y-m-d'),
            'month' => $date->format('Y-m'),
            'year' => $date->format('Y'),
            'hour' => $date->format('Y-m-d H'),
            default => $date->format('Y-m-d'),
        };
    }

    private function bucketLabel(Carbon $start, string $group): string
    {
        return match ($group) {
            'week' => $start->format('Y.m.d.') . ' – ' . $start->copy()->endOfWeek(CarbonInterface::SUNDAY)->format('m.d.'),
            'month' => $start->format('Y.m'),
            'year' => $start->format('Y'),
            'hour' => $start->format('H:00'),
            default => $start->format('Y.m.d.'),
        };
    }

    private function add(array &$bucket, string $key, string $label, int $count, int $amount): void
    {
        $bucket[$key] ??= ['key' => $key, 'label' => $label, 'tickets' => 0, 'amount' => 0];
        $bucket[$key]['tickets'] += $count;
        $bucket[$key]['amount'] += $amount;
    }

    private function sorted(array $bucket, ?callable $by = null): array
    {
        $list = array_values($bucket);
        usort($list, $by ?? fn($a, $b) => $b['tickets'] <=> $a['tickets'] ?: $b['amount'] <=> $a['amount']);

        return $list;
    }
}
