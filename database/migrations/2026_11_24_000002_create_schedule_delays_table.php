<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('schedule_delays', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->unsignedSmallInteger('delay_minutes');
            $table->string('reason')->nullable();
            $table->enum('severity', ['minor', 'major', 'critical'])->default('minor');
            $table->time('revised_start_time')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['event_id', 'created_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('schedule_delays');
    }
};
