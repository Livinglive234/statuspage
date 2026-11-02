<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statuspage_entries', function (Blueprint $table) {
            $table->id();
            // No foreign key on purpose: servers.id type differs between versions and a mismatch fails the migration.
            $table->unsignedBigInteger('server_id')->unique();
            $table->string('subdomain', 63)->unique();
            $table->unsignedSmallInteger('query_port')->nullable();
            // null = use the default from the plugin settings
            $table->boolean('show_version')->nullable();
            $table->boolean('show_players')->nullable();
            $table->boolean('show_player_names')->nullable();
            $table->boolean('show_motd')->nullable();
            $table->boolean('show_plugins')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statuspage_entries');
    }
};
