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
        $e = fn(string $v) => e($v);

        $category = (string) $get('category');
        $service = $category === 'service';
        $tag = $category === 'staff' ? 'Dolgozó jegy' : ($category === 'experience_card' ? 'Élménykártya' : '');

        // A szolgáltatás-nyugtának nincs előadása: az eladás időpontja szerepel rajta
        $when = $this->formatWhen($service ? ($get('sold_at') ?? $get('starts_at')) : $get('starts_at'));
        $whenLine = $tag !== '' ? $when . ' • ' . $tag : $when;
        $title = (string) $get('movie_title');
        $seat = (string) ($get('seat_number') ?? '—');
        // szintes székeknél a jegyre a terem helyett a szint neve kerül
        $section = trim((string) $get('section'));
        $hallLabel = $section !== '' ? 'Szint' : 'Terem';
        $hall = $section !== '' ? $section : (string) ($get('auditorium_name') ?: '—');
        $row = (string) $get('row_label');
        $money = number_format((int) $get('price_amount'), 0, ',', ' ') . ' Ft';
        $number = (string) $get('ticket_number');

        $len = mb_strlen($title);
        $titleSize = $len <= 20 ? 36 : ($len <= 30 ? 30 : ($len <= 44 ? 25 : 21));

        $label = fn(string $text, float $size, float $spacing, string $extra = '') => '<span class="lbl" style="font-size:' . $this->f($size, 0.85) . ';letter-spacing:' . $this->px($spacing) . ';' . $extra . '">' . $e($text) . '</span>';

        $html = '<div class="page' . ($breakAfter ? ' brk' : '') . '">'
            . '<img class="bg" src="' . resource_path('ticket-base.jpg') . '" alt="">'
            // cím és időpont a jegy fő részén
            . '<div class="abs" style="left:' . $this->px(34) . ';top:' . $this->px(136) . ';width:' . $this->px(566) . ';height:' . $this->px(62) . ';overflow:hidden;display:table;">'
            . '<div class="cell" style="font-weight:bold;line-height:1.1;font-size:' . $this->f($titleSize) . ';">' . $e($title) . '</div></div>'
            . '<div class="abs" style="left:' . $this->px(34) . ';top:' . $this->px(200) . ';width:' . $this->px(566) . ';font-size:' . $this->f(23) . ';">' . $e($whenLine) . '</div>';

        if ($service) {
            $html .= '<div class="abs" style="left:' . $this->px(38) . ';top:' . $this->px(232) . ';width:' . $this->px(118) . ';">'
                . '<div>' . $label('Nyugtaszám', 11, 1.5) . '</div>'
                . '<div style="font-size:' . $this->f(19) . ';font-weight:bold;line-height:1.15;">' . $e($number) . '</div></div>'
                . '<div class="abs" style="left:' . $this->px(366) . ';top:' . $this->px(232) . ';width:' . $this->px(118) . ';">'
                . '<div>' . $label('Kategória', 11, 1.5) . '</div>'
                . '<div style="font-size:' . $this->f(19) . ';font-weight:bold;line-height:1.15;">Szolgáltatás</div></div>'
                . '<div class="abs" style="left:' . $this->px(484) . ';top:' . $this->px(232) . ';width:' . $this->px(118) . ';">'
                . '<div>' . $label('Ár', 11, 1.5) . '</div>'
                . '<div style="font-size:' . $this->f(24) . ';font-weight:bold;line-height:1.15;">' . $e($money) . '</div></div>';
        } else {
            // szint / sor / szék a cím alatt balra, ár és nyugtaszám jobbra
            $line = fn(string $name, string $value, bool $first = false) => '<div style="text-align:left;line-height:1.2;' . ($first ? '' : 'margin-top:' . $this->px(2) . ';') . '">'
                . $label($name, 10, 1, 'margin-right:' . $this->px(5) . ';') . '<b style="font-size:' . $this->f(17) . ';">' . $e($value) . '</b></div>';

            $html .= '<div class="abs" style="left:' . $this->px(40) . ';top:' . $this->px(192) . ';width:' . $this->px(130) . ';text-align:left;">'
                . $line($hallLabel, $hall, true) . $line('Sor', $row) . $line('Szék', $seat) . '</div>'
                . '<div class="abs" style="left:' . $this->px(466) . ';top:' . $this->px(192) . ';width:' . $this->px(134) . ';">'
                . '<div>' . $label('Ár', 11, 1.5) . '</div>'
                . '<div style="font-size:' . $this->f(24) . ';font-weight:bold;line-height:1.15;">' . $e($money) . '</div>'
                . '<div style="margin-top:' . $this->px(6) . ';">' . $label('Nyugtaszám', 11, 1.5) . '</div>'
                . '<div style="font-family:\'DejaVu Sans Mono\';font-weight:bold;font-size:' . $this->f(14, 0.85) . ';letter-spacing:' . $this->px(0.3) . ';line-height:1.2;">' . $e($number) . '</div>'
                . '</div>';
        }

        // a letéphető szelvény
        $stubSeat = $service
            ? ''
            : '<div style="font-size:' . $this->f(12) . ';margin-top:' . $this->px(3) . ';line-height:1.2;">' . $e($hall) . ' • <b>' . $e($row) . '. sor ' . $e($seat) . '. szék</b></div>';

        $html .= '<div class="abs" style="left:' . $this->px(626) . ';top:' . $this->px(190) . ';width:' . $this->px(204) . ';">'
            . '<div style="font-weight:bold;font-size:' . $this->f(14) . ';line-height:1.15;max-height:' . $this->px(33) . ';overflow:hidden;">' . $e($title) . '</div>'
            . '<div style="font-size:' . $this->f(12) . ';margin-top:' . $this->px(3) . ';line-height:1.2;">' . $e($when) . '</div>'
            . $stubSeat
            . ($tag !== '' ? '<div style="font-size:' . $this->f(12) . ';margin-top:' . $this->px(3) . ';line-height:1.2;"><b>' . $e($tag) . '</b></div>' : '')
            . '<div style="font-family:\'DejaVu Sans Mono\';font-weight:bold;font-size:' . $this->f(15, 0.85) . ';letter-spacing:' . $this->px(1) . ';margin-top:' . $this->px(6) . ';">' . $e($number) . '</div>'
            . '<div style="font-weight:bold;font-size:' . $this->f(15) . ';margin-top:' . $this->px(2) . ';">' . $e($money) . '</div>'
            . '</div></div>';

        return $html;
    }

    private function formatWhen(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return $value ? $value->copy()->timezone('Europe/Budapest')->format('Y.m.d. H:i') : '';
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
