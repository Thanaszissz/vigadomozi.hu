<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #1e3a8a; color: white; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .content { background: #f5f5f5; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .section { margin-bottom: 20px; }
        .label { font-weight: bold; color: #1e3a8a; }
        .seats { background: white; padding: 10px; border-radius: 3px; margin-top: 10px; }
        .total { font-size: 1.2em; font-weight: bold; color: #1e3a8a; }
        .footer { text-align: center; color: #666; font-size: 0.9em; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Foglalás Visszaigazolás</h1>
        </div>

        <div class="content">
            <div class="section">
                <p><span class="label">Kedves vendég!</span></p>
                <p>Köszönjük a foglalást! Alább találja a foglalás adatait:</p>
            </div>

            <div class="section">
                <p><span class="label">Film:</span> {{ $reservation->showtime->movie->title }}</p>
                <p><span class="label">Terem:</span> {{ $reservation->showtime->auditorium->name }}</p>
                <p><span class="label">Dátum és idő:</span> {{ $reservation->showtime->starts_at->format('Y.m.d. H:i') }}</p>
            </div>

            <div class="section">
                <p><span class="label">Kiválasztott székek:</span></p>
                <div class="seats">
                    @foreach($reservation->items as $item)
                        <div>{{ $item->row_label }}-{{ $item->seat_number ?? 'X' }} - {{ number_format($item->price_amount) }} Ft</div>
                    @endforeach
                </div>
            </div>

            <div class="section">
                <p class="total">Összesen: {{ number_format($reservation->total_amount) }} Ft</p>
            </div>

            <div class="section">
                <p><span class="label">Foglalási azonosító:</span> {{ $reservation->id }}</p>
                <p><span class="label">Email:</span> {{ $reservation->customer_email }}</p>
            </div>

            <div class="section">
                <p><strong>QR kód a belépéshez:</strong></p>
                <p>{{ $qrCode }}</p>
            </div>

            <div class="footer">
                <p>Ez az email a foglalás visszaigazolása. Kérjük, őrizze meg!</p>
            </div>
        </div>
    </div>
</body>
</html>
