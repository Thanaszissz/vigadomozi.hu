<?php

namespace App\Services;

use App\Models\EmailTemplate;
use App\Models\Reservation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Crypto\DkimSigner;
use Symfony\Component\Mime\Email;

class ReservationMailer
{
    public function __construct(private EmailTemplateService $templates)
    {
    }

    /** Publikus foglalás visszaigazolása. A küldés a válasz elküldése után történik, így nem lassítja a foglalást. */
    public function reservationReceived(Reservation $reservation): void
    {
        $this->afterResponse(fn() => $this->deliver('reservation_received', $reservation));
    }

    /** A sikeresen kifizetett jegyek kiküldése. */
    public function ticketsPaid(Reservation $reservation, Collection $tickets): void
    {
        if ($tickets->isEmpty()) {
            return;
        }

        $this->afterResponse(fn() => $this->deliver('tickets_paid', $reservation, $tickets));
    }

    /**
     * Teszt küldés az admin felületről (azonnal, hogy a hiba visszajelezhető legyen).
     *
     * @throws \Throwable
     */
    public function sendTest(string $subject, string $body, string $to): void
    {
        [$subjectText, $html, $text] = $this->templates->compose($subject, $body, $this->templates->sampleVariables());
        $this->send($to, '[TESZT] ' . $subjectText, $html, $text);
    }

    private function deliver(string $key, Reservation $reservation, ?Collection $tickets = null): void
    {
        $to = (string) $reservation->customer_email;

        try {
            $template = EmailTemplate::forKey($key);
            if (!$template->is_active || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            [$subject, $html, $text] = $this->templates->compose(
                $template->subject,
                $template->body,
                $this->templates->variablesFor($reservation, $tickets)
            );

            $this->send($to, $subject, $html, $text, $this->ticketAttachments($key, $reservation, $tickets));

            ActivityLogger::log(
                'email.sent',
                "E-mail elküldve: {$template->name} → {$to}",
                $reservation,
                ['template' => $key, 'to' => $to, 'subject' => $subject],
                user: null,
                subjectLabel: 'Foglalás #' . $reservation->id
            );
        } catch (\Throwable $e) {
            Log::error('E-mail küldési hiba: ' . $e->getMessage());

            ActivityLogger::log(
                'email.failed',
                "E-mail küldése sikertelen: {$key} → {$to}",
                $reservation,
                ['template' => $key, 'to' => $to, 'error' => mb_substr($e->getMessage(), 0, 300)],
                user: null,
                subjectLabel: 'Foglalás #' . $reservation->id
            );
        }
    }

    /** @return array<int, array{name:string, data:string, mime:string}> */
    private function ticketAttachments(string $key, Reservation $reservation, ?Collection $tickets): array
    {
        if ($key !== 'tickets_paid' || !$tickets || $tickets->isEmpty()) {
            return [];
        }

        try {
            return [[
                'name' => 'vigado-mozi-jegyek-' . $reservation->id . '.pdf',
                'data' => app(TicketPdfService::class)->render($tickets),
                'mime' => 'application/pdf',
            ]];
        } catch (\Throwable $e) {
            // A jegyszámok a levél szövegében is benne vannak, ezért a levél PDF nélkül is kimegy
            Log::error('A jegyek PDF-je nem készült el: ' . $e->getMessage());

            return [];
        }
    }

    /** @param array<int, array{name:string, data:string, mime:string}> $attachments */
    private function send(string $to, string $subject, string $html, string $text, array $attachments = []): void
    {
        $signer = $this->dkimSigner();
        if ($signer) {
            try {
                $email = (new Email())
                    ->from(new Address((string) config('mail.from.address'), (string) config('mail.from.name')))
                    ->to($to)
                    ->subject($subject)
                    ->html($html)
                    ->text($text);
                foreach ($attachments as $a) {
                    $email->attach($a['data'], $a['name'], $a['mime']);
                }

                Mail::mailer()->getSymfonyTransport()->send($signer->sign($email));

                return;
            } catch (\Throwable $e) {
                // Hibás kulcs vagy aláírási hiba esetén aláíratlanul küldünk, hogy a levél így is kimenjen
                Log::warning('DKIM-aláírás sikertelen, a levél aláírás nélkül megy ki: ' . $e->getMessage());
            }
        }

        Mail::html($html, function ($message) use ($to, $subject, $text, $attachments) {
            $message->to($to)->subject($subject);
            $message->text($text);
            foreach ($attachments as $a) {
                $message->attachData($a['data'], $a['name'], ['mime' => $a['mime']]);
            }
        });
    }

    private function dkimSigner(): ?DkimSigner
    {
        $cfg = config('mail.dkim');
        $domain = (string) ($cfg['domain'] ?? '');
        $key = (string) ($cfg['private_key'] ?? '');
        if ($domain === '' || $key === '') {
            return null;
        }

        $pem = str_contains($key, 'BEGIN') ? $key : (string) base64_decode($key, true);
        if (!str_contains($pem, 'PRIVATE KEY')) {
            Log::warning('A MAIL_DKIM_PRIVATE_KEY érvénytelen (base64-kódolt PEM kell).');

            return null;
        }

        return new DkimSigner($pem, $domain, (string) ($cfg['selector'] ?: 'vigado'));
    }

    private function afterResponse(\Closure $callback): void
    {
        app()->terminating($callback);
    }
}
