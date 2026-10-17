<?php

namespace App\Services;

use App\Models\Showtime;
use App\Support\SeatKey;

class PricingService
{
    /**
     * Calculate the price for a specific seat in a showtime.
     * 
     * Priority:
     * 1. Showtime pricing_override_json
     * 1b. Theater show ticket_price
     * 2. Auditorium layout: seat.price
     * 3. Row basePrice
     * 4. Global default
     */
    public function priceForSeat(Showtime $showtime, string $seatKey): int
    {
        $defaultPrice = 2000; // HUF

        // 1. Showtime override
        if ($showtime->pricing_override_json) {
            $override = $showtime->pricing_override_json;
            if (isset($override['seats'][$seatKey])) {
                return $override['seats'][$seatKey];
            }
            if (isset($override['rows'])) {
                [$row] = explode('-', $seatKey);
                if (isset($override['rows'][$row])) {
                    return $override['rows'][$row];
                }
            }
        }

        // Előadás / program: saját jegyár, a terem szektorárai helyett
        if ($showtime->theater_show_id !== null && $showtime->theaterShow?->ticket_price !== null) {
            return (int) $showtime->theaterShow->ticket_price;
        }

        // 2. & 3. Auditorium layout
        if ($showtime->auditorium && $showtime->auditorium->layout_json) {
            $layout = $showtime->auditorium->layout_json;
            $parts = SeatKey::parse($seatKey);
            $legacyKey = SeatKey::make(null, $parts['row'], (int) $parts['number']);
            
            // New schema: flat seats array with sector/price (kulcs szerint indexelve, hogy sok széknél is gyors legyen)
            $index = $this->layoutIndex($showtime->auditorium->id, $layout);
            $seat = $index['seats'][$seatKey] ?? ($parts['section'] !== null ? ($index['seats'][$legacyKey] ?? null) : null);
            if ($seat !== null) {
                // A szektor ára az irányadó (így a szektor árának módosítása a már kijelölt székekre is érvényes)
                if (isset($seat['sector']) && isset($index['sectorPrices'][$seat['sector']])) {
                    return $index['sectorPrices'][$seat['sector']];
                }

                if (isset($seat['price'])) {
                    return (int) $seat['price'];
                }
            }
            
            // Find the row
            $rowLabel = $parts['row'];
            
            foreach ($layout['rows'] ?? [] as $row) {
                if ((string) $row['label'] === $rowLabel && ($row['section'] ?? null) == $parts['section']) {
                    // Find seat in row
                    foreach ($row['seats'] ?? [] as $seat) {
                        if ($seat['key'] === $seatKey) {
                            // Seat has specific price override
                            if (isset($seat['price'])) {
                                return $seat['price'];
                            }
                            break;
                        }
                    }
                    // Use row base price
                    if (isset($row['basePrice'])) {
                        return $row['basePrice'];
                    }
                    break;
                }
            }

            // Szektor nélküli szék: az első (alapértelmezett) szektor ára
            $defaultSectorPrice = $layout['sectors'][0]['price'] ?? null;
            if (is_numeric($defaultSectorPrice)) {
                return (int) $defaultSectorPrice;
            }
        }

        // 4. Global default
        return $defaultPrice;
    }

    /** @var array<int,array{seats: array<string,array>, sectorPrices: array<string,int>}> */
    private array $layoutIndexCache = [];

    /** A terem elrendezése kulcs szerint indexelve (egy kérésen belül egyszer épül fel). */
    private function layoutIndex(int $auditoriumId, array $layout): array
    {
        if (isset($this->layoutIndexCache[$auditoriumId])) {
            return $this->layoutIndexCache[$auditoriumId];
        }

        $seats = [];
        foreach ($layout['seats'] ?? [] as $seat) {
            $key = $seat['key'] ?? null;
            if (!$key && isset($seat['row'], $seat['column'])) {
                $key = chr(64 + (int) $seat['row']) . '-' . str_pad((string) $seat['column'], 2, '0', STR_PAD_LEFT);
            }
            if ($key !== null && !isset($seats[$key])) {
                $seats[$key] = $seat;
            }
        }

        $sectorPrices = [];
        foreach ($layout['sectors'] ?? [] as $sector) {
            if (isset($sector['name'], $sector['price']) && !isset($sectorPrices[$sector['name']])) {
                $sectorPrices[$sector['name']] = (int) $sector['price'];
            }
        }

        return $this->layoutIndexCache[$auditoriumId] = ['seats' => $seats, 'sectorPrices' => $sectorPrices];
    }
}
