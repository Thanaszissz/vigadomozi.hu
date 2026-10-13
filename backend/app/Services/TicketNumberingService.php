<?php

namespace App\Services;

use App\Exceptions\TicketNumberingException;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A jegyszámozás: egy jegyszám = ELŐTAG + "-" + folyamatos 6 jegyű sorszám (pl. 2026-000123).
 * A sorszámot minden eladott jegy eggyel növeli (nem előadásonként). A számláló előtagonként külön számol,
 * és a jegyszám az adatbázisban egyedi, így kétszer nem adható ki ugyanaz a szám.
 */
class TicketNumberingService
{
    public const SETTING_KEY = 'ticket_prefix';
    public const MAX_COUNTER = 9999999;
    public const CONFIRM_TTL_SECONDS = 300;
    public const RESET_PHRASE = 'NULLÁZÁS';

    public function currentPrefix(): string
    {
        $value = Setting::find(self::SETTING_KEY)?->value;
        if ($value) {
            return $value;
        }

        $default = (string) now('Europe/Budapest')->year;
        Setting::updateOrCreate(['key' => self::SETTING_KEY], ['value' => $default]);

        return $default;
    }

    public function format(string $prefix, int $seq): string
    {
        return sprintf('%s-%06d', $prefix, $seq);
    }

    public function normalizePrefix(string $raw): string
    {
        return strtoupper(trim($raw));
    }

    public function isValidPrefix(string $prefix): bool
    {
        return (bool) preg_match('/^[A-Z0-9]{1,12}$/', $prefix);
    }

    /** A következő sorszám kiadása. Tranzakcióban kell hívni; a számláló sora zárolt. */
    public function nextSequence(string $prefix): int
    {
        $now = now();
        DB::table('ticket_counters')->insertOrIgnore(['prefix' => $prefix, 'last_number' => 0, 'created_at' => $now, 'updated_at' => $now]);

        $row = DB::table('ticket_counters')->where('prefix', $prefix)->lockForUpdate()->first();
        // Védelem: a számláló sosem lehet kisebb a már kiadott legnagyobb sorszámnál
        $next = max((int) $row->last_number, $this->maxIssued($prefix)) + 1;

        DB::table('ticket_counters')->where('prefix', $prefix)->update(['last_number' => $next, 'updated_at' => $now]);

        return $next;
    }

    public function maxIssued(string $prefix): int
    {
        return (int) Ticket::withoutGlobalScopes()->where('ticket_prefix', $prefix)->max('ticket_seq');
    }

    public function counterOf(string $prefix): int
    {
        return (int) DB::table('ticket_counters')->where('prefix', $prefix)->value('last_number');
    }

    /** Az utoljára kiadott sorszám az előtaghoz (a számláló és a kiadott jegyek közül a nagyobb). */
    public function lastNumber(string $prefix): int
    {
        return max($this->counterOf($prefix), $this->maxIssued($prefix));
    }

    public function state(): array
    {
        $prefix = $this->currentPrefix();
        $last = $this->lastNumber($prefix);

        $latest = Ticket::withoutGlobalScopes()->orderByDesc('id')->first();
        $previous = Ticket::withoutGlobalScopes()->where('ticket_prefix', '!=', $prefix)->orderByDesc('id')->first();

        $stats = Ticket::withoutGlobalScopes()
            ->selectRaw('ticket_prefix, count(*) as tickets, max(ticket_seq) as max_seq, max(sold_at) as last_sold_at')
            ->groupBy('ticket_prefix')
            ->get()
            ->keyBy('ticket_prefix');

        $prefixes = collect(DB::table('ticket_counters')->pluck('prefix'))->merge($stats->keys())->push($prefix)->unique()->sort()->values();

        $counters = $prefixes->map(fn($p) => [
            'prefix' => $p,
            'last_number' => $this->lastNumber($p),
            'tickets' => (int) ($stats[$p]->tickets ?? 0),
            'last_sold_at' => $stats[$p]->last_sold_at ?? null,
            'is_current' => $p === $prefix,
        ])->values();

        $currentYear = (int) now('Europe/Budapest')->year;

        return [
            'prefix' => $prefix,
            'last_number' => $last,
            'last_number_formatted' => $last > 0 ? $this->format($prefix, $last) : null,
            'next_number' => $this->format($prefix, $last + 1),
            'min_counter' => $this->maxIssued($prefix),
            'max_counter' => self::MAX_COUNTER,
            'last_ticket' => $latest ? [
                'number' => $latest->ticket_number,
                'sold_at' => $latest->sold_at,
                'prefix' => $latest->ticket_prefix,
                'seq' => (int) $latest->ticket_seq,
            ] : null,
            // Az előző előtag utolsó jegye: erről lehet folytatni, ha a számlálót véletlenül nullázták
            'previous_prefix_last' => $previous ? [
                'number' => $previous->ticket_number,
                'prefix' => $previous->ticket_prefix,
                'seq' => (int) $previous->ticket_seq,
            ] : null,
            'counters' => $counters,
            'year_notice' => ctype_digit($prefix) && strlen($prefix) === 4 && (int) $prefix < $currentYear,
            'current_year' => $currentYear,
            'reset_phrase' => self::RESET_PHRASE,
        ];
    }

