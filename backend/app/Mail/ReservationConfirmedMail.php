<?php

namespace App\Mail;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReservationConfirmedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Reservation $reservation
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Foglalás visszaigazolás - Jegyfoglalási rendszer',
        );
    }

    public function content(): Content
    {
        $qrCode = $this->generateQRCode();
        
        return new Content(
            view: 'emails.reservation-confirmed',
            with: [
                'reservation' => $this->reservation->load(['items', 'showtime.movie', 'showtime.auditorium']),
                'qrCode' => $qrCode,
            ],
        );
    }

    private function generateQRCode(): string
    {
        // Generate QR code from reservation ID + HMAC
        $hmac = hash_hmac('sha256', $this->reservation->id, config('app.key'));
        $qrData = $this->reservation->id . ':' . $hmac;
        
        // Simple placeholder - in production, use a QR code library
        // e.g., BaconQrCode or similar
        return base64_encode($qrData);
    }
}
