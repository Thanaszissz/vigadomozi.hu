# VIGADÓ JEGY - Online Jegyfoglalási Rendszer

A VIGADÓ JEGY egy teljes körű, modern **online mozi jegyfoglalási rendszer** a kiváló Vigadó Sándor Budai Mozi számára. Az alkalmazás a filmvetítések egyszerű és intuitív online foglalását teszi lehetővé.

## 📋 Projektöttletek

- **Backend**: Laravel 11 + PHP 8.2+ + MySQL/SQLite
- **Frontend**: Angular 17 + TypeScript 5.2 + RxJS
- **Fizetés**: Stripe Checkout integráció
- **Email**: Laravel Mail + Queue rendszer
- **Adatbázis**: 6 tábla, komplexe relációk, tranzakciókezelés

## 🚀 Gyors Indítás (5 perc)

### Backend beállítása:

```bash
cd backend
php composer.phar install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Backend elérhető: `http://localhost:8000`

### Frontend beállítása:

```bash
cd frontend
npm install
npm start
```

Frontend elérhető: `http://localhost:4200`

## 📚 Dokumentáció

- **[DOKUMENTACIO.md](DOKUMENTACIO.md)** - Teljes technikai specifikáció (500+ sor)
- **[GYORS_START.md](GYORS_START.md)** - 5 lépéses gyorsindítási útmutató, Tinker példák, cURL tesztek
- **[backend/README_BACKEND.md](backend/README_BACKEND.md)** - Backend API referencia, adatbázis séma
- **[frontend/README_FRONTEND.md](frontend/README_FRONTEND.md)** - Frontend komponensek, API integrálás, stílusozás

## 🏗️ Projekt Struktúra

```
VIGADO JEGY/
├── backend/
│   ├── app/
│   │   ├── Models/               (6 Eloquent modell)
│   │   ├── Services/             (PricingService, SeatMapService, StripeService)
│   │   ├── Http/Controllers/     (API és Admin controllerek)
│   │   └── Console/Commands/     (ExpireReservations command)
│   ├── database/
│   │   ├── migrations/           (6 tábla migráció)
│   │   └── seeders/              (Teszt adatok: 3 film, 24 szék)
│   ├── routes/
│   │   ├── api.php               (Publikus API végpontok)
│   │   └── admin.php             (Admin végpontok)
│   └── resources/views/emails/   (Blade email sablonok)
│
├── frontend/
│   ├── src/app/
│   │   ├── core/                 (ApiService)
│   │   ├── features/
│   │   │   ├── home/             (Főoldal)
│   │   │   ├── movies/           (Filmek listája, filmdetail, szék kiválasztás)
│   │   │   └── checkout/         (Fizetés, sikeroldal)
│   │   ├── app.component.ts      (Gyökér komponens)
│   │   └── routes.ts             (6 útvonal)
│   ├── angular.json              (CLI konfiguráció)
│   └── tsconfig.json             (TypeScript beállítások)
│
├── DOKUMENTACIO.md               (Technikai spec)
└── GYORS_START.md                (5 lépés)
```

## ✨ Fő Funkciók

### 🎬 Filmkezelés
- Filmek metaadatai (cím, leírás, poszter, YouTube trailer)
- Előadások (vetítési idő, terem, ár override)
- Dinamikus szék árazás (sor alapú, szék alapú, felülírás alapú)

### 🪑 Szék Foglalás
- Interaktív szék térkép (CSS Grid renderelés)
- Valós idejű szék státusz (foglalt, zárolt, szabad)
- 10 perces szék zárolás (egyidejűségi kontroll)
- Email validálás

### 💳 Stripe Integrálás
- Stripe Checkout session létrehozás
- Webhook kezelés (payment_intent.succeeded)
- Automatikus email megerősítés

### 📧 Email Megerősítés
- Foglalás megerősítés HTML email
- QR kód placeholder
- Queue-alapú aszinkron küldés

### 🔒 Biztonság
- Tranzakció-alapú szék zárolás (DB.transaction)
- Egyedi kényszerfeltételek (UNIQUE(showtime_id, seat_key))
- CSRF védelem webhook-ra
- Stripe aláírás verifikálás

## 🔧 Konfigurálás

### Stripe kulcsok beállítása

Frissítsd a `backend/.env` fájlt:

```env
STRIPE_PUBLIC_KEY=pk_test_...
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

Szerezz test kulcsokat: https://dashboard.stripe.com/test/apikeys

### Email beállítása

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=...
MAIL_PASSWORD=...
```

### Queue Worker indítása

```bash
cd backend
php artisan queue:work database
```

## 🧪 Tesztelés

### Test adatok
- **3 film**: Inception, The Dark Knight, Interstellar
- **1 terem**: 3 sor × 8 szék = 24 szék (Standard, VIP, Wheelchair, Aisle)
- **4 előadás**: Különböző dátumok és időpontok

### Test Stripe kártya
- **Szám**: `4242 4242 4242 4242`
- **Lejárat**: `12/26`
- **CVC**: `123`

## 📊 Adatbázis Séma

```
auditoria          (terem/auditorium)
├── id
├── name
├── layout_json    (szék elrendezés)
└── style_json     (CSS stílusok)

movies             (filmek)
├── id
├── title
├── description
├── poster_path
├── trailer_youtube_url
└── duration_min

showtimes          (előadások)
├── id
├── movie_id       (→ movies)
├── auditorium_id  (→ auditoria)
├── starts_at
├── status
└── pricing_override_json

reservations       (foglalások)
├── id
├── showtime_id    (→ showtimes)
├── customer_email
├── status         (PENDING/PAID/CANCELLED/EXPIRED)
├── stripe_session_id
└── expires_at

reservation_items  (foglalás tételek)
├── id
├── reservation_id (→ reservations)
├── seat_key
├── price_amount
└── timestamps

seat_locks        (szék zárolás)
├── id
├── showtime_id   (→ showtimes)
├── reservation_id (→ reservations)
├── seat_key
└── expires_at
```

## 🔄 Foglalás Folyamata

```
1. Felhasználó megnézi a filmeket
   ↓
2. Kiválaszt egy előadást
   ↓
3. Választ székeket az interaktív térképről
   ↓
4. Megadja az email címet
   ↓
5. A backend 10 percig "zárolja" a székeket
   ↓
6. Stripe Checkout oldal
   ↓
7. Sikeroldal + Email megerősítés
```

## 🛠️ Fejlesztői Parancsok

### Backend
```bash
php artisan tinker                 # Laravel REPL
php artisan migrate --seed         # Adatbázis reset
php artisan serve                  # Dev szerver (port 8000)
php artisan queue:work database    # Queue worker
php artisan schedule:run           # Ütemezés (1 perc alatt)
```

### Frontend
```bash
npm start                          # Dev szerver (port 4200)
npm run build                      # Production build
npm run lint                       # TypeScript/ESLint check
```

## 🐳 Docker Deployment

Előkészítés az Azure/GCP/AWS-hez:

```bash
cd backend && docker build -t vigado-backend .
cd ../frontend && docker build -t vigado-frontend .
```

## 📄 Licenc

Saját fejlesztésű projekt. © 2025 VIGADÓ JEGY

## 👤 Support

Kérdések, problémák? Nézd meg a [GYORS_START.md](GYORS_START.md) vagy a [DOKUMENTACIO.md](DOKUMENTACIO.md) fájlt.

---

**Készült**: 2025. január  
**Backend**: Laravel 11  
**Frontend**: Angular 17  
**Integráció**: Stripe + Email Queue