    /**
     * Első lépés: a kért módosítás ellenőrzése és összefoglalása. Egyszer használható megerősítő kódot ad.
     *
     * @throws TicketNumberingException
     */
    public function prepare(User $user, array $input): array
    {
        $plan = $this->plan($input);

        $token = Str::random(48);
        Cache::put($this->cacheKey($token), [
            'user_id' => $user->id,
            'input' => $input,
            'phrase' => $plan['phrase'],
            'signature' => $plan['signature'],
        ], self::CONFIRM_TTL_SECONDS);

        return [
            'token' => $token,
            'expires_in' => self::CONFIRM_TTL_SECONDS,
            'action' => $plan['action'],
            'phrase' => $plan['phrase'],
            'summary' => $plan['summary'],
            'warnings' => $plan['warnings'],
        ];
    }

    /**
     * Második lépés: a módosítás végrehajtása a megerősítő kóddal és a beírt megerősítő szöveggel.
     *
     * @throws TicketNumberingException
     */
    public function apply(User $user, string $token, string $phrase): array
    {
        $stored = Cache::pull($this->cacheKey($token));

        if (!$stored || $stored['user_id'] !== $user->id) {
            throw new TicketNumberingException('A megerősítés lejárt vagy érvénytelen. Kezdd újra a módosítást.');
        }

        if (!hash_equals($stored['phrase'], trim($phrase))) {
            throw new TicketNumberingException('A megerősítő szöveg nem egyezik. A módosítás nem történt meg, kezdd újra.');
        }

        return DB::transaction(function () use ($stored) {
            // A módosítás kiértékelése a tranzakcióban, friss adatokkal
            Setting::lockForUpdate()->find(self::SETTING_KEY);
            $plan = $this->plan($stored['input']);

            if ($plan['signature'] !== $stored['signature']) {
                throw new TicketNumberingException('Közben megváltozott a jegyszámozás állapota (pl. jegyet adtak el). Kezdd újra a módosítást a friss adatokkal.');
            }

            return $plan['action'] === 'prefix' ? $this->applyPrefix($plan) : $this->applyCounter($plan);
        });
    }

