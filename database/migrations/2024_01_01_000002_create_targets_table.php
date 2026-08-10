<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('host');
            $table->boolean('show_host_publicly')->default(false);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('icmp_enabled')->default(true);
            $table->boolean('tcp_enabled')->default(false);
            $table->unsignedSmallInteger('tcp_port')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['category_id', 'is_enabled']);
            $table->index(['is_public', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('targets');
    }
};
