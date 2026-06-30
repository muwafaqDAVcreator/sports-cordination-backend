<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->foreignId('athlete_id')->constrained('athletes')->onDelete('cascade');
            $table->unsignedTinyInteger('placement')->nullable();
            $table->string('time')->nullable();
            $table->decimal('wind_speed', 4, 1)->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'placement']);
            $table->unique(['event_id', 'athlete_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('results');
    }
};
