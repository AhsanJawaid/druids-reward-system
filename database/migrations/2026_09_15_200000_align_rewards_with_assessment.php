<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->date('birthday')->nullable()->after('name');
            $table->unsignedSmallInteger('last_birthday_reward_year')->nullable()->after('birthday');
            $table->boolean('account_bonus_awarded')->default(false)->after('last_birthday_reward_year');
            $table->boolean('newsletter_bonus_awarded')->default(false)->after('account_bonus_awarded');
        });

        Schema::table('point_transactions', function (Blueprint $table) {
            $table->timestamp('available_at')->nullable()->after('metadata');
        });

        Schema::table('rewards', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('name');
        });

        Schema::table('redemptions', function (Blueprint $table) {
            $table->string('shopify_object_type')->default('discount_code')->after('shopify_discount_id');
            $table->string('product_gid')->nullable()->after('shopify_object_type');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'birthday',
                'last_birthday_reward_year',
                'account_bonus_awarded',
                'newsletter_bonus_awarded',
            ]);
        });
        Schema::table('point_transactions', function (Blueprint $table) {
            $table->dropColumn('available_at');
        });
        Schema::table('rewards', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
        Schema::table('redemptions', function (Blueprint $table) {
            $table->dropColumn(['shopify_object_type', 'product_gid']);
        });
    }
};
