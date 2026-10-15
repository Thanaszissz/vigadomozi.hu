# 🎬 Jegyfoglalási Rendszer - Teljes Dokumentáció

## Projekt Áttekintés

A **Vigado Jegy Jegyfoglalási Rendszer** egy teljes szövetséges web alkalmazás filmjegyek online foglalásához.

**Technológia Stack:**
- **Backend**: Laravel 11 + MySQL
- **Frontend**: Angular 17
- **Fizetés**: Stripe Checkout
- **Email**: Laravel Mail Queue

## Architektúra

### Backend (Laravel)

```
backend/
├── app/
│   ├── Models/           # Eloquent ORM modellek
│   ├── Http/Controllers/ # API Controller-ek
│   ├── Services/         # Business logic (PricingService, SeatMapService)
│   └── Mail/             # Email Mailable classes
├── database/
│   ├── migrations/       # Adatbázis schema
│   └── seeders/          # Teszt adatok
├── routes/
│   ├── api.php           # Public API végpontok
│   └── admin.php         # Admin API (Sanctum auth)
└── config/
    └── services.php      # Stripe config
```

**Kulcs modellek:**
- `Movie` - Filmek
- `Auditorium` - Termek (nézőtér layout-tal)
- `Showtime` - Előadások (film + terem + dátum/idő)
- `Reservation` - Foglalások
- `ReservationItem` - Foglalásban szereplő székek
- `SeatLock` - Szék occupancy tracking

### Frontend (Angular)

```
frontend/
├── src/app/
│   ├── core/api/         # API Service
│   ├── features/
│   │   ├── home/         # Kezdőoldal
│   │   ├── movies/       # Film lista & részletek
│   │   └── checkout/     # Szék választás, fizetés, sikeres vásárlás
│   ├── routes.ts         # Routing konfigurálás
│   └── app.component.ts  # Root komponens
└── dist/                 # Build output (production)
```

**Standalone Components**: Minden komponens standalone, modulok nélkül.

## Adatbázis Séma

### auditoria (Termek)
```sql
CREATE TABLE auditoria (
  id bigint PRIMARY KEY,
  name varchar(255),
  layout_json json,    -- Nézőtér layout (sorok, székek, árak)
  style_json json,     -- Szín sémák, stílusok
  timestamps
);
```

**layout_json Szerkezet:**
```json
{
  "screenLabel": "VÁSZON",
  "rows": [
    {
      "label": "A",
      "basePrice": 2400,
      "seats": [
        {"key": "A-01", "type": "STANDARD"},
        {"key": "A-05", "type": "VIP", "price": 3200}
      ]
    }
  ],
  "legend": {
    "STANDARD": "Normál",
    "VIP": "VIP"
  }
}
```

### movies
```sql
CREATE TABLE movies (
  id bigint PRIMARY KEY,
  title varchar(255),
  description text,
  poster_path varchar(255),
  trailer_youtube_url varchar(255),
  duration_min int,
  timestamps
);
```

### showtimes
```sql
CREATE TABLE showtimes (
  id bigint PRIMARY KEY,
  movie_id bigint,          -- FK movies
  auditorium_id bigint,     -- FK auditoria
  starts_at datetime,
  sales_open_at datetime,
  sales_close_at datetime,
  pricing_override_json json, -- Override árak
  status enum(active, cancelled, completed),
  timestamps,
  
  FOREIGN KEY (movie_id) REFERENCES movies(id),
  FOREIGN KEY (auditorium_id) REFERENCES auditoria(id)
);
```

### reservations
```sql
CREATE TABLE reservations (
  id bigint PRIMARY KEY,
  showtime_id bigint,
  user_id bigint,            -- nullable (guest checkout)
  customer_email varchar(255),
  status enum(PENDING, PAID, CANCELLED, EXPIRED),
  total_amount int,          -- HUF
  currency varchar(3),
  payment_provider varchar(50), -- 'stripe'
  payment_ref varchar(255),  -- Stripe session_id / intent_id
  expires_at datetime,       -- PENDING foglalás lejárat
  timestamps
);
```

