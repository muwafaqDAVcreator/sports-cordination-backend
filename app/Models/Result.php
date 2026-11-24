<?php
namespace App\Models;

use App\Models\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Result extends Model {
    use HasFactory, HasSyncUuid;

    protected $fillable = [
        'uuid',
        'event_id',
        'athlete_id',
        'placement',
        'time',
        'wind_speed',
        'notes',
    ];

    protected $casts = [
        'placement' => 'integer',
        'wind_speed' => 'float',
    ];

    public function event() {
        return $this->belongsTo(Event::class);
    }

    public function athlete() {
        return $this->belongsTo(Athlete::class);
    }
}
