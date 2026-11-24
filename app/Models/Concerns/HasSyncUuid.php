<?php
namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Gives a model a stable, client-generatable UUID.
 *
 * Records created on the mobile client while offline arrive with the UUID the
 * client assigned; records created on the server (seeders, admin tools) get
 * one generated here, so every row can be addressed the same way by the
 * synchronization engine.
 */
trait HasSyncUuid {
    public static function bootHasSyncUuid(): void {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public static function findByUuid(?string $uuid): ?static {
        return $uuid ? static::where('uuid', $uuid)->first() : null;
    }
}