### reservation_items
```sql
CREATE TABLE reservation_items (
  id bigint PRIMARY KEY,
  reservation_id bigint,
  seat_key varchar(32),      -- "A-01"
  row_label varchar(8),
  seat_number int,
  price_amount int,          -- HUF
  timestamps,
  
  UNIQUE(reservation_id, seat_key)
);
```

### seat_locks
```sql
CREATE TABLE seat_locks (
  id bigint PRIMARY KEY,
  showtime_id bigint,
  seat_key varchar(32),
  reservation_id bigint,
  expires_at datetime,
  timestamps,
  
  UNIQUE(showtime_id, seat_key),
  INDEX(showtime_id, expires_at)
);
```

## API Végpontok

### Publikus (Auth nélkül)

| Metódus | Végpont | Leírás |
|---------|---------|--------|
| GET | `/api/movies` | Filmek listája |
| GET | `/api/movies/{id}` | Film részletei |
| GET | `/api/showtimes` | Előadások (szűrható: date, movie_id) |
| GET | `/api/showtimes/{id}` | Előadás + nézőtér layout + foglaltság |
| POST | `/api/showtimes/{id}/lock` | Szék lefoglalása (10 perc lock) |
| GET | `/api/reservations/{id}` | Foglalás adatai |
| POST | `/api/reservations/{id}/pay` | Stripe Checkout URL |
| POST | `/api/webhooks/stripe` | Stripe webhook (CSRF exempt) |

### Admin (Laravel Sanctum token szükséges)

| Metódus | Végpont | Leírás |
|---------|---------|--------|
| GET/POST/PUT/DELETE | `/admin/auditoria` | Termek CRUD |
| GET/POST/PUT/DELETE | `/admin/movies-admin` | Filmek CRUD |
| GET/POST/PUT/DELETE | `/admin/showtimes-admin` | Előadások CRUD |
| GET | `/admin/showtimes/{id}/reservations` | Foglalások lekérdezése |
| GET | `/admin/showtimes/{id}/reservations/export?format=csv` | CSV export |

## Foglalási Flow

```
1. User kiválasztja a filmet és előadást
2. Frontend GET /api/showtimes/{id}
   └─ Backend: Nézőtér layout + foglalt/locked szék lista
3. User kiválasztja az üléseket
4. Frontend POST /api/showtimes/{id}/lock
   ├─ Backend DELETE expired seat_locks
   ├─ Backend ellenőrzi: nincs-e PAID foglalás
   ├─ Backend ellenőrzi: nincs-e aktív lock
   ├─ Backend INSERT Reservation (PENDING)
   ├─ Backend INSERT ReservationItems (egyenként)
   ├─ Backend INSERT SeatLocks (10 perc expiry)
   └─ Response: Reservation data
5. Frontend POST /api/reservations/{id}/pay
   ├─ Backend: Stripe Checkout session létrehozás
   └─ Response: checkout URL
6. Frontend: window.location.href = checkout_url
   └─ Stripe Checkout oldal
7. User fizetés
8. Stripe webhook POST /api/webhooks/stripe
   ├─ Backend: Payload verifálás
   ├─ Backend: Reservation.status = 'PAID'
   ├─ Backend: DELETE SeatLocks
   ├─ Backend: Mail::send(ReservationConfirmedMail)
   └─ Frontend: user redirect success page
9. Email küldés (async queue)
   └─ HTML: Foglalási adatok + QR kód
```

## Árszámítás Logika

**PricingService::priceForSeat($showtime, $seatKey)**

Sorrend:
1. Showtime `pricing_override_json` - ha van override
2. Auditorium `layout_json`: Szék konkrét ár (`seat.price`)
3. Auditorium `layout_json`: Sor alapár (`row.basePrice`)
4. Globális alapár (2000 HUF)

