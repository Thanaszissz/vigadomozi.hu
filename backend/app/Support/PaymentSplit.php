<?php

namespace App\Support;

/**
 * Több fizetési móddal (részfizetéssel) kifizetett eladás kezelése.
 *
 * A befizetett összegek jegyenként, sorrendben „folynak” végig a fizetési módokon: egy jegy, amelyet egyetlen mód fedez,
 * egyszerű jegy marad (payment_method = az adott mód); ha egy jegy ára több módra oszlik, payment_method = „mixed”,
 * és a payment_split a módonkénti összegeket tartalmazza. Így a módonkénti összeg minden statisztikában pontos.
 */
final class PaymentSplit
{
    public const MIXED = 'mixed';

    /** Részfizetésnél választható fizetési módok. */
    public const METHODS = ['cash', 'card', 'szep_card', 'experience_card', 'voucher'];

    public const LABELS = [
        'cash' => 'Készpénz',
        'card' => 'Bankkártya',
        'szep_card' => 'SZÉP kártya',
        'experience_card' => 'Élménykártya',
        'voucher' => 'Utalvány',
        'stripe' => 'Online',
        'simplepay' => 'Online (SimplePay)',
        self::MIXED => 'Vegyes fizetés',
    ];

    /** @var array<int,array{method:string,left:int}> */
    private array $queue = [];

    /** @param array<int,array{method:string,amount:int}> $payments */
    public function __construct(array $payments)
    {
        foreach (self::normalize($payments) as $p) {
            $this->queue[] = ['method' => $p['method'], 'left' => $p['amount']];
        }
    }

    /**
     * Az azonos módok összevonása, a nulla (vagy negatív) összegű sorok elhagyása.
     *
     * @param array<int,array{method:string,amount:int|float|string}> $payments
     * @return array<int,array{method:string,amount:int}>
     */
    public static function normalize(array $payments): array
    {
        $merged = [];
        foreach ($payments as $p) {
            $amount = (int) ($p['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $merged[$p['method']] = ($merged[$p['method']] ?? 0) + $amount;
        }

        $result = [];
        foreach ($merged as $method => $amount) {
            $result[] = ['method' => (string) $method, 'amount' => $amount];
        }

        return $result;
    }

    /**
     * Egy jegy árának fedezése a hátralévő összegekből.
     *
     * @return array{0:string,1:array<int,array{method:string,amount:int}>|null} [payment_method, payment_split]
     */
    public function take(int $price): array
    {
        if ($price <= 0) {
            return [$this->queue[0]['method'] ?? 'cash', null];
        }

        $parts = [];
        $need = $price;
        while ($need > 0 && $this->queue) {
            $take = min($need, $this->queue[0]['left']);
            $parts[] = ['method' => $this->queue[0]['method'], 'amount' => $take];
            $this->queue[0]['left'] -= $take;
            $need -= $take;
            if ($this->queue[0]['left'] <= 0) {
                array_shift($this->queue);
            }
        }

        if (!$parts) {
            return ['cash', null];
        }

        return count($parts) === 1 ? [$parts[0]['method'], null] : [self::MIXED, $parts];
    }

    /**
     * Módonkénti összeg egy jegycsoportban (a vegyes jegyek a részösszegek szerint bontva).
     *
     * @param iterable<object> $tickets
     * @return array<string,int>
     */
    public static function amounts(iterable $tickets): array
    {
        $sums = [];
        foreach ($tickets as $t) {
            foreach (self::parts($t) as $p) {
                $sums[$p['method']] = ($sums[$p['method']] ?? 0) + $p['amount'];
            }
        }

        return $sums;
    }

    /**
     * Módonkénti darabszám és összeg (a vegyes jegy minden érintett módnál egyszer szerepel).
     *
     * @param iterable<object> $tickets
     * @return array<string,array{payment_method:string,tickets:int,amount:int}>
     */
    public static function summary(iterable $tickets): array
    {
        $rows = [];
        foreach ($tickets as $t) {
            foreach (self::parts($t) as $p) {
                $m = $p['method'];
                $rows[$m] ??= ['payment_method' => $m, 'tickets' => 0, 'amount' => 0];
                $rows[$m]['tickets']++;
                $rows[$m]['amount'] += $p['amount'];
            }
        }

        return $rows;
    }

    /** @return array<int,array{method:string,amount:int}> a jegy módonkénti részei */
    public static function parts(object $ticket): array
    {
        $split = $ticket->payment_split ?? null;
        if (is_array($split) && $split) {
            return array_map(fn($p) => ['method' => (string) $p['method'], 'amount' => (int) $p['amount']], $split);
        }

        return [['method' => (string) $ticket->payment_method, 'amount' => (int) $ticket->price_amount]];
    }

    public static function label(?string $method): string
    {
        return self::LABELS[$method] ?? (string) $method;
    }

    /** Emberi leírás exporthoz / naplóhoz, pl. „Készpénz 1 000 Ft + Élménykártya 1 000 Ft”. */
    public static function describe(object $ticket): string
    {
        $split = $ticket->payment_split ?? null;
        if (!is_array($split) || !$split) {
            return self::label($ticket->payment_method);
        }

        return implode(' + ', array_map(
            fn($p) => self::label($p['method']) . ' ' . number_format((int) $p['amount'], 0, ',', ' ') . ' Ft',
            $split
        ));
    }
}
