<?php

namespace App\Services;

use App\Models\Ticket;
use App\Support\PaymentSplit;
use App\Support\TicketCategory;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;

/**
 * Az eladott jegyek archívumának PDF-je (A4 fekvő): fejléc a szűrőkkel, összesítők (fizetési módok, kategóriák) és a jegyek táblázata.
 */
class TicketArchivePdfService
{
    /** Ennél több jegy már nem fér el értelmesen egy PDF-ben (memória, olvashatóság). */
    public const MAX_TICKETS = 3000;

    /**
     * @param array{year:int,filters:array<int,string>,generated_by:?string,generated_at:string} $meta
     * @param array{tickets:int,amount:int,by_method:array<int,array>,by_category:array<int,array>} $summary
     * @param Collection<int, Ticket> $tickets
     */
    public function render(array $meta, array $summary, Collection $tickets): string
    {
        $dir = storage_path('app/dompdf');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('fontCache', $dir);
        $options->set('tempDir', $dir);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('dpi', 96);

        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'landscape');
        $pdf->loadHtml($this->document($meta, $summary, $tickets), 'UTF-8');
        $pdf->render();

        // Oldalszám a lábécben
        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 24, 'Oldal {PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.4, 0.4, 0.4]);

        return (string) $pdf->output();
    }

    private function document(array $meta, array $summary, Collection $tickets): string
    {
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $ft = fn($n) => number_format((int) $n, 0, ',', "\u{00A0}") . "\u{00A0}Ft";

        $methodRows = '';
        foreach ($summary['by_method'] as $m) {
            $methodRows .= '<tr><td>' . $e(PaymentSplit::label($m['payment_method'])) . '</td><td class="r">' . $e($m['tickets']) . ' db</td><td class="r">' . $ft($m['amount']) . '</td></tr>';
        }

        $categoryRows = '';
        foreach ($summary['by_category'] as $c) {
            $label = match ($c['category'] ?: TicketCategory::STANDARD) {
                TicketCategory::STANDARD => 'Normál jegy',
                TicketCategory::STAFF => 'Dolgozó jegy',
                TicketCategory::EXPERIENCE_CARD => 'Élménykártyás jegy',
                TicketCategory::SERVICE => 'Egyéb szolgáltatás (nyugta)',
                default => TicketCategory::label($c['category']),
            };
            $categoryRows .= '<tr><td>' . $e($label) . '</td><td class="r">' . $e($c['tickets']) . ' db</td><td class="r">' . $ft($c['amount']) . '</td></tr>';
        }

        $rows = '';
        foreach ($tickets as $t) {
            $service = $t->category === TicketCategory::SERVICE;
            $seat = $service ? '—' : (($t->section ? $t->section . ' · ' : '') . $t->row_label . ' / ' . ($t->seat_number ?? '—'));
            $cat = $t->category && $t->category !== TicketCategory::STANDARD ? ' <span class="tag">' . $e(TicketCategory::label($t->category)) . '</span>' : '';

            $rows .= '<tr>'
                . '<td class="num">' . $e($t->ticket_number) . '</td>'
                . '<td>' . $e($t->sold_at->copy()->timezone('Europe/Budapest')->format('Y.m.d. H:i')) . '</td>'
                . '<td>' . $e($t->movie_title) . $cat . '</td>'
                . '<td>' . ($service ? '—' : $e($t->starts_at->copy()->timezone('Europe/Budapest')->format('Y.m.d. H:i'))) . '</td>'
                . '<td>' . $e($seat) . '</td>'
                . '<td>' . $e(PaymentSplit::describe($t)) . '</td>'
                . '<td>' . $e($t->sold_by_name ?: '—') . '</td>'
                . '<td class="r">' . $ft($t->price_amount) . '</td>'
                . '</tr>';
        }

        $filters = $meta['filters'] ? implode(' • ', array_map($e, $meta['filters'])) : 'nincs (az év összes jegye)';

        return '<!doctype html><html lang="hu"><head><meta charset="utf-8"><style>'
            . '@page { margin: 14mm 12mm 16mm; }'
            . 'body { font-family: "DejaVu Sans"; font-size: 8px; color: #111; }'
            . 'h1 { font-size: 17px; margin: 0 0 2px; }'
            . '.sub { color: #444; font-size: 9px; margin-bottom: 2px; }'
            . '.meta { color: #666; font-size: 8px; margin-bottom: 10px; }'
            . '.sum { width: 100%; border-collapse: separate; border-spacing: 0; margin-bottom: 10px; }'
            . '.sum > tbody > tr > td { vertical-align: top; padding: 0 8px 0 0; }'
            . '.box { border: 1px solid #999; padding: 6px 8px; }'
            . '.box h3 { font-size: 8px; margin: 0 0 4px; text-transform: uppercase; color: #555; letter-spacing: 0.4px; }'
            . '.box table { width: 100%; border-collapse: collapse; }'
            . '.box td { padding: 2px 0; border-bottom: 1px solid #ddd; font-size: 8.5px; }'
            . '.box tr.tot td { border-top: 1px solid #111; border-bottom: none; font-weight: bold; padding-top: 3px; }'
            . '.kpi { font-size: 19px; font-weight: bold; margin: 4px 0 2px; }'
            . 'table.list { width: 100%; border-collapse: collapse; table-layout: fixed; }'
            . 'table.list thead { display: table-header-group; }'
            . 'table.list th { background: #eee; font-size: 7.5px; text-transform: uppercase; text-align: left; padding: 4px 5px; border-bottom: 1.5px solid #111; }'
            . 'table.list td { padding: 3px 5px; border-bottom: 0.5px solid #ccc; vertical-align: top; }'
            . 'table.list tr { page-break-inside: avoid; }'
            . 'table.list tr:nth-child(even) td { background: #fafafa; }'
            . '.r { text-align: right; white-space: nowrap; }'
            . '.num { font-family: "DejaVu Sans Mono"; font-weight: bold; }'
            . '.tag { background: #e8d5b7; padding: 0 3px; font-size: 7px; }'
            . '</style></head><body>'
            . '<h1>Eladott jegyek – ' . $e($meta['year']) . '</h1>'
            . '<div class="sub">Szűrők: ' . $filters . '</div>'
            . '<div class="meta">Készítette: ' . $e($meta['generated_by'] ?: '—') . ' • ' . $e($meta['generated_at']) . ' • ' . $e(number_format(count($tickets), 0, ',', "\u{00A0}")) . ' jegy a listában</div>'
            . '<table class="sum"><tbody><tr>'
            . '<td style="width:24%"><div class="box"><h3>Bevétel</h3><div class="kpi">' . $ft($summary['amount']) . '</div><div>' . $e(number_format($summary['tickets'], 0, ',', "\u{00A0}")) . ' db jegy / nyugta</div></div></td>'
            . '<td style="width:38%"><div class="box"><h3>Fizetési módok</h3><table>' . $methodRows . '<tr class="tot"><td>Összesen</td><td class="r"></td><td class="r">' . $ft($summary['amount']) . '</td></tr></table></div></td>'
            . '<td style="width:38%; padding-right:0"><div class="box"><h3>Jegykategóriák</h3><table>' . $categoryRows . '<tr class="tot"><td>Összesen</td><td class="r">' . $e($summary['tickets']) . ' db</td><td class="r">' . $ft($summary['amount']) . '</td></tr></table></div></td>'
            . '</tr></tbody></table>'
            . '<table class="list"><colgroup>'
            . '<col style="width:10%"><col style="width:11%"><col style="width:20%"><col style="width:11%"><col style="width:14%"><col style="width:15%"><col style="width:10%"><col style="width:9%">'
            . '</colgroup><thead><tr><th>Jegyszám</th><th>Eladva</th><th>Film / szolgáltatás</th><th>Előadás</th><th>Sor / szék</th><th>Fizetés</th><th>Értékesítő</th><th class="r">Ár</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>'
            . '</body></html>';
    }
}
