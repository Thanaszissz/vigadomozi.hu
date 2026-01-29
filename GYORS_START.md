# 🚀 Gyors Start Guide

## Előfeltételek
- PHP 8.2+
- Node.js 18+
- MySQL 8+
- Composer

## Lépés 1: Backend Telepítés (5 perc)

```bash
cd backend

# 1. Environment beállítások
cp .env.example .env
php artisan key:generate

# 2. Adatbázis (SQLite használatával egyszerűbb):
# .env-ben DB_CONNECTION=sqlite (már így van)

# 3. Migrációk futtatása
php artisan migrate --seed

# 4. Backend indítása
php artisan serve
# Elérhető: http://localhost:8000
```

**Teszt adatok:** DatabaseSeeder.php automatikusan létrehoz 3 filmet + 1 termet + 4 előadást.

## Lépés 2: Frontend Telepítés (3 perc)

```bash
cd frontend

# 1. NPM dependencies
npm install

# 2. Frontend start
npm start
# Elérhető: http://localhost:4200
```

## Lépés 3: Teszt Végigmegyünk

1. **Nyisd meg:** http://localhost:4200
2. **Kattints:** "Filmek" → "Inception" (vagy másik film)
3. **Válassz:** Egy előadást (pl. ma 19:00)
4. **Kattints:** "Jegyek foglalása"
5. **Válassz székeket:** Kattints 3-4 szék-re (zöld)
6. **Add meg:** Email-ed
7. **Kattints:** "Foglalás (10 perc)"
8. **Megerősítés:** Foglalás összegzése

## Stripe Teszt Fizetés (Opcionális)

Az éles Stripe fizetés csak akkor működik, ha beállítottad a kulcsokat.

### Teszt Beállítása:
1. Regisztrálj: https://dashboard.stripe.com
2. Test Mode-ba lépj (jobboldal teteje)
3. API Keys másolása:
   - Publishable Key (`pk_test_...`)
   - Secret Key (`sk_test_...`)

4. Backend `.env` frissítése:
```env
STRIPE_PUBLIC_KEY=pk_test_...
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_... # Később, webhook-kal
```

5. Teszt Checkout (élesben):
   - Checkout-nál használd: `4242 4242 4242 4242` (test card)
   - Hónap: bármelyik jövőbeli (pl. 12/25)
   - CVC: bármelyik 3 szám
   - ZIP: bármelyik 5 szám

**Eredmény:** Sikeres fizetés után Success oldal, email megerősítés.

## Gyorsan Módosítani

### Film Hozzáadása (Backend)

```bash
php artisan tinker

Movie::create([
  'title' => 'Shrek',
  'description' => 'Egy mocsárban lakó sült közönség',
  'duration_min' => 90,
]);

exit
```

### Nézőtér Módosítása

```bash
php artisan tinker

$auditorium = Auditorium::first();
$auditorium->update([
  'layout_json' => [
    'screenLabel' => 'VÁSZON',
    'rows' => [
      [
        'label' => 'A',
        'basePrice' => 2000,
        'seats' => [
          ['key' => 'A-01', 'type' => 'STANDARD'],
          ['key' => 'A-02', 'type' => 'STANDARD'],
        ],
      ],
    ],
  ],
]);

exit
```

### Előadás Hozzáadása

```bash
php artisan tinker

Showtime::create([
  'movie_id' => 1,
  'auditorium_id' => 1,
  'starts_at' => now()->addDays(1)->setHour(19)->setMinutes(0),
  'status' => 'active',
]);

exit
```

## Teszteléshez Hasznos

### Foglalások Lekérése
```bash
php artisan tinker

Reservation::with('items', 'showtime.movie')->get();

# Vagy konkrét:
Reservation::find(1)->load('items');
```

### Lejárt Foglalások Érzékelése
```bash
php artisan tinker

Reservation::where('status', 'PENDING')
  ->where('expires_at', '<', now())
  ->update(['status' => 'EXPIRED']);

# Vagy command-ból:
# php artisan reservations:expire
```

### Szék Lock Ellenőrzése
```bash
php artisan tinker

SeatLock::with('reservation', 'showtime')->get();
```

## Fejlesztési Szerverek Futtatása

### Terminal 1: Backend
```bash
cd backend
php artisan serve
```

### Terminal 2: Frontend
```bash
cd frontend
npm start
```

### Terminal 3: Queue Worker (Email küldéshez)
```bash
cd backend
php artisan queue:work database
```

### Terminal 4: Scheduler (Lejárt foglalások kezeléséhez)
```bash
cd backend
watch -n 1 'php artisan schedule:run'
# vagy Windows-on:
# :repeat php artisan schedule:run
```

## API Teszt (cURL-el)

### Filmek Lekérése
```bash
curl http://localhost:8000/api/movies
```

### Előadások
```bash
curl "http://localhost:8000/api/showtimes?date=2026-01-28&movie_id=1"
```

### Előadás + Nézőtér
```bash
curl http://localhost:8000/api/showtimes/1
```

### Szék Foglalása
```bash
curl -X POST http://localhost:8000/api/showtimes/1/lock \
  -H "Content-Type: application/json" \
  -d '{"email":"test@test.com","seatKeys":["A-01","A-02"]}'
```

### Foglalás Lekérése
```bash
curl http://localhost:8000/api/reservations/1
```

## FAQ

**Q: Hiba: "Composer not found"**
A: Telepítsd a Composer-t: https://getcomposer.org/download

**Q: Hiba: "npm not found"**
A: Telepítsd a Node.js-t: https://nodejs.org

**Q: Hiba: "SQLite database not found"**
A: Laravel automatikusan létrehozza a `database.sqlite` fájlt.

**Q: Backend nem indul el**
A: Próbáld: `php artisan config:clear`

**Q: Frontend lassú/nem reagál**
A: `npm install` újra, majd `npm start`

**Q: Stripe Checkout nem jelenik meg**
A: Ellenőrizd, hogy a `.env` file-ban benne van-e az `STRIPE_PUBLIC_KEY`.

## Prod Deploy

### Azure-ra (egyszerű)
```bash
# Backend
az webapp deployment source config-zip \
  --resource-group mygroup \
  --name my-cinema-backend \
  --src-path backend.zip

# Frontend
az staticwebapp create \
  --name cinema-frontend \
  --source https://github.com/user/repo
```

### Docker-rel
```bash
docker-compose up -d
# backend:8000 + frontend:80
```

## Következő Lépések

1. ✅ **Teszt**: Végigmegy a foglalási flow-n
2. 📝 **Testreszabás**: Filmek, termek, előadások módosítása
3. 🔐 **Stripe Élesítés**: Éles API kulcsok beállítása
4. 📧 **Email Beállítás**: Valódi SMTP szerver (SendGrid, AWS SES, stb.)
5. 🚀 **Deploy**: Azure / Docker / AWS / DigitalOcean
6. 📊 **Monitoring**: Application Insights / Sentry beállítása

## Support & Docs

- **Backend**: `backend/README_BACKEND.md`
- **Frontend**: `frontend/README_FRONTEND.md`
- **Teljes Docs**: `DOKUMENTACIO.md`

---

**Szórakozz! 🎬🍿**
