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
        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 120);
            $table->char('color', 7); // Format: #RRGGBB
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at_updated_at')->useCurrent();
        });

        Schema::create('stops', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('route_id')->unsigned();
            $table->string('name', 120);
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->smallInteger('sequence');
            $table->timestamp('created_at_updated_at')->useCurrent();

            // Foreign Key
            $table->foreign('route_id')
                    ->references('id')
                    ->on('routes')
                    ->onDelete('cascade');
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('route_id')->unsigned();
            $table->string('plate_number', 20)->unique();
            $table->integer('capacity');
            $table->enum('status', ['active', 'maintenance', 'retired'])->default('active');
            $table->timestamp('created_at_updated_at')->useCurrent();

            // Foreign Key
            $table->foreign('route_id')
                    ->references('id')
                    ->on('routes')
                    ->onDelete('cascade');
        });
        
        Schema::create('density_logs', function (Blueprint $table) {
            $table->id(); // BIGINT UNSIGNED, PK, AUTO_INCREMENT
            $table->unsignedBigInteger('vehicle_id');
            $table->integer('passenger_count'); // >= 0 (validasi di app / DB constraint tambahan)
            $table->integer('capacity_at_time'); // > 0
            $table->decimal('occupancy_ratio', 4, 3); // contoh: 0.875
            $table->timestamp('recorded_at')->index();
            $table->timestamp('created_at')->useCurrent();

            // Foreign Key
            $table->foreign('vehicle_id')
                  ->references('id')
                  ->on('vehicles')
                  ->onDelete('cascade');
        });

        Schema::create('forecasts', function (Blueprint $table) {
            $table->id(); // BIGINT UNSIGNED, PK, AUTO_INCREMENT
            $table->unsignedBigInteger('vehicle_id');
            $table->integer('predicted_count'); // NOT NULL
            $table->timestamp('predicted_for')->index();
            $table->string('model_version', 50);
            $table->timestamp('created_at')->useCurrent();

            // Foreign Key
            $table->foreign('vehicle_id')
                  ->references('id')
                  ->on('vehicles')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('density_logs');
        Schema::dropIfExists('forecasts');
        Schema::dropIfExists('stops');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('routes');
        
    }
};
