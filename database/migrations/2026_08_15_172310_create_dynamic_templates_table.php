<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dynamic_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            $table->unsignedInteger('egg_id');
            $table->unsignedInteger('node_id');

            $table->json('startup_variables')->nullable();

            $table->unsignedInteger('memory');
            $table->unsignedInteger('disk');
            $table->unsignedInteger('cpu')->default(0);

            $table->unsignedInteger('port_range_start');
            $table->unsignedInteger('port_range_end');

            $table->unsignedInteger('min_servers')->default(0);
            $table->boolean('auto_creation')->default(false);

            $table->timestamps();

            $table->foreign('egg_id')
                ->references('id')
                ->on('eggs')
                ->cascadeOnDelete();

            $table->foreign('node_id')
                ->references('id')
                ->on('nodes')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_templates');
    }
};
