<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Upgrades tables created by the first version of the plugin. Safe to run on a fresh install too.
return new class extends Migration
{
    private const COLUMNS = ['show_version', 'show_players', 'show_player_names', 'show_motd'];

    public function up(): void
    {
        foreach (self::COLUMNS as $column) {
            if (!Schema::hasColumn('statuspage_entries', $column)) {
                Schema::table('statuspage_entries', function (Blueprint $table) use ($column) {
                    $table->boolean($column)->nullable();
                });
            }
        }

        if (!Schema::hasColumn('statuspage_entries', 'show_plugins')) {
            Schema::table('statuspage_entries', function (Blueprint $table) {
                $table->boolean('show_plugins')->nullable();
            });
        } else {
            // The first version had NOT NULL default false; null now means "use the default".
            Schema::table('statuspage_entries', function (Blueprint $table) {
                $table->boolean('show_plugins')->nullable()->default(null)->change();
            });
        }
    }

    public function down(): void
    {
        // Nothing to undo: the create migration drops the whole table.
    }
};
