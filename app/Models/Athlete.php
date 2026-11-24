<?php
namespace App\Models;

use App\Models\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Athlete extends Model {
    use HasFactory, HasSyncUuid;

    protected $fillable = [
        'uuid',
        'name',
        'bib_number',
        'age',
        'house',
        'event_id',
    ];

    protected $casts = [
        'bib_number' => 'integer',
        'age' => 'integer',
    ];

    public function event() {
        return $this->belongsTo(Event::class);
    }

    public function result() {
        return $this->hasOne(Result::class);
    }

    /** Next free bib number within an event (bibs are unique per event). */
    public static function nextBibNumber(int $eventId): int {
        return ((int) static::where('event_id', $eventId)->max('bib_number')) + 1;
    }
}