**Példa:**
```php
$price = $pricingService->priceForSeat($showtime, 'A-05');
// 1. Showtime override nincs
// 2. layout: A sor, A-05 szék → price: 3200 → return 3200
```

## Stripe Integráció

### Konfigurálás (.env)
```env
STRIPE_PUBLIC_KEY=pk_test_...
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

### Checkout Flow
```php
$session = $stripe->checkout->sessions->create([
  'line_items' => [
    ['price_data' => [...], 'quantity' => 1],
    // Minden szék egy line item
  ],
  'mode' => 'payment',
  'success_url' => '...',
  'cancel_url' => '...',
  'metadata' => ['reservation_id' => 123],
]);
```

### Webhook Kezelés
```php
// POST /api/webhooks/stripe
$event = Stripe\Webhook::constructEvent($payload, $sig, $secret);

if ($event->type === 'checkout.session.completed') {
  $reservation = Reservation::find($event->data->object->metadata->reservation_id);
  $reservation->update(['status' => 'PAID', 'payment_ref' => $event->data->object->id]);
  Mail::to($reservation->customer_email)->send(new ReservationConfirmedMail($reservation));
}
```

## Email Rendszer

### ReservationConfirmedMail Mailable
```php
class ReservationConfirmedMail extends Mailable implements ShouldQueue {
  public function content() {
    return new Content(
      view: 'emails.reservation-confirmed',
      with: ['reservation' => ..., 'qrCode' => ...]
    );
  }
}
```

**Template:** `resources/views/emails/reservation-confirmed.blade.php`
- Foglalási ID
- Film, dátum, terem
- Kiválasztott székek + árak
- QR kód (foglalás ID + HMAC)

### Küldés Queue-ben
```php
Mail::to($email)->send(new ReservationConfirmedMail($reservation));
// Laravel automatikusan queue-be teszi (ShouldQueue interface)
```

**Queue Futtatása:**
```bash
php artisan queue:work database
```

## Scheduler (Cron Job)

**ExpireReservations Command:** Lejárt PENDING foglalásokat EXPIRED-re állít.

```bash
# Percenként fut
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

**Logic:**
```php
Reservation::where('status', 'PENDING')
  ->where('expires_at', '<', now())
  ->update(['status' => 'EXPIRED']);

SeatLock::where('expires_at', '<', now())->delete();
```

## Biztonsági Jellemzők

### Seat Lock Unique Index
```sql
UNIQUE KEY `unique_seat_lock` (showtime_id, seat_key)
```
Garantálja: Egy szék csak egyszer lehet locked egy előadáson.

### Transaction-based Locking
```php
DB::transaction(function () {
  // Atomikus: select for update, check, insert
  // Adatbázis szinten korlátozva az ütközés lehetősége
});
```

### Rate Limiting
```php
Route::post('/showtimes/{id}/lock', [...])
  ->middleware('throttle:seat-lock'); // 10/perc/IP
```

### CSRF Protection Kihagyása Webhookra
```php
Route::post('/webhooks/stripe', [...])
  ->withoutMiddleware('api');
```

## Telepítési Lépések

### 1. Backend Setup
```bash
cd backend
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve  # http://localhost:8000
```

### 2. Frontend Setup
```bash
cd frontend
npm install
npm start  # http://localhost:4200
```

### 3. Stripe Teszt Kulcsok
- https://dashboard.stripe.com/test/apikeys
- Teszt kártyák: https://stripe.com/docs/testing

### 4. Webhook Setup (Lokális fejlesztéshez)
```bash
# Stripe CLI telepítése
brew install stripe/stripe-cli/stripe

# Login
stripe login

# Listen to webhook events
stripe listen --forward-to localhost:8000/api/webhooks/stripe

# Copy signing secret → .env: STRIPE_WEBHOOK_SECRET=whsec_...
```

## Fejlesztési Tippek

