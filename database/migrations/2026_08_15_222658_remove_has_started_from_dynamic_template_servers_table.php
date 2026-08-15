<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dynamic_template_servers', function (Blueprint $table) {
            $table->dropColumn('has_started');
        });
    }

    public function down(): void
    {
        Schema::table('dynamic_template_servers', function (Blueprint $table) {
            $table->boolean('has_started')->default(false);
        });
    }
};
