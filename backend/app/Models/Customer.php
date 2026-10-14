<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = ['name', 'email', 'phone'];

    /**
     * Foglaláskor megadott adatok mentése: ha a vásárló (név + telefonszám vagy e-mail alapján) már megvan, csak a hiányzó
     * adatait egészíti ki, különben új vásárlót vesz fel. A hibája soha nem akaszthatja meg a foglalást.
     */
    public static function remember(string $name, ?string $phone, ?string $email): ?self
    {
        try {
            $name = trim($name);
            $phone = filled($phone) ? trim($phone) : null;
            $email = filled($email) ? mb_strtolower(trim($email)) : null;
            if ($name === '') {
                return null;
            }

            // A magyar előhívók (+36, 06, 0036) eltérése ne akadályozza az egyezést: az utolsó 9 számjegyet hasonlítjuk
            $digits = fn(?string $p) => substr(preg_replace('/\D+/', '', (string) $p), -9);
            $existing = static::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->get()->first(
                fn(self $c) => ($phone && $digits($c->phone) !== '' && $digits($c->phone) === $digits($phone))
                    || ($email && mb_strtolower((string) $c->email) === $email)
            );

            if ($existing) {
                $existing->fill(array_filter([
                    'email' => $existing->email ?: $email,
                    'phone' => $existing->phone ?: $phone,
                ]));
                if ($existing->isDirty()) {
                    $existing->save();
                }

                return $existing;
            }

            return static::create(['name' => $name, 'email' => $email, 'phone' => $phone]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Vásárló mentése sikertelen: ' . $e->getMessage());

            return null;
        }
    }
}
