<?php

namespace Tests\Unit;

use App\Support\PaymentSplit;
use PHPUnit\Framework\TestCase;

class PaymentSplitTest extends TestCase
{
    public function test_amounts_flow_through_tickets_in_order(): void
    {
        // 10 000 Ft, 5 db 2 000 Ft-os jegy: 3000 készpénz, 2000 élménykártya, 1000 bankkártya, 4000 utalvány
        $split = new PaymentSplit([
            ['method' => 'cash', 'amount' => 3000],
            ['method' => 'experience_card', 'amount' => 2000],
            ['method' => 'card', 'amount' => 1000],
            ['method' => 'voucher', 'amount' => 4000],
        ]);

        $tickets = [];
        for ($i = 0; $i < 5; $i++) {
            [$method, $parts] = $split->take(2000);
            $tickets[] = (object) ['payment_method' => $method, 'payment_split' => $parts, 'price_amount' => 2000];
        }

        $this->assertSame('cash', $tickets[0]->payment_method);
        $this->assertNull($tickets[0]->payment_split);
        $this->assertSame('mixed', $tickets[1]->payment_method);
        $this->assertSame([['method' => 'cash', 'amount' => 1000], ['method' => 'experience_card', 'amount' => 1000]], $tickets[1]->payment_split);
        $this->assertSame('voucher', $tickets[3]->payment_method);
        $this->assertSame('voucher', $tickets[4]->payment_method);

        $this->assertSame(
            ['cash' => 3000, 'experience_card' => 2000, 'card' => 1000, 'voucher' => 4000],
            PaymentSplit::amounts($tickets)
        );
        $this->assertSame(10000, array_sum(PaymentSplit::amounts($tickets)));
    }

    public function test_normalize_merges_same_methods_and_drops_empty_rows(): void
    {
        $this->assertSame(
            [['method' => 'cash', 'amount' => 3000], ['method' => 'card', 'amount' => 500]],
            PaymentSplit::normalize([
                ['method' => 'cash', 'amount' => 1000],
                ['method' => 'card', 'amount' => 500],
                ['method' => 'cash', 'amount' => 2000],
                ['method' => 'voucher', 'amount' => 0],
            ])
        );
    }

    public function test_plain_ticket_counts_for_its_own_method_and_free_ticket_takes_first_method(): void
    {
        $plain = (object) ['payment_method' => 'szep_card', 'payment_split' => null, 'price_amount' => 1500];
        $this->assertSame(['szep_card' => 1500], PaymentSplit::amounts([$plain]));

        $split = new PaymentSplit([['method' => 'card', 'amount' => 1000]]);
        $this->assertSame(['card', null], $split->take(0));
    }

    public function test_describe_lists_the_parts_of_a_mixed_ticket(): void
    {
        $ticket = (object) [
            'payment_method' => 'mixed',
            'payment_split' => [['method' => 'cash', 'amount' => 1000], ['method' => 'voucher', 'amount' => 1000]],
            'price_amount' => 2000,
        ];

        $this->assertSame('Készpénz 1 000 Ft + Utalvány 1 000 Ft', PaymentSplit::describe($ticket));
    }
}
