<?php

namespace App\Support;

use App\Models\Reward;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EnsureAssessmentSchema
{
    public static function apply(): void
    {
        if (! Schema::hasTable('customers') || ! Schema::hasTable('rewards')) {
            return;
        }

        if (! Schema::hasColumn('customers', 'birthday')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->date('birthday')->nullable();
            });
        }
        if (! Schema::hasColumn('customers', 'last_birthday_reward_year')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->unsignedSmallInteger('last_birthday_reward_year')->nullable();
            });
        }
        if (! Schema::hasColumn('customers', 'account_bonus_awarded')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->boolean('account_bonus_awarded')->default(false);
            });
        }
        if (! Schema::hasColumn('customers', 'newsletter_bonus_awarded')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->boolean('newsletter_bonus_awarded')->default(false);
            });
        }

        if (Schema::hasTable('point_transactions') && ! Schema::hasColumn('point_transactions', 'available_at')) {
            Schema::table('point_transactions', function (Blueprint $table) {
                $table->timestamp('available_at')->nullable();
            });
        }

        if (! Schema::hasColumn('rewards', 'slug')) {
            Schema::table('rewards', function (Blueprint $table) {
                $table->string('slug')->nullable()->unique();
            });
        }

        if (Schema::hasTable('redemptions') && ! Schema::hasColumn('redemptions', 'shopify_object_type')) {
            Schema::table('redemptions', function (Blueprint $table) {
                $table->string('shopify_object_type')->default('discount_code');
            });
        }
        if (Schema::hasTable('redemptions') && ! Schema::hasColumn('redemptions', 'product_gid')) {
            Schema::table('redemptions', function (Blueprint $table) {
                $table->string('product_gid')->nullable();
            });
        }

        static::markMigrationRan();

        if (Schema::hasColumn('rewards', 'slug')) {
            $slugs = collect(Reward::CATALOG)->pluck('slug');
            $found = Reward::query()->whereIn('slug', $slugs)->count();
            if ($found < $slugs->count()) {
                Reward::syncCatalog();
            }
        }
    }

    private static function markMigrationRan(): void
    {
        if (! Schema::hasTable('migrations')) {
            return;
        }

        $name = '2026_09_15_200000_align_rewards_with_assessment';
        if (DB::table('migrations')->where('migration', $name)->exists()) {
            return;
        }

        $batch = (int) DB::table('migrations')->max('batch');
        DB::table('migrations')->insert([
            'migration' => $name,
            'batch' => $batch + 1,
        ]);
    }
}
