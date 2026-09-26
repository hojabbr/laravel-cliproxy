<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('cliproxy.batches.table', 'cliproxy_batch_requests'), function (Blueprint $table): void {
            $table->id();
            $table->string('batch_id', 40);
            $table->string('custom_id');
            $table->string('status', 16);
            $table->longText('params');
            $table->longText('result')->nullable();
            $table->string('model')->nullable();
            $table->string('error_type', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'custom_id']);
            $table->index(['batch_id', 'status']);
            $table->index('ended_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('cliproxy.batches.table', 'cliproxy_batch_requests'));
    }
};
