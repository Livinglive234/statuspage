<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('statuspage_entries', 'proxy_protocol')) {
            Schema::table('statuspage_entries', function (Blueprint $table) {
                // true for servers that require the PROXY protocol header, such as Velocity with haproxy-protocol enabled
                $table->boolean('proxy_protocol')->default(false);
            });
        }
    }

    public function down(): void
    {
        // Nothing to undo: the create migration drops the whole table.
    }
};
