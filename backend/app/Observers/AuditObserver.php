<?php

namespace App\Observers;

use App\Models\Auditorium;
use App\Models\EmailTemplate;
use App\Models\Movie;
use App\Models\Showtime;
use App\Models\TheaterShow;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Az admin felületen kezelt modellek létrehozását, módosítását és törlését naplózza.
 * Csak bejelentkezett felhasználó műveleteit rögzíti (seeder, konzol és publikus műveletek kimaradnak).
 */
class AuditObserver
{
    private const SKIP = ['updated_at', 'created_at', 'id'];

    // Érzékeny vagy nagy mezők: csak a tényük kerül a naplóba, az értékük nem
    private const REDACTED = ['password', 'remember_token'];
    private const SUMMARIZED = ['layout_json', 'style_json', 'pricing_override_json', 'body'];

    public function created(Model $model): void
    {
        if (!ActivityLogger::currentUser()) return;

        [$prefix, $noun, $label] = $this->describe($model);

        ActivityLogger::log(
            "{$prefix}.created",
            "{$noun} felvéve: {$label}",
            $model,
            ['attributes' => $this->snapshot($model)],
            subjectLabel: $label
        );
    }

    public function updated(Model $model): void
    {
        if (!ActivityLogger::currentUser()) return;

        $changes = $this->changes($model);
        if (!$changes) return;

        [$prefix, $noun, $label] = $this->describe($model);
        $fields = implode(', ', array_keys($changes));

        ActivityLogger::log(
            "{$prefix}.updated",
            "{$noun} módosítva: {$label} ({$fields})",
            $model,
            ['changes' => $changes],
            subjectLabel: $label
        );
    }

    public function deleted(Model $model): void
    {
        if (!ActivityLogger::currentUser()) return;

        [$prefix, $noun, $label] = $this->describe($model);

        ActivityLogger::log(
            "{$prefix}.deleted",
            "{$noun} törölve: {$label}",
            $model,
            ['attributes' => $this->snapshot($model)],
            subjectLabel: $label
        );
    }

    /** @return array{0:string,1:string,2:string} [műveletelőtag, megnevezés, azonosító szöveg] */
    private function describe(Model $model): array
    {
        return match (true) {
            $model instanceof Movie => ['movie', 'Film', (string) $model->title],
            $model instanceof TheaterShow => ['theater_show', 'Színházi előadás', (string) $model->title],
            $model instanceof Auditorium => ['auditorium', 'Terem', (string) $model->name],
            $model instanceof User => ['user', 'Felhasználó', $model->name . ' (' . $model->email . ')'],
            $model instanceof EmailTemplate => ['email_template', 'E-mail sablon', (string) $model->name],
            $model instanceof Showtime => ['showtime', 'Előadás', $this->showtimeLabel($model)],
            default => [strtolower(class_basename($model)), class_basename($model), '#' . $model->getKey()],
        };
    }

    private function showtimeLabel(Showtime $showtime): string
    {
        $title = $showtime->program_title ?? 'Előadás #' . $showtime->getKey();
        $when = $showtime->starts_at?->copy()->timezone('Europe/Budapest')->format('Y.m.d. H:i');

        return $when ? "{$title} – {$when}" : $title;
    }

    private function changes(Model $model): array
    {
        $result = [];

        foreach ($model->getChanges() as $key => $new) {
            if (in_array($key, self::SKIP, true)) continue;

            if (in_array($key, self::REDACTED, true) || in_array($key, self::SUMMARIZED, true)) {
                $result[$key] = '(módosítva)';
                continue;
            }

            $old = $model->getRawOriginal($key);
            if ($old == $new) continue;

            $result[$key] = ['old' => $old, 'new' => $new];
        }

        return $result;
    }

    private function snapshot(Model $model): array
    {
        $attributes = [];

        foreach ($model->getAttributes() as $key => $value) {
            if (in_array($key, self::SKIP, true) || in_array($key, self::REDACTED, true) || in_array($key, self::SUMMARIZED, true)) {
                continue;
            }
            $attributes[$key] = $value;
        }

        return $attributes;
    }
}
