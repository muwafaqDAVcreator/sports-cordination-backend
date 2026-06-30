<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Result extends Model {
    use HasFactory;

    protected $fillable = [
        'event_id',
        'athlete_id',
        'placement',
        'time',
        'wind_speed',
        'notes',
    ];

    public function event() {
        return $this->belongsTo(Event::class);
    }

    public function athlete() {
        return $this->belongsTo(Athlete::class);
    }
}
