<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dynamic_template_servers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('dynamic_template_id')
                ->constrained('dynamic_templates')
                ->cascadeOnDelete();

            $table->unsignedInteger('server_id');

            $table->foreign('server_id')
                ->references('id')
                ->on('servers')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique('server_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_template_servers');
    }
};
