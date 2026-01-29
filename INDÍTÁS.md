# 🎬 VIGADÓ MOZI - Projekt Indítása

## 📋 Jelenlegi Státusz

### ✅ Backend: MŰKÖDIK
```
URL: http://127.0.0.1:8000
Szerver: Laravel 11 (php artisan serve)
Adatbázis: SQLite ✓ 
Migráció: ✓ Kész
Seeding: ✓ Kész (3 film, 24 szék, 4 előadás)
```

### 📝 Test Adatok
- **3 Film**: Inception, The Dark Knight, Interstellar
- **1 Terem**: 24 szék (3 sor × 8 szék)
- **4 Előadás**: Különböző dátumok/időpontok
- **Adatbázis**: `backend/database/database.sqlite`

---

## 🔌 API Tesztelés (Backend Működésének Ellenőrzése)

### PowerShell-ben tesztelj (curl helyett):

```powershell
# Összes film lekérdezése
$response = Invoke-WebRequest -Uri "http://127.0.0.1:8000/api/movies" -UseBasicParsing
$response.Content | ConvertFrom-Json

# Összes előadás lekérdezése
$response = Invoke-WebRequest -Uri "http://127.0.0.1:8000/api/showtimes" -UseBasicParsing
$response.Content | ConvertFrom-Json

# Konkrét előadás (id=1)
$response = Invoke-WebRequest -Uri "http://127.0.0.1:8000/api/showtimes/1" -UseBasicParsing
$response.Content | ConvertFrom-Json
```

---

## 🎨 Frontend Indítása (Alternative)

### Opció 1: Közvetlen npm install (Ha az permission error megoldódott)
```bash
cd "j:\VIGADO MOZI\frontend"
npm install
npm start
```
**Majd**: Nyisd meg `http://localhost:4200` böngészőben

### Opció 2: Python Development Server (Szabad)
```bash
cd "j:\VIGADO MOZI\frontend"
python -m http.server 4200
```
**Majd**: Nyisd meg `http://localhost:4200` böngészőben

### Opció 3: Node.js http-server (ha van)
```bash
npm install -g http-server
cd "j:\VIGADO MOZI\frontend\src"
http-server
```

---

## 🧪 Teljes Foglalási Folyamat Tesztelése

1. **Backend API** (`http://127.0.0.1:8000/api/movies`)
   - Válassz film
   - Válassz előadás
   
2. **Frontend UI** (`http://localhost:4200`)
   - Szék kiválasztás
   - Email megadás
   - Stripe checkout

3. **Test Stripe Kártya**
   - Szám: `4242 4242 4242 4242`
   - Lejárat: `12/26`
   - CVC: `123`

---

## 📚 Dokumentáció

- **[DOKUMENTACIO.md](../DOKUMENTACIO.md)** – Teljes technikai spec
- **[GYORS_START.md](../GYORS_START.md)** – cURL/Tinker példák
- **[backend/README_BACKEND.md](../backend/README_BACKEND.md)** – Backend API
- **[frontend/README_FRONTEND.md](../frontend/README_FRONTEND.md)** – Frontend komponensek

---

## 🔍 Hibaelhárítás

### npm install Permission Error
```powershell
# Windows: Close antivirus or VSCode, then:
cd "j:\VIGADO MOZI\frontend"
Remove-Item "node_modules" -Recurse -Force
npm cache clean --force
npm install --legacy-peer-deps --no-audit
```

### Backend nem válaszol
```powershell
# Ellenőrizd, hogy a szerver futó-e:
cd "j:\VIGADO MOZI\backend"
php artisan serve
```

### Adatbázis hiba
```powershell
cd "j:\VIGADO MOZI\backend"
php artisan migrate:reset
php artisan migrate --seed
```

---

## ✨ Projekt Szerkezet

```
VIGADO MOZI/
├── backend/
│   ├── app/Models/              (6 modell)
│   ├── app/Services/            (Pricing, SeatMap, Stripe)
│   ├── routes/api.php           (API végpontok)
│   ├── database/database.sqlite (Adatbázis)
│   └── php artisan serve        (Szerver porta 8000)
│
├── frontend/
│   ├── src/app/                 (6 komponens)
│   ├── package.json             (npm függőségek)
│   └── npm start                (Szerver porta 4200)
│
└── [DOKUMENTACIO.md]             (Teljes leírás)
```

---

## 🎯 Következő Lépések

1. ✅ **Backend működik** – API elérhető `http://127.0.0.1:8000`
2. ⏳ **Frontend npm install** – Akár manuálisan is folytatható
3. 📧 **Stripe config** – `.env` frissítése API kulcsokkal
4. 🚀 **Éles deploy** – Docker/Azure/AWS

---

**Készült**: 2025. január 28.  
**Backend**: Laravel 11 + SQLite  
**Frontend**: Angular 17  
**Status**: ✅ Működőképes Beta
