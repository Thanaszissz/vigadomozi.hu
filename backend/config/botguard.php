<?php

return [
    // Láthatatlan robotvédelem a nyilvános foglalási végpontokon (székzárolás, foglalás)
    'enabled' => env('BOT_GUARD_ENABLED', true),

    // A számítási feladat nehézsége: ennyi vezető nulla bit kell a hash elején (16 bit ≈ 65 ezer próba, böngészőben ~1 mp)
    'difficulty' => (int) env('BOT_GUARD_DIFFICULTY', 16),

    // A kihívás megoldása előtt/után ennyi másodpercig fogadható el
    'min_age' => 1,
    'max_age' => 1800,
];
