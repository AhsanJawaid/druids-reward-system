<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ProgramSetting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $row = static::query()->where('key', $key)->first();

        return $row?->value ?? $default;
    }

    public static function putValue(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
        Cache::forget('program.settings');
    }

    /**
     * @return array<string, mixed>
     */
    public static function current(): array
    {
        return [
            'program_name' => static::getValue('program_name', 'Atelier Rewards'),
            'points_per_dollar' => (float) static::getValue('points_per_dollar', '1'),
            'min_order_amount' => (float) static::getValue('min_order_amount', '0'),
            'discount_expiry_days' => (int) static::getValue('discount_expiry_days', '30'),
        ];
    }
}
