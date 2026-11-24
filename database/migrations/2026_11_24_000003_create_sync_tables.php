<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Idempotency ledger: every uploaded mutation id is recorded with the
        // response it produced, so a retried upload (e.g. after the connection
        // dropped mid-batch) returns the same outcome instead of re-applying.
        Schema::create('sync_mutations', function (Blueprint $table) {
            $table->id();
            $table->uuid('mutation_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('entity', 20);
            $table->string('operation', 10);
            $table->string('status', 20);
            $table->json('response');
            $table->timestamps();
        });

        // Conflict audit log: one row per conflict the sync engine resolved,
        // keeping both versions and the rule applied so every automatic
        // decision is reviewable (NFR3).
        Schema::create('sync_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('mutation_id');
            $table->string('entity', 20);
            $table->uuid('entity_uuid');
            $table->string('resolution', 20); // client_wins | server_wins
            $table->string('rule', 20)->default('lww');
            $table->json('client_payload');
            $table->json('server_snapshot');
            $table->timestamp('client_updated_at')->nullable();
            $table->timestamp('server_updated_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('sync_conflicts');
        Schema::dropIfExists('sync_mutations');
    }
};
