<?php

namespace App\Services;

use App\Models\Showtime;

class PricingService
{
    /**
     * Calculate the price for a specific seat in a showtime.
     * 
     * Priority:
     * 1. Showtime pricing_override_json
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

        // 2. & 3. Auditorium layout
        if ($showtime->auditorium && $showtime->auditorium->layout_json) {
            $layout = $showtime->auditorium->layout_json;
            
            // New schema: flat seats array with sector/price
            if (isset($layout['seats']) && is_array($layout['seats'])) {
                foreach ($layout['seats'] as $seat) {
                    $key = $seat['key'] ?? null;
                    if (!$key && isset($seat['row'], $seat['column'])) {
                        $rowLabel = chr(64 + (int) $seat['row']);
                        $key = $rowLabel . '-' . str_pad((string) $seat['column'], 2, '0', STR_PAD_LEFT);
                    }
                    if ($key === $seatKey) {
                        if (isset($seat['price'])) {
                            return $seat['price'];
                        }

                        if (isset($seat['sector']) && isset($layout['sectors']) && is_array($layout['sectors'])) {
                            foreach ($layout['sectors'] as $sector) {
                                if (($sector['name'] ?? null) === $seat['sector'] && isset($sector['price'])) {
                                    return $sector['price'];
                                }
                            }
                        }
                        break;
                    }
                }
            }
            
            // Find the row
            [$rowLabel] = explode('-', $seatKey);
            
            foreach ($layout['rows'] ?? [] as $row) {
                if ($row['label'] === $rowLabel) {
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
        }

        // 4. Global default
        return $defaultPrice;
    }
}
