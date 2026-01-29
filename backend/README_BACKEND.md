# Jegyfoglalási Rendszer - Backend

## Telepítés és Konfigurálás

### Előfeltételek
- PHP 8.2+
- Composer
- Laravel 11+

### Lépések

#### 1. Adatbázis setup

```bash
cd backend
php artisan migrate
```

#### 2. .env konfigurálás

Másolja a `.env` fájlt, és állítsa be a szükséges értékeket:

```env
APP_KEY=base64:...
APP_FRONTEND_URL=http://localhost:4200

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=cinema
DB_USERNAME=root
DB_PASSWORD=

# Stripe API keys (test kulcsok)
STRIPE_PUBLIC_KEY=pk_test_...
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...

MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=...
MAIL_PASSWORD=...
```

#### 3. APP Key generálása

```bash
php artisan key:generate
```

#### 4. Szervices indítása

```bash
php artisan serve
```

A backend elérhető lesz a `http://localhost:8000` címen.

### API Végpontok

#### Publikus

- **GET** `/api/movies` - Filmek listája
- **GET** `/api/movies/{id}` - Film részletei
- **GET** `/api/showtimes?date=2026-01-28&movie_id=1` - Előadások szűrve
- **GET** `/api/showtimes/{id}` - Előadás teljes adata (layout, foglaltság)
- **POST** `/api/showtimes/{id}/lock` - Székek lefoglalása
- **GET** `/api/reservations/{id}` - Foglalás adatai
- **POST** `/api/reservations/{id}/pay` - Fizetés indítása (Stripe Checkout)

#### Admin (Auth szükséges: Laravel Sanctum token)

- **GET/POST/PUT/DELETE** `/admin/auditoria` - Termek kezelése
- **GET/POST/PUT/DELETE** `/admin/movies-admin` - Filmek adminisztrációja
- **GET/POST/PUT/DELETE** `/admin/showtimes-admin` - Előadások kezelése
- **GET** `/admin/showtimes/{id}/reservations` - Foglalások lekérdezése
- **GET** `/admin/showtimes/{id}/reservations/export?format=csv` - CSV export

### Webhook kezelés

Stripe webhook: `POST /api/webhooks/stripe`

Ez a végpont kezeli a fizetési eseményeket. Webhook sikerességkor:
1. Reservation státusza PAID-re változik
2. Seat lock-ok törlődnek
3. Megerősítő email kerül küldésre

### Scheduler (Cron job)

Lejárt foglalások kezeléséhez:

```bash
# Futtatás percenként
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

Ez a parancs percenként futtatja az `ExpireReservations` command-ot, amely:
- PENDING foglalásokat EXPIRED-re állít, ha lejártak
- Feloldja az érintett szék-lock-okat

### Nézőtér Layout JSON formátum

```json
{
  "screenLabel": "VÁSZON",
  "rows": [
    {
      "label": "A",
      "basePrice": 2400,
      "seats": [
        {"key": "A-01", "type": "STANDARD"},
        {"key": "A-02", "type": "STANDARD"},
        {"key": "A-03", "type": "AISLE"},
        {"key": "A-04", "type": "STANDARD"},
        {"key": "A-05", "type": "VIP", "price": 3200}
      ]
    },
    {
      "label": "B",
      "basePrice": 2200,
      "seats": [
        {"key": "B-01", "type": "STANDARD"},
        {"key": "B-02", "type": "WHEELCHAIR", "price": 1800}
      ]
    }
  ],
  "legend": {
    "STANDARD": "Normál",
    "VIP": "VIP",
    "WHEELCHAIR": "Mozgássérült",
    "AISLE": "Átjáró"
  }
}
```

**Szék típusok:**
- `STANDARD` - Foglalható normál szék
- `VIP` - Foglalható VIP szék
- `WHEELCHAIR` - Mozgássérültek számára
- `AISLE` - Átjáró (nem foglalható)
- `EMPTY` - Üres blokk (nem foglalható)

### Árszámítás szabály

Sorrend:
1. Showtime `pricing_override_json` (ha van)
2. Layout: szék konkrét ár (`seat.price`)
3. Layout: sor alapár (`row.basePrice`)
4. Globális alapár (2000 HUF)

### Fejlesztés

#### Naplózás

```php
\Log::info('Message', ['context' => 'data']);
\Log::error('Error', ['exception' => $e]);
```

#### Database seeding

```bash
php artisan tinker

# Film létrehozása
Movie::create(['title' => 'Test Film', 'description' => '...']);

# Terem létrehozása
Auditorium::create([
    'name' => 'A terem',
    'layout_json' => [...], // layout JSON
]);

# Előadás létrehozása
Showtime::create([
    'movie_id' => 1,
    'auditorium_id' => 1,
    'starts_at' => now()->addDays(1)->setHour(19)->setMinutes(0),
    'status' => 'active',
]);
```

### Biztonsági megjegyzések

1. **CSRF védelem**: API route-ok alapból excluded (RoutServiceProvider)
2. **Rate limiting**: `/api/showtimes/{id}/lock` végpont korlátozott (10/perc/IP)
3. **Seat lock unique index**: Adatbázis szinten biztosított (unique constraint)
4. **Transaction-based locking**: Seat lock transaction-ben történik
5. **Email queue**: A megerősítő email async queue-ben kerül küldésre

## További fejlesztések

- Filament admin panel integrálása
- Stripe Advanced Fraud Tools
- Email template szépítése
- QR kód generálása (BaconQrCode)
- Beléptetési scanner endpoint
- Audit log (admin műveletek)
- JWT auth opcionálisan
