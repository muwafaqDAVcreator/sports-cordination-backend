<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncConflict extends Model {
    protected $fillable = [
        'user_id',
        'mutation_id',
        'entity',
        'entity_uuid',
        'resolution',
        'rule',
        'client_payload',
        'server_snapshot',
        'client_updated_at',
        'server_updated_at',
    ];

    protected $casts = [
        'client_payload' => 'array',
        'server_snapshot' => 'array',
        'client_updated_at' => 'datetime',
        'server_updated_at' => 'datetime',
    ];
}
