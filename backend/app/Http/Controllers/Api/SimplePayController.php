<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\ActivityLogger;
use App\Services\OnlinePaymentService;
use App\Services\SimplePayService;
use App\Exceptions\SimplePayException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class SimplePayController extends Controller
{
    public function __construct(private SimplePayService $simplePay, private OnlinePaymentService $payments)
    {
    }

    /**
     * A vásárló visszatérése a SimplePay fizetési oldaláról. Az aláírt eredmény alapján a foglalás itt is jóváírható
     * (az IPN késhet), majd a vásárlót a megfelelő oldalra irányítjuk.
     */
    public function back(Request $request): RedirectResponse
    {
        $site = $this->simplePay->frontendUrl();

        try {
            $info = $this->simplePay->parseBack((string) $request->query('r', ''), (string) $request->query('s', ''));
        } catch (SimplePayException $e) {
            Log::warning('SimplePay visszatérés elutasítva: ' . $e->getMessage());

            return redirect($site . '/');
        }

        $payment = Payment::where('order_ref', $info['o'])->first();
        if (!$payment) {
            return redirect($site . '/');
        }

        $id = $payment->reservation_id;

        if ($info['e'] === 'SUCCESS') {
            $outcome = $this->payments->markPaid($payment, $info['t'], ['return' => $info]);

            return redirect($site . ($outcome === OnlinePaymentService::STATUS_CONFLICT ? "/checkout/{$id}?payment=conflict" : "/success/{$id}"));
        }

        $this->payments->markFailed($payment, $info['e'], $info['t'], ['return' => $info]);

        return redirect($site . "/checkout/{$id}?payment=" . strtolower($info['e']));
    }

    /** IPN: a SimplePay szerver-szerver értesítése (aláírt JSON). A válasz az értesítés aláírt nyugtázása. */
    public function ipn(Request $request): Response
    {
        $body = $request->getContent();
        $content = $this->simplePay->parseIpn($body, $request->header('Signature'));

        if ($content === null) {
            Log::warning('SimplePay IPN elutasítva: érvénytelen aláírás');
            ActivityLogger::log('payment.simplepay_ipn_rejected', 'SimplePay IPN elutasítva: érvénytelen aláírás', null, ['ip' => $request->ip()], user: null);

            return response('UNSUCCESSFUL VALIDATION', 400);
        }

        $payment = Payment::where('order_ref', (string) ($content['orderRef'] ?? ''))->first();

        if ($payment) {
            $status = strtoupper((string) ($content['status'] ?? ''));
            $transactionId = isset($content['transactionId']) ? (string) $content['transactionId'] : null;

            if ($status === 'FINISHED') {
                $this->payments->markPaid($payment, $transactionId, ['ipn' => $content]);
            } elseif (in_array($status, ['CANCELLED', 'TIMEOUT', 'FAILED', 'NOTAUTHORIZED'], true)) {
                $event = $status === 'CANCELLED' ? 'CANCEL' : ($status === 'TIMEOUT' ? 'TIMEOUT' : 'FAIL');
                $this->payments->markFailed($payment, $event, $transactionId, ['ipn' => $content]);
            }
        } else {
            Log::warning('SimplePay IPN ismeretlen rendeléshez: ' . ($content['orderRef'] ?? '?'));
        }

        [$confirmation, $signature] = $this->simplePay->ipnConfirmation($content);

        return response($confirmation, 200, ['Content-Type' => 'application/json', 'Signature' => $signature]);
    }
}
