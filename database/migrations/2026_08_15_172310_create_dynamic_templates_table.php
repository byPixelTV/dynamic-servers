<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('dynamic_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('egg_id')->constrained('eggs')->cascadeOnDelete();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->json('startup_variables')->nullable();
            $table->unsignedInteger('memory');
            $table->unsignedInteger('disk');
            $table->unsignedInteger('cpu')->default(0);
            $table->unsignedInteger('port_range_start');
            $table->unsignedInteger('port_range_end');
            $table->unsignedInteger('min_servers')->default(0);
            $table->boolean('auto_creation')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dynamic_templates');
    }
};
