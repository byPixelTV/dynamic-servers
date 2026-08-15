<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dynamic_templates', function (Blueprint $table) {
            $table->dropForeign(['egg_id']);
            $table->dropForeign(['node_id']);
        });

        Schema::table('dynamic_templates', function (Blueprint $table) {
            $table->foreignId('egg_id')
                ->nullable()
                ->change();

            $table->foreignId('node_id')
                ->nullable()
                ->change();
        });

        Schema::table('dynamic_templates', function (Blueprint $table) {
            $table->foreign('egg_id')
                ->references('id')
                ->on('eggs')
                ->nullOnDelete();

            $table->foreign('node_id')
                ->references('id')
                ->on('nodes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dynamic_templates', function (Blueprint $table) {
            $table->dropForeign(['egg_id']);
            $table->dropForeign(['node_id']);
        });

        Schema::table('dynamic_templates', function (Blueprint $table) {
            $table->foreignId('egg_id')
                ->nullable(false)
                ->change();

            $table->foreignId('node_id')
                ->nullable(false)
                ->change();
        });

        Schema::table('dynamic_templates', function (Blueprint $table) {
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
};
