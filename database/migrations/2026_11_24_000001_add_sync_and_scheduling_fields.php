<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Module 6: offline-first support.
 *
 * Every syncable record gets a globally unique `uuid` so the mobile client can
 * create records offline with a stable identity, reference them from child
 * records before the server has assigned an id, and safely retry uploads.
 * Events also gain the scheduling and resourcing fields the analytics engine
 * uses for delay and bottleneck detection.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('events', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
            $table->string('discipline')->nullable()->after('name');
            $table->time('actual_start_time')->nullable()->after('end_time');
            $table->unsignedSmallInteger('officials_required')->default(0)->after('venue');
            $table->unsignedSmallInteger('officials_assigned')->default(0)->after('officials_required');
        });

        Schema::table('athletes', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        Schema::table('results', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        foreach (['events', 'athletes', 'results'] as $table) {
            DB::table($table)->whereNull('uuid')->orderBy('id')->each(function ($row) use ($table) {
                DB::table($table)->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
            });

            Schema::table($table, function (Blueprint $t) {
                $t->unique('uuid');
            });
        }
    }

    public function down(): void {
        foreach (['events', 'athletes', 'results'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropUnique(['uuid']);
                $t->dropColumn('uuid');
            });
        }

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['discipline', 'actual_start_time', 'officials_required', 'officials_assigned']);
        });
    }
};
