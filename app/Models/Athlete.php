<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Athlete extends Model {
    use HasFactory;

    protected $fillable = [
        'name',
        'bib_number',
        'age',
        'house',
        'event_id',
    ];

    public function event() {
        return $this->belongsTo(Event::class);
    }

    public function result() {
        return $this->hasOne(Result::class);
    }
}
