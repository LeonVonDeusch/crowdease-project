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
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id(); 
            $table->string('name', 120);
            $table->string('url', 500); // NOT NULL
            $table->string('secret', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at_updated_at')->useCurrent();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id(); 
            $table->bigInteger('webhook_id')->unsigned();
            $table->string('event', 64); // NOT NULL
            $table->json('payload');
            $table->smallInteger('attempt');
            $table->enum('status', ['pending', 'delivered', 'failed'])->default('pending');
            $table->timestamp('created_at')->useCurrent();

            // Foreign Key
            $table->foreign('webhook_id')
                  ->references('id')
                  ->on('webhooks')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
    }
};
