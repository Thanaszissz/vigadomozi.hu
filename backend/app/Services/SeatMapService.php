<?php

namespace App\Services;

use App\Models\Showtime;
use App\Models\ReservationItem;
use App\Models\SeatLock;

class SeatMapService
{
    /**
     * Get the seat map status for a showtime.
     * Returns booked seats and locked seats.
     */
    public function getSeatStatus(Showtime $showtime)
    {
        $bookedSeats = ReservationItem::query()
            ->whereHas('reservation', fn($q) => $q
                ->where('showtime_id', $showtime->id)
                ->where('status', 'PAID')
            )
            ->pluck('seat_key')
            ->toArray();

        $lockedSeats = SeatLock::where('showtime_id', $showtime->id)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            // Az eladott székek zárolása végleges, de azok "eladottként" látszanak, nem "foglaltként"
            ->whereHas('reservation', fn($q) => $q->where('status', 'PENDING'))
            ->pluck('seat_key')
            ->toArray();

        return [
            'booked' => $bookedSeats,
            'locked' => $lockedSeats,
        ];
    }

    /**
     * Get all seats from auditorium layout with their status and prices.
     */
    public function getFullSeatMap(Showtime $showtime, PricingService $pricingService)
    {
        $auditorium = $showtime->auditorium;
        $status = $this->getSeatStatus($showtime);
        $layout = $auditorium->layout_json ?? null;
        $seatOverrides = [];
        if ($layout && isset($layout['seats']) && is_array($layout['seats'])) {
            foreach ($layout['seats'] as $seat) {
                $key = $seat['key'] ?? null;
                if (!$key && isset($seat['row'], $seat['column'])) {
                    $rowLabel = chr(64 + (int) $seat['row']);
                    $key = $rowLabel . '-' . str_pad((string) $seat['column'], 2, '0', STR_PAD_LEFT);
                }
                if ($key) {
                    $seatOverrides[$key] = $seat;
                }
            }
        }

        $seatMap = [];

        $rowsConfig = $layout['rows'] ?? [];
        if (!empty($rowsConfig)) {
            $rowIndex = 1;
            foreach ($rowsConfig as $rowConfig) {
                $rowLabel = $rowConfig['label'] ?? chr(64 + $rowIndex);
                $columns = (int) ($rowConfig['columns'] ?? $auditorium->columns);
                $defaultSection = $rowConfig['section'] ?? null;

                $seatMap[$rowLabel] = [];
                for ($col = 1; $col <= $columns; $col++) {
                    $seatKey = $rowLabel . '-' . str_pad($col, 2, '0', STR_PAD_LEFT);
                    $override = $seatOverrides[$seatKey] ?? null;
                    $blocked = (bool) ($override['blocked'] ?? false);
                    $bookable = !$blocked;
                    $type = $override['sector'] ?? 'STANDARD';
                    $section = $override['section'] ?? $defaultSection;

                    $seatMap[$rowLabel][$seatKey] = [
                        'key' => $seatKey,
                        'type' => $type,
                        'section' => $section,
                        'bookable' => $bookable,
                        'status' => $blocked ? 'locked' : (in_array($seatKey, $status['booked']) ? 'booked'
                                   : (in_array($seatKey, $status['locked']) ? 'locked' : 'available')),
                        'price' => $pricingService->priceForSeat($showtime, $seatKey),
                    ];
                }
                $rowIndex++;
            }
        } else {
            // Use schema: rows and columns integers
            for ($row = 1; $row <= $auditorium->rows; $row++) {
                $rowLabel = chr(64 + $row); // A, B, C, ...
                $seatMap[$rowLabel] = [];

                for ($col = 1; $col <= $auditorium->columns; $col++) {
                    $seatKey = $rowLabel . '-' . str_pad($col, 2, '0', STR_PAD_LEFT);
                    $override = $seatOverrides[$seatKey] ?? null;
                    $blocked = (bool) ($override['blocked'] ?? false);
                    $bookable = !$blocked;
                    $type = $override['sector'] ?? 'STANDARD';
                    $section = $override['section'] ?? null;

                    $seatMap[$rowLabel][$seatKey] = [
                        'key' => $seatKey,
                        'type' => $type,
                        'section' => $section,
                        'bookable' => $bookable,
                        'status' => $blocked ? 'locked' : (in_array($seatKey, $status['booked']) ? 'booked'
                                   : (in_array($seatKey, $status['locked']) ? 'locked' : 'available')),
                        'price' => $pricingService->priceForSeat($showtime, $seatKey),
                    ];
                }
            }
        }
        
        return [
            'layout' => [
                'screenLabel' => 'VÁSZON',
                'rows' => $auditorium->rows,
                'columns' => $auditorium->columns,
                'aisles' => $this->aisles($layout, $seatMap),
            ],
            'seats' => $seatMap,
            'status' => $status,
        ];
    }

    /**
     * Folyosó a sor közepén: sorjel => a folyosó előtti székek száma.
     * A folyosó szintenként (elkülönített részenként) kapcsolható, a szint nélküli soroknak külön beállítása van.
     */
    private function aisles(?array $layout, array $seatMap): array
    {
        $aisleSections = [];
        foreach ($layout['sections'] ?? [] as $section) {
            if (!empty($section['aisle']) && isset($section['name'])) {
                $aisleSections[$section['name']] = true;
            }
        }
        $aisleUnsectioned = (bool) ($layout['no_section_aisle'] ?? false);

        $aisles = [];
        foreach ($seatMap as $rowLabel => $seats) {
            $count = count($seats);
            if ($count < 2) {
                continue;
            }

            $section = null;
            foreach ($seats as $seat) {
                if (!empty($seat['section'])) {
                    $section = $seat['section'];
                    break;
                }
            }

            $hasAisle = $section !== null ? isset($aisleSections[$section]) : $aisleUnsectioned;
            if ($hasAisle) {
                $aisles[$rowLabel] = (int) ceil($count / 2);
            }
        }

        return $aisles;
    }
}
