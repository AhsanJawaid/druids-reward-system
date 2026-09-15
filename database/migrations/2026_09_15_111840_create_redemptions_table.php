<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reward_id')->constrained()->cascadeOnDelete();
            $table->foreignId('point_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('points_spent');
            $table->string('discount_code');
            $table->string('shopify_discount_id')->nullable();
            $table->string('status')->default('issued');
            $table->timestamp('expires_at')->nullable();
            $table->json('graphql_result')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redemptions');
    }
};
