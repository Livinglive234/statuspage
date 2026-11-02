<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The manual subdomain is now optional: servers with a Subdomains plugin address need no manual one.
        Schema::table('statuspage_entries', function (Blueprint $table) {
            $table->string('subdomain', 63)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Nothing to undo: the create migration drops the whole table.
    }
};
