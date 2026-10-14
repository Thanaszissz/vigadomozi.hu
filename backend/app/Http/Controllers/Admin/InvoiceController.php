<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Reservation;
use App\Services\ActivityLogger;
use App\Services\SzamlazzService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Helyszíni ÁFÁS számla kiállítása (Számlázz.hu) a kifizetett foglalásokhoz. */
class InvoiceController extends Controller
{
    public function __construct(private SzamlazzService $szamlazz)
    {
    }

    /** A pénztár ebből tudja, hogy megjelenjen-e a számla kérése gomb és mekkora az ÁFA kulcs. */
    public function status(): JsonResponse
    {
        return response()->json([
            'enabled' => $this->szamlazz->isEnabled(),
            'vat_rate' => $this->szamlazz->config()['vat_rate'],
        ]);
    }

    public function show(Reservation $reservation): JsonResponse
    {
        return response()->json(Invoice::where('reservation_id', $reservation->id)->first());
    }

    public function issue(Reservation $reservation, Request $request): JsonResponse
    {
        $buyer = $request->validate([
            'name' => ['required', 'string', 'max:200', 'regex:/^[^<>\x00-\x1F\x7F]+$/u'],
            'zip' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9\- ]{3,10}$/'],
            'city' => ['required', 'string', 'max:100', 'regex:/^[^<>\x00-\x1F\x7F]+$/u'],
            'address' => ['required', 'string', 'max:200', 'regex:/^[^<>\x00-\x1F\x7F]+$/u'],
            'tax_number' => ['nullable', 'string', 'regex:/^\d{8}-\d-\d{2}$/'],
            'email' => ['nullable', 'email', 'max:255'],
        ], [
            'tax_number.regex' => 'Az adószám formátuma: 12345678-1-42.',
            'zip.regex' => 'Az irányítószám érvénytelen.',
            'name.regex' => 'A név érvénytelen karaktert tartalmaz.',
        ]);

        if ($reservation->payment_ref !== 'pos') {
            return response()->json(['message' => 'Számla csak a helyszínen kifizetett foglaláshoz állítható ki.'], 422);
        }

        $buyer['tax_number'] = filled($buyer['tax_number'] ?? null) ? $buyer['tax_number'] : null;
        $buyer['email'] = filled($buyer['email'] ?? null) ? trim($buyer['email']) : null;

        try {
            $invoice = $this->szamlazz->issue($reservation, $buyer, $request->user());
        } catch (\RuntimeException $e) {
            ActivityLogger::log(
                'invoice.failed',
                sprintf('Számla kiállítása sikertelen: foglalás #%d (%s)', $reservation->id, $e->getMessage()),
                $reservation,
                ['error' => $e->getMessage()],
                subjectLabel: "Foglalás #{$reservation->id}"
            );

            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($invoice->wasRecentlyCreated) {
            ActivityLogger::log(
                'invoice.issued',
                sprintf('Számla kiállítva: %s (foglalás #%d, %s, %s Ft)', $invoice->invoice_number, $reservation->id, $invoice->buyer_name, number_format($invoice->gross_amount, 0, ',', ' ')),
                $reservation,
                ['invoice_number' => $invoice->invoice_number, 'gross' => $invoice->gross_amount, 'vat_rate' => $invoice->vat_rate],
                subjectLabel: $invoice->invoice_number
            );
        }

        return response()->json($invoice, 201);
    }

    public function pdf(Invoice $invoice)
    {
        abort_unless($invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path), 404, 'A számla PDF nem érhető el.');

        return Storage::disk('local')->download($invoice->pdf_path, preg_replace('/[^A-Za-z0-9_\-]/', '_', $invoice->invoice_number) . '.pdf', ['Content-Type' => 'application/pdf']);
    }
}