### Backend Debugging
```php
\Log::info('Debug', ['key' => 'value']);
\Log::error('Error', ['exception' => $e]);
```

### Database Testing
```bash
php artisan tinker

# Kiváló adatok
>>> Movie::count()
>>> Showtime::first()
>>> Reservation::where('status', 'PAID')->count()
```

### Frontend Testing
```bash
# Mock API (json-server)
npm install -g json-server
json-server --watch db.json --port 3000

# Update API_URL: 'http://localhost:3000'
```

### Browser DevTools
- Network tab: API hívások monitorozása
- Console: JavaScript hibák
- Storage: localStorage (foglalási ID-k)

## Teljesítmény Optimalizálás

### Backend
- Database indexek: `movie_id`, `auditorium_id`, `status`, `expires_at`
- Query eager loading: `->with(['movie', 'auditorium'])`
- Cache: Redis (opscionális): `Cache::remember('showtimes', 60, fn() => ...)`

### Frontend
- Lazy loading: Route-onként betöltve
- Change detection strategy: OnPush
- Tree-shaking: Prod build

## Prod Deployment

### Azure App Service
```bash
# Deploy backend
az deployment group create \
  --template-file arm-template.json \
  --resource-group mygroup

# Deploy frontend
az staticwebapp create \
  --name cinema-booking \
  --source https://github.com/user/repo
```

### Docker Deployment
```bash
# Backend Dockerfile
FROM php:8.2-fpm
RUN composer install
RUN php artisan migrate

# Frontend Dockerfile
FROM node:18 as builder
RUN npm run build

FROM nginx
COPY --from=builder /app/dist /usr/share/nginx/html
```

### Environment Variables
**Backend (.env):**
```env
DB_HOST=prod-db.example.com
STRIPE_SECRET_KEY=sk_live_...
MAIL_HOST=smtp.sendgrid.net
```

**Frontend (.env.prod):**
```env
API_URL=https://api.cinema.example.com
```

## Monitorozás

- **Application Insights** (Azure): Error tracking
- **Sentry** (opcionális): Error reporting
- **LogRocket** (opcionális): Frontend session replay
- **Stripe Dashboard**: Payment monitoring

## FAQ

**Q: Hogyan működik a 10 perces lock?**
A: `seat_locks` tábla `expires_at` oszlopa beállított 10 percre. Cron job percenként futva törli az lejárt lock-okat.

**Q: Mi történik, ha a fizetés sikertelen?**
A: Webhook nem érkezik. Reservation PENDING marad. Cron job 10 perc után EXPIRED-re állítja. SeatLock törlődik.

**Q: Lehet-e szék duplikálva foglalni?**
A: Nem. Unique constraint + transaction biztosítja.

**Q: Milyen fizetési módok támogatottak?**
A: Stripe Checkout: bankkártya, Apple Pay, Google Pay (konfigurációtól függő).

**Q: Hoztam létre admin felhasználót?**
A: Jelenleg Filament panelbe integrálható (opcionális). API-n Sanctum tokennel.

## Jövőbeli Fejlesztések

1. **Filament Admin Panel** - Drag & drop layout editor
2. **Seat Categories** - Sorok/székek kategorizálása
3. **Bulk Import** - CSV importálás filmekhez
4. **Analytics** - Prodejség analitika
5. **Refund System** - Visszatérítések kezelése
6. **Mobile App** - React Native vagy Flutter
7. **QR Scanner** - Beléptetési scanner
8. **Audit Log** - Admin műveletek naplózása
9. **Multi-language** - i18n support
10. **Payment Methods** - PayPal, Apple Pay, stb.

## Support

Problémákra vonatkozó dokumentáció:
- Backend issues: `backend/README_BACKEND.md`
- Frontend issues: `frontend/README_FRONTEND.md`
- API debugging: Swagger/OpenAPI spec (opcionális)

---

**Verzió:** 1.0.0  
**Frissítés:** 2026-01-28  
**Szerző:** Vigado Jegy Dev Team
