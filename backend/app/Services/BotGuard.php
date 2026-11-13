<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Láthatatlan "captcha": a böngésző a háttérben megold egy aláírt, egyszer használatos számítási feladatot
 * (proof-of-work), így a tömeges, automatizált foglalás drága, a valódi vásárlónak viszont semmi teendője nincs.
 */
class BotGuard
{
    /** @return array{token: string, difficulty: int} */
    public function issue(): array
    {
        $payload = $this->b64(json_encode([
            'n' => bin2hex(random_bytes(12)),
            't' => time(),
            'd' => (int) config('botguard.difficulty', 12),
        ]));

        return [
            'token' => $payload . '.' . $this->sign($payload),
            'difficulty' => (int) config('botguard.difficulty', 12),
        ];
    }

    public function verify(?string $token, ?string $solution): bool
    {
        if (!is_string($token) || !is_string($solution) || !preg_match('/^\d{1,12}$/', $solution)) {
            return false;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2 || !hash_equals($this->sign($parts[0]), $parts[1])) {
            return false;
        }

        $data = json_decode($this->unb64($parts[0]), true);
        if (!is_array($data) || !isset($data['n'], $data['t'], $data['d'])) {
            return false;
        }

        $age = time() - (int) $data['t'];
        if ($age < (int) config('botguard.min_age', 1) || $age > (int) config('botguard.max_age', 1800)) {
            return false;
        }

        if (!$this->hasLeadingZeroBits(hash('sha256', $token . ':' . $solution, true), (int) $data['d'])) {
            return false;
        }

        // Egy megoldás csak egyszer használható fel
        return Cache::add('botguard:' . $data['n'], 1, (int) config('botguard.max_age', 1800));
    }

    private function hasLeadingZeroBits(string $hash, int $bits): bool
    {
        for ($i = 0; $i < $bits; $i++) {
            if ((ord($hash[intdiv($i, 8)]) >> (7 - $i % 8) & 1) !== 0) {
                return false;
            }
        }

        return true;
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    private function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'));
    }
}
