<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$users = $app['db']->connection()->select('SELECT id, name, email FROM users');
echo "=== Felhasználók az adatbázisban ===\n";
foreach ($users as $user) {
    echo "ID: {$user->id}, Név: {$user->name}, Email: {$user->email}\n";
}
echo "\nÖsszesen: " . count($users) . " felhasználó\n";
