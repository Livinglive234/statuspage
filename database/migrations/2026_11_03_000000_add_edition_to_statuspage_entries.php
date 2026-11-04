<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('statuspage_entries', 'edition')) {
            Schema::table('statuspage_entries', function (Blueprint $table) {
                // 'java' (Server List Ping over TCP) or 'bedrock' (RakNet unconnected ping over UDP)
                $table->string('edition', 10)->default('java');
            });
        }
    }

    public function down(): void
    {
        // Nothing to undo: the create migration drops the whole table.
    }
};
