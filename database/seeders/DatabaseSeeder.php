<?php

namespace Database\Seeders;

use App\Models\ProgramSetting;
use App\Models\Reward;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        ProgramSetting::putValue('program_name', 'Rewards System');
        ProgramSetting::putValue('points_per_pound', '2');
        ProgramSetting::putValue('points_per_dollar', '2');
        ProgramSetting::putValue('min_order_amount', '0');
        ProgramSetting::putValue('discount_expiry_days', '30');
        ProgramSetting::putValue('hold_days', '14');
        ProgramSetting::putValue('free_product_collection_id', '');

        Reward::syncCatalog();
    }
}
