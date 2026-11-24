<?php
namespace App\Models;

use App\Models\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model {
    use HasFactory, HasSyncUuid;

    protected $fillable = [
        'uuid',
        'name',
        'discipline',
        'event_date',
        'start_time',
        'end_time',
        'actual_start_time',
        'venue',
        'officials_required',
        'officials_assigned',
        'status',
        'created_by',
    ];

    protected $casts = [
        'event_date' => 'date:Y-m-d',
        'officials_required' => 'integer',
        'officials_assigned' => 'integer',
    ];

    public function creator() {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function athletes() {
        return $this->hasMany(Athlete::class);
    }

    public function results() {
        return $this->hasMany(Result::class);
    }

    public function delays() {
        return $this->hasMany(ScheduleDelay::class)->latest();
    }

    public function scopeToday($query) {
        return $query->whereDate('event_date', today());
    }

    public function scopeActive($query) {
        return $query->whereIn('status', ['running', 'upcoming']);
    }

    public function scopeForUser($query, $userId) {
        return $query->where('created_by', $userId);
    }

    /** Events the given user may see: admins see every event, coordinators their own. */
    public function scopeVisibleTo($query, User $user) {
        return $user->isAdmin() ? $query : $query->forUser($user->id);
    }

    /** Total minutes of delay logged against this event. */
    public function totalDelayMinutes(): int {
        return (int) $this->delays->sum('delay_minutes');
    }
}
