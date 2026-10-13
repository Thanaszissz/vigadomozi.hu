<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;

/**
 * A jegyek nyomtatható PDF-je: jegyenként egy 210 x 100 mm-es fekvő oldal, a jegysablonnal (resources/ticket-base.jpg).
 * Az elrendezés a frontend jegysablonjával (ticket-template.ts) egyezik: a koordináták annak 840 x 400 px-es vásznából származnak.
 */
class TicketPdfService
{
    // A 840 px-es vászon átváltása 210 mm-re (793,7 px)
    private const K = 793.7 / 840;

    // A Georgia helyett használt DejaVu Serif szélesebb, ezért a betűméreteket kicsinyítjük
    private const SERIF = 0.8;

    /** @param Collection<int, mixed> $tickets Ticket modellek vagy tömbök */
    public function render(Collection $tickets): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('chroot', [resource_path()]);
        $options->set('fontCache', storage_path('app/dompdf'));
        $options->set('tempDir', storage_path('app/dompdf'));
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('dpi', 96);

        if (!is_dir(storage_path('app/dompdf'))) {
            @mkdir(storage_path('app/dompdf'), 0775, true);
        }

        $pdf = new Dompdf($options);
        $pdf->setPaper([0, 0, 210 / 25.4 * 72, 100 / 25.4 * 72]);
        $pdf->loadHtml($this->document($tickets), 'UTF-8');
        $pdf->render();

        return (string) $pdf->output();
    }

    private function document(Collection $tickets): string
    {
        $pages = [];
        $last = $tickets->count() - 1;
        foreach ($tickets->values() as $i => $t) {
            $pages[] = $this->page($t, $i < $last);
        }

        return '<!doctype html><html lang="hu"><head><meta charset="utf-8"><style>'
            . '@page { margin: 0; }'
            . 'html, body { margin: 0; padding: 0; }'
            . '.page { position: relative; width: 210mm; height: 99mm; overflow: hidden; background: #fff; }'
            . '.brk { page-break-after: always; }'
            . '.bg { position: absolute; left: 0; top: 0; width: 210mm; height: 99mm; }'
            . '.abs { position: absolute; text-align: center; color: #111; font-family: "DejaVu Serif"; }'
            . '.cell { display: table-cell; vertical-align: middle; text-align: center; }'
            . '.lbl { font-family: "DejaVu Sans"; text-transform: uppercase; color: #555; }'
            . '</style></head><body>' . implode('', $pages) . '</body></html>';
    }

    private function page(mixed $t, bool $breakAfter): string
    {
        $get = fn(string $k) => data_get($t, $k);

        $when = $get('starts_at');
        $when = is_string($when) ? $when : ($when ? $when->copy()->timezone('Europe/Budapest')->format('Y.m.d. H:i') : '');
        $title = (string) $get('movie_title');
        $seat = (string) ($get('seat_number') ?? '—');
        $hall = (string) ($get('auditorium_name') ?: '—');
        $row = (string) $get('row_label');
        $money = number_format((int) $get('price_amount'), 0, ',', ' ') . ' Ft';
        $number = (string) $get('ticket_number');

        $len = mb_strlen($title);
        $titleSize = $len <= 20 ? 36 : ($len <= 30 ? 30 : ($len <= 44 ? 25 : 21));
        $e = fn(string $v) => e($v);

        $fact = fn(int $x, string $label, string $value) => '<div class="abs" style="left:' . $this->px($x) . ';top:' . $this->px(232) . ';width:' . $this->px(118) . ';">'
            . '<div class="lbl" style="font-size:' . $this->f(11, 0.85) . ';letter-spacing:' . $this->px(1.5) . ';">' . $e($label) . '</div>'
            . '<div style="font-size:' . $this->f(24) . ';font-weight:bold;line-height:1.2;">' . $e($value) . '</div></div>';

        $html = '<div class="page' . ($breakAfter ? ' brk' : '') . '">'
            . '<img class="bg" src="' . resource_path('ticket-base.jpg') . '" alt="">'
            // cím és időpont a jegy fő részén
            . '<div class="abs" style="left:' . $this->px(34) . ';top:' . $this->px(136) . ';width:' . $this->px(566) . ';height:' . $this->px(62) . ';overflow:hidden;display:table;">'
            . '<div class="cell" style="font-weight:bold;line-height:1.1;font-size:' . $this->f($titleSize) . ';">' . $e($title) . '</div></div>'
            . '<div class="abs" style="left:' . $this->px(34) . ';top:' . $this->px(202) . ';width:' . $this->px(566) . ';font-size:' . $this->f(23) . ';">' . $e((string) $when) . '</div>'
            . $fact(38, 'Terem', $hall) . $fact(156, 'Sor', $row) . $fact(366, 'Szék', $seat) . $fact(484, 'Ár', $money)
            // a letéphető szelvény
            . '<div class="abs" style="left:' . $this->px(626) . ';top:' . $this->px(190) . ';width:' . $this->px(204) . ';">'
            . '<div style="font-weight:bold;font-size:' . $this->f(14) . ';line-height:1.15;max-height:' . $this->px(33) . ';overflow:hidden;">' . $e($title) . '</div>'
            . '<div style="font-size:' . $this->f(12) . ';margin-top:' . $this->px(3) . ';">' . $e((string) $when) . '</div>'
            . '<div style="font-size:' . $this->f(12) . ';margin-top:' . $this->px(2) . ';">' . $e($hall) . ' • <b>' . $e($row) . '. sor ' . $e($seat) . '. szék</b></div>'
            . '<div style="font-family:\'DejaVu Sans Mono\';font-weight:bold;font-size:' . $this->f(15, 0.85) . ';margin-top:' . $this->px(6) . ';">' . $e($number) . '</div>'
            . '<div style="font-weight:bold;font-size:' . $this->f(15) . ';margin-top:' . $this->px(2) . ';">' . $e($money) . '</div>'
            . '</div></div>';

        return $html;
    }

    private function px(float|int $v): string
    {
        return round($v * self::K, 2) . 'px';
    }

    private function f(float|int $v, float $factor = self::SERIF): string
    {
        return round($v * self::K * $factor, 2) . 'px';
    }
}
