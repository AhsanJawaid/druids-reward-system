<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_events', function (Blueprint $table) {
            $table->id();
            $table->string('topic');
            $table->string('shop_domain')->nullable();
            $table->string('webhook_id')->nullable();
            $table->boolean('hmac_verified')->default(false);
            $table->string('status')->default('processed');
            $table->text('message')->nullable();
            $table->json('payload');
            $table->timestamps();

            $table->index(['topic', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_events');
    }
};
