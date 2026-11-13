<?php

return [
    // Láthatatlan robotvédelem a nyilvános foglalási végpontokon (székzárolás, foglalás)
    'enabled' => env('BOT_GUARD_ENABLED', true),

    // A számítási feladat nehézsége: ennyi vezető nulla bit kell a hash elején (12 bit ≈ 4 ezer próba, böngészőben egy pillanat; a throttle és a rejtett mező egészíti ki)
    'difficulty' => (int) env('BOT_GUARD_DIFFICULTY', 12),

    // A kihívás megoldása előtt/után ennyi másodpercig fogadható el
    'min_age' => 1,
    'max_age' => 1800,
];
