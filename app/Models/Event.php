<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model {
    use HasFactory;

    protected $fillable = [
        'name',
        'event_date',
        'start_time',
        'end_time',
        'venue',
        'status',
        'created_by',
    ];

    protected $casts = [
        'event_date' => 'date',
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

    public function scopeToday($query) {
        return $query->whereDate('event_date', today());
    }

    public function scopeActive($query) {
        return $query->whereIn('status', ['running', 'upcoming']);
    }

    public function scopeForUser($query, $userId) {
        return $query->where('created_by', $userId);
    }
}
