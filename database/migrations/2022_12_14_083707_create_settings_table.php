<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Published from spatie/laravel-settings and then adapted to this project's
 * conventions: jsonb payload, timestamptz, and a down() method so the suite can
 * roll back to zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->string('group');
            $table->string('name');
            $table->boolean('locked')->default(false);
            $table->jsonb('payload');

            $table->timestampsTz();

            $table->unique(['group', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        $table = config('settings.repositories.database.table');

        return is_string($table) && $table !== '' ? $table : 'settings';
    }
};