    private function applyPrefix(array $plan): array
    {
        $old = $this->currentPrefix();
        $oldLast = $this->lastNumber($old);
        $now = now();

        DB::table('ticket_counters')->insertOrIgnore(['prefix' => $plan['prefix'], 'last_number' => 0, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('ticket_counters')->where('prefix', $plan['prefix'])->lockForUpdate()->first();
        DB::table('ticket_counters')->where('prefix', $plan['prefix'])->update(['last_number' => $plan['resulting_counter'], 'updated_at' => $now]);
        Setting::updateOrCreate(['key' => self::SETTING_KEY], ['value' => $plan['prefix']]);

        return [
            'action' => 'prefix',
            'description' => sprintf(
                'Jegyszám előtag módosítva: %s → %s (%s), következő jegy: %s',
                $old,
                $plan['prefix'],
                $plan['mode'] === 'reset' ? 'nullázással' : 'folytatással',
                $this->format($plan['prefix'], $plan['resulting_counter'] + 1)
            ),
            'properties' => [
                'old_prefix' => $old,
                'new_prefix' => $plan['prefix'],
                'mode' => $plan['mode'],
                'old_last_number' => $oldLast,
                'new_last_number' => $plan['resulting_counter'],
                'next_number' => $this->format($plan['prefix'], $plan['resulting_counter'] + 1),
            ],
        ];
    }

    private function applyCounter(array $plan): array
    {
        $prefix = $plan['prefix'];
        $now = now();
        $before = $this->lastNumber($prefix);

        DB::table('ticket_counters')->insertOrIgnore(['prefix' => $prefix, 'last_number' => 0, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('ticket_counters')->where('prefix', $prefix)->lockForUpdate()->first();
        DB::table('ticket_counters')->where('prefix', $prefix)->update(['last_number' => $plan['value'], 'updated_at' => $now]);

        return [
            'action' => 'counter',
            'description' => sprintf(
                'Jegyszám-számláló beállítva (%s): %d → %d, következő jegy: %s',
                $prefix,
                $before,
                $plan['value'],
                $this->format($prefix, $plan['value'] + 1)
            ),
            'properties' => [
                'prefix' => $prefix,
                'old_last_number' => $before,
                'new_last_number' => $plan['value'],
                'next_number' => $this->format($prefix, $plan['value'] + 1),
            ],
        ];
    }

    /**
     * A kért módosítás szabályainak ellenőrzése és terve.
     *
     * @throws TicketNumberingException
     */
    private function plan(array $input): array
    {
        return match ($input['action'] ?? '') {
            'prefix' => $this->planPrefix($input),
            'counter' => $this->planCounter($input),
            default => throw new TicketNumberingException('Ismeretlen művelet.'),
        };
    }

    private function planPrefix(array $input): array
    {
        $new = $this->normalizePrefix((string) ($input['prefix'] ?? ''));
        $mode = (string) ($input['mode'] ?? '');
        $current = $this->currentPrefix();

        if (!$this->isValidPrefix($new)) {
            throw new TicketNumberingException('Az előtag 1–12 karakter lehet, csak betűből és számból állhat (ékezet, szóköz és kötőjel nélkül).');
        }
        if ($new === $current) {
            throw new TicketNumberingException('Az új előtag megegyezik a jelenlegivel.');
        }
        if (!in_array($mode, ['continue', 'reset'], true)) {
            throw new TicketNumberingException('Válaszd ki, hogy a számlálás folytatódjon vagy nullázódjon.');
        }

        $currentLast = $this->lastNumber($current);
        $newIssued = $this->maxIssued($new);

        if ($mode === 'reset' && $newIssued > 0) {
            throw new TicketNumberingException(sprintf(
                'A(z) %s előtaggal már kiadtak jegyeket (utolsó: %s). Nullázással ugyanazok a jegyszámok újra kiadódnának, ezért ez nem engedélyezett. Válaszd a folytatást, vagy utólag állítsd be a számlálót.',
                $new,
                $this->format($new, $newIssued)
            ));
        }

        $resulting = $mode === 'reset' ? 0 : max($currentLast, $this->counterOf($new), $newIssued);

        $summary = ["Jegyszám előtag: {$current} → {$new}"];
        $warnings = [];

        if ($mode === 'reset') {
            $summary[] = 'A sorszám nullázódik: az új előtaggal az első jegy száma ' . $this->format($new, 1) . ' lesz.';
            $warnings[] = "A nullázás után a jegyek sorszáma újra 1-ről indul. A korábbi, {$current} előtagú jegyek változatlanok maradnak.";
            $warnings[] = 'Ha véletlenül nullázol, a számlálót később be lehet állítani oda, ahol abbahagytátok (de csak felfelé, kiadott jegyek alá nem).';
        } else {
            $summary[] = 'A sorszám folytatódik: a következő jegy száma ' . $this->format($new, $resulting + 1) . ' lesz.';
        }

        return [
            'action' => 'prefix',
            'prefix' => $new,
            'mode' => $mode,
            'resulting_counter' => $resulting,
            'phrase' => $mode === 'reset' ? self::RESET_PHRASE : $new,
            'summary' => $summary,
            'warnings' => $warnings,
            'signature' => $this->signature($current),
        ];
    }

    private function planCounter(array $input): array
    {
        $raw = $input['value'] ?? null;
        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_COUNTER]]);
        if ($value === false) {
            throw new TicketNumberingException('A sorszám egész szám lehet 0 és ' . number_format(self::MAX_COUNTER, 0, ',', ' ') . ' között.');
        }

        $prefix = $this->currentPrefix();
        $issued = $this->maxIssued($prefix);
        $last = $this->lastNumber($prefix);

        if ($value < $issued) {
            throw new TicketNumberingException(sprintf(
                'A(z) %s előtaggal már kiadtak jegyet (utolsó sorszám: %d). Ennél kisebb szám duplikált jegyszámokat okozna, ezért nem engedélyezett.',
                $prefix,
                $issued
            ));
        }
        if ($value === $last) {
            throw new TicketNumberingException('A számláló már ezen az értéken áll.');
        }

        $summary = [
            "Előtag: {$prefix}",
            "Utoljára kiadott sorszám: {$last} → {$value}",
            'A következő jegy száma ' . $this->format($prefix, $value + 1) . ' lesz.',
        ];
        $warnings = [];
        if ($value > $last) {
            $warnings[] = sprintf('A kimaradó sorszámok (%d–%d) nem kerülnek kiadásra.', $last + 1, $value);
        } else {
            $warnings[] = "A számláló csökken ({$last} → {$value}). Ez csak azért engedélyezett, mert eddig egyik kiadott jegy sem érte el ezt a számot.";
        }

        return [
            'action' => 'counter',
            'prefix' => $prefix,
            'value' => $value,
            'phrase' => (string) $value,
            'summary' => $summary,
            'warnings' => $warnings,
            'signature' => $this->signature($prefix),
        ];
    }

    private function signature(string $prefix): string
    {
        return hash('sha256', implode('|', [$prefix, $this->counterOf($prefix), $this->maxIssued($prefix), $this->currentPrefix()]));
    }

    private function cacheKey(string $token): string
    {
        return 'ticketing_confirm:' . hash('sha256', $token);
    }
}
