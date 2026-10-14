<?php

namespace App\Services;

use App\Exceptions\SeatUnavailableException;
use App\Models\ExtraService;
use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\SeatLock;
use App\Models\Showtime;
use App\Support\TicketCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * A székek kizárólagos lefoglalása. Egy szék egy előadásra csak egyszer foglalható és adható el:
 * minden foglalt VAGY eladott székhez tartozik egy seat_locks sor, és az (előadás, szék) pár az
 * adatbázisban egyedi. Az eladott székek zárolása nem törlődik, csak végleges lesz (expires_at = null),
 * ezért az egyidejű kérések (online foglalás, pénztári eladás, késői fizetés) sem tudják ugyanazt a
 * széket kétszer megszerezni.
 */
class SeatClaimService
{
    public function __construct(
        private SeatMapService $seatMap,
        private PricingService $pricing
    ) {
    }

    /**
     * Új foglalás székeinek ellenőrzése, a tételek és a zárolások létrehozása. Tranzakcióban hívandó.
     *
     * @param array<int,string> $seatKeys
     * @param array<string,string> $categories szék kulcs => jegykategória (Dolgozó jegy: a beállított ár, Élménykártya: 0 Ft;
     *                                          a terem szék-árától függetlenül)
     * @return int a foglalás végösszege
     * @throws SeatUnavailableException
     */
    public function claimSeats(Showtime $showtime, Reservation $reservation, array $seatKeys, ?Carbon $expiresAt, array $categories = []): int
    {
        $this->releaseStale($showtime);

        $seats = $this->flatSeatMap($showtime);
        foreach ($seatKeys as $key) {
            $seat = $seats[$key] ?? null;

            if (!$seat || !$seat['bookable'] || $seat['type'] === 'aisle') {
                throw new SeatUnavailableException("A(z) {$key} szék nem foglalható.", 422);
            }
            if ($seat['status'] !== 'available') {
                throw new SeatUnavailableException("A(z) {$key} szék már foglalt vagy eladott.", 409);
            }
        }

        $staffPrice = null;
        $total = 0;
        foreach ($seatKeys as $key) {
            $category = TicketCategory::normalizeSeat($categories[$key] ?? null);
            $price = match ($category) {
                TicketCategory::STAFF => $staffPrice ??= ExtraService::staffPrice(),
                TicketCategory::EXPERIENCE_CARD => 0,
                default => (int) $seats[$key]['price'],
            };
            [$rowLabel, $number] = array_pad(explode('-', $key), 2, null);

            ReservationItem::create([
                'reservation_id' => $reservation->id,
                'seat_key' => $key,
                'row_label' => $rowLabel,
                'seat_number' => intval($number) ?: null,
                'price_amount' => $price,
                'category' => $category,
            ]);

            $this->createLock($showtime->id, $reservation->id, $key, $expiresAt);
            $total += $price;
        }

        $reservation->update(['total_amount' => $total]);

        return $total;
    }

    /**
     * Fizetéskor: a foglalás minden széke a foglalásé marad, és a zárolás véglegessé válik.
     * Ha a zárolás közben lejárt és a széket már más megszerezte, kivételt dob (a fizetés nem véglegesíthető).
     * Tranzakcióban hívandó.
     *
     * @throws SeatUnavailableException
     */
    public function finalizeClaims(Reservation $reservation): void
    {
        $reservation->loadMissing('items');

        foreach ($reservation->items as $item) {
            // Védelem a régi adatokra: más, már kifizetett foglalás ne vihesse el ugyanazt a széket
            $soldElsewhere = ReservationItem::where('seat_key', $item->seat_key)
                ->where('reservation_id', '!=', $reservation->id)
                ->whereHas('reservation', fn($q) => $q->where('showtime_id', $reservation->showtime_id)->where('status', 'PAID'))
                ->exists();

            $lock = SeatLock::where('showtime_id', $reservation->showtime_id)->where('seat_key', $item->seat_key)->first();

            if ($soldElsewhere || ($lock && $lock->reservation_id !== $reservation->id)) {
                throw new SeatUnavailableException("A(z) {$item->seat_key} szék időközben másé lett.", 409);
            }

            if (!$lock) {
                $this->createLock($reservation->showtime_id, $reservation->id, $item->seat_key, null);
            } elseif ($lock->expires_at !== null) {
                $lock->update(['expires_at' => null]);
            }
        }
    }

    /**
     * Az online foglalás zárolásának lejáratát módosítja (fizetés indításakor kitolja, sikertelen fizetésnél rövidíti).
     * A lejárat nélküli (pénztári) foglalásokhoz nem nyúl. Tranzakción kívül is hívható.
     */
    public function adjustHold(Reservation $reservation, Carbon $until, bool $onlyShrink = false): void
    {
        $reservation->refresh();

        if ($reservation->status !== 'PENDING' || $reservation->expires_at === null) {
            return;
        }

        $shouldChange = $onlyShrink ? $reservation->expires_at->gt($until) : $reservation->expires_at->lt($until);
        if (!$shouldChange) {
            return;
        }

        $reservation->update(['expires_at' => $until]);
        SeatLock::where('reservation_id', $reservation->id)->whereNotNull('expires_at')->update(['expires_at' => $until]);
    }

    /** Annak ellenőrzése, hogy a foglalás székei még a foglaláshoz tartoznak (pl. fizetés indítása előtt). */
    public function assertStillHeld(Reservation $reservation): void
    {
        $reservation->loadMissing('items');

        $held = SeatLock::where('reservation_id', $reservation->id)
            ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();

        if ($held < $reservation->items->count()) {
            throw new SeatUnavailableException('A foglalás székei már nem tartoznak a foglaláshoz (lejárt a zárolás).', 409);
        }
    }

    /** Lejárt és érvénytelen (lemondott, lejárt foglaláshoz tartozó) zárolások törlése az előadásra. */
    public function releaseStale(Showtime $showtime): void
    {
        // A lejárt online foglalások lejártnak minősülnek (ezt nem bízzuk egy ütemezett feladatra)
        Reservation::where('showtime_id', $showtime->id)
            ->where('status', 'PENDING')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => 'EXPIRED']);

        SeatLock::where('showtime_id', $showtime->id)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();

        SeatLock::where('showtime_id', $showtime->id)
            ->whereHas('reservation', fn($q) => $q->whereIn('status', ['CANCELLED', 'EXPIRED']))
            ->delete();
    }

    private function createLock(int $showtimeId, int $reservationId, string $seatKey, ?Carbon $expiresAt): void
    {
        try {
            SeatLock::create([
                'showtime_id' => $showtimeId,
                'reservation_id' => $reservationId,
                'seat_key' => $seatKey,
                'expires_at' => $expiresAt,
            ]);
        } catch (QueryException $e) {
            // Az (előadás, szék) egyediség sérült: közben más megszerezte a széket
            if (in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                throw new SeatUnavailableException("A(z) {$seatKey} szék közben már foglalttá vált.", 409);
            }

            throw $e;
        }
    }

    /** @return array<string, array> szék kulcs => szék adatai */
    private function flatSeatMap(Showtime $showtime): array
    {
        $map = $this->seatMap->getFullSeatMap($showtime, $this->pricing);
        $seats = [];
        foreach ($map['seats'] as $rowSeats) {
            foreach ($rowSeats as $key => $seat) {
                $seats[$key] = $seat;
            }
        }

        return $seats;
    }
}
