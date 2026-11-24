<?php
namespace App\Models;

use App\Models\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Model;

class ScheduleDelay extends Model {
    use HasSyncUuid;

    protected $fillable = [
        'uuid',
        'event_id',
        'delay_minutes',
        'reason',
        'severity',
        'revised_start_time',
        'reported_by',
    ];

    protected $casts = [
        'delay_minutes' => 'integer',
    ];

    public function event() {
        return $this->belongsTo(Event::class);
    }

    /** Severity bands used consistently by the API, analytics engine and client. */
    public static function severityFor(int $minutes): string {
        if ($minutes >= 30) return 'critical';
        if ($minutes >= 10) return 'major';
        return 'minor';
    }
}
