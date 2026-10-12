<?php

namespace App\Models;

use App\Services\EmailTemplateService;
use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $fillable = ['key', 'name', 'description', 'subject', 'body', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * A sablon lekérése kulcs alapján; ha még nincs az adatbázisban, az alapértelmezett szöveggel létrejön.
     */
    public static function forKey(string $key): self
    {
        $defaults = EmailTemplateService::DEFAULTS[$key] ?? null;
        abort_if($defaults === null, 404, 'Ismeretlen e-mail sablon.');

        return static::withoutEvents(fn() => static::firstOrCreate(['key' => $key], $defaults + ['is_active' => true]));
    }
}
