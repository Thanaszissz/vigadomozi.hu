# Jegyfoglalási Rendszer - Frontend (Angular)

## Telepítés

### Előfeltételek
- Node.js 18+
- npm vagy yarn

### Lépések

```bash
cd frontend
npm install
```

## Futtatás

```bash
npm start
```

A frontend elérhető lesz a `http://localhost:4200` címen.

## API Konfigurálás

Az API végpontok az `src/app/core/api/api.service.ts` file-ban vannak konfigurálva:

```typescript
const API_URL = 'http://localhost:8000/api';
```

Módosítsa az `API_URL` értékét, ha a backend más helyen fut.

## Komponensek szerkezete

```
src/
├── app/
│   ├── core/
│   │   └── api/
│   │       └── api.service.ts          # API kommunikáció
│   ├── features/
│   │   ├── home/
│   │   │   └── home.component.ts       # Kezdőoldal
│   │   ├── movies/
│   │   │   ├── movies-list.component.ts # Filmek listája
│   │   │   └── movie-detail.component.ts # Film részletei
│   │   └── checkout/
│   │       ├── seat-select.component.ts  # Szék kiválasztás
│   │       ├── checkout.component.ts     # Fizetés oldal
│   │       └── success.component.ts      # Sikeres vásárlás
│   ├── routes.ts                        # Útvonalak
│   └── app.component.ts                 # Root komponens
├── main.ts                              # Belépési pont
└── index.html                           # HTML template
```

## Fontosabb funkciók

### Film Kilistázása
- **Komponens**: `MoviesListComponent`
- **Route**: `/movies`
- **Funkció**: Összes film megjelenítése grid formátumban

### Film Részletei
- **Komponens**: `MovieDetailComponent`
- **Route**: `/movies/:id`
- **Funkció**: Film információi, trailer beágyazás, elérhető előadások

### Szék Kiválasztás
- **Komponens**: `SeatSelectComponent`
- **Route**: `/showtimes/:id/select-seats`
- **Funkció**: Interaktív nézőtér, szék kiválasztás, foglalás inicializálása

**Jellegzetességek:**
- Kattintható szék grid
- Szék státusz megjelenítés (szabad, foglalt, locked)
- Drag & drop lehetőség (opcionális)
- Teljes ár kalkulus
- Email validáció
- 10 perces lock időzítő

### Fizetés (Checkout)
- **Komponens**: `CheckoutComponent`
- **Route**: `/checkout/:reservationId`
- **Funkció**: Foglalás összegzése, Stripe Checkout indítása

### Sikeres Vásárlás
- **Komponens**: `SuccessComponent`
- **Route**: `/success/:reservationId`
- **Funkció**: Vásárlás megerősítés, foglalási azonosító, további teendők

## API Service

A `ApiService` a teljes backend kommunikációt kezeli:

```typescript
// Filmek
getMovies(): Observable<Movie[]>
getMovieById(id: number): Observable<Movie>

// Előadások
getShowtimes(filters?: any): Observable<Showtime[]>
getShowtimeById(id: number): Observable<ShowtimeDetail>

// Foglalások
lockSeats(showtimeId: number, email: string, seatKeys: string[]): Observable<any>
getReservation(id: number): Observable<Reservation>
initiatePayment(id: number): Observable<any>
```

## Stílok

Az alkalmazás **CSS Grid** és **Flexbox** felhasználásával responsive. Mobilon is jól működik.

Témaszínek:
- Elsődleges: `#1e3a8a` (Sötét kék)
- Siker: `#4CAF50` (Zöld)
- Figyelmeztetés: `#ff9800` (Narancssárga)
- Hiba: `#f44336` (Piros)

## Build

```bash
npm run build
```

Az optimalizált kód a `dist/` mappában lesz.

## Fejlesztés

### TypeScript
A projekt **TypeScript 5.2**-t használ `strict` mód-dal.

Típusok az API Service-ben:

```typescript
interface Movie { ... }
interface Showtime { ... }
interface Reservation { ... }
```

### Angular Verzió
Angular 17+ modern features:
- Standalone components
- Signal-based reactivity (opcionális)
- New Control Flow syntax (@if, @for)

## Hibakezelés

- API hibák: User-friendly error messages
- Network timeout: Retry mechanizmus
- Session expiry: Redirect login page-ra

## Security

- CORS: Backend-ről megfelelő CORS headers szükségesek
- XSS: Angular automatikusan sanitizálja a template-eket
- CSRF: API route-okat külön middleware-ből kezelik

## Éles deployment

### S3 / CloudFront
```bash
npm run build
# Deploy dist/ to S3 bucket
```

### Azure Static Web Apps
```bash
az staticwebapp create \
  --name cinema-booking \
  --resource-group mygroup \
  --location westeurope \
  --source https://github.com/user/repo \
  --branch main
```

### Docker
```dockerfile
FROM node:18 as builder
WORKDIR /app
COPY . .
RUN npm install && npm run build

FROM nginx:latest
COPY --from=builder /app/dist/cinema-booking /usr/share/nginx/html
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
```

## Teljesítmény

- **Lazy Loading**: Komponensek route-onként betöltve
- **OnPush Change Detection**: Optimalizált rendering
- **Service Worker**: PWA support (opcionális)

## Tesztelés

```bash
npm test
```

Unit test-ek Karma + Jasmine-nel futnak.

## Gyakori Problémák

### CORS Hiba
```
Access to XMLHttpRequest from origin blocked by CORS policy
```

**Megoldás**: Backend `.env` fájlban állítsa be:
```env
APP_FRONTEND_URL=http://localhost:4200
```

### Stripe Checkout Nem Betöltődik
- Ellenőrizze a Stripe kulcsokat az `.env` fájlban
- Tesztelési módban vagyunk-e (pk_test_, sk_test_)?

### Szék Lock 409 Hiba
- Más felhasználó közben foglalja a széket
- A UI automatikusan frissíti a nézőteret

## Fejlesztési Tippek

1. **Browser DevTools**: Network tab-ot nyisd meg az API hívások nyomonkövetéséhez
2. **Angular DevTools**: Chrome extension az Angular alkalmazások debuggálásához
3. **Stripe Dashboard**: Test transactions megtekintése
4. **Local Mock Server**: json-server-rel mockozhatod az API-t fejlesztés során

## Támogatás

Problémák esetén:
1. Ellenőrizd a browser console-t
2. Nézd meg az backend logokat
3. Ellenőrizd a network tabban az API válaszokat
