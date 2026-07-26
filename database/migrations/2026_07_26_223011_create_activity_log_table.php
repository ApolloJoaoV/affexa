<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Published from spatie/laravel-activitylog and then adapted to this project's
 * conventions: jsonb instead of json so the properties can be indexed and
 * queried with containment operators, timestamptz instead of naive timestamps,
 * and a down() method so the suite can roll back to zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->jsonb('attribute_changes')->nullable();
            $table->jsonb('properties')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
