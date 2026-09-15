<?php

namespace App\Services\Shopify;

use App\Models\ProgramSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ShopifyConfig
{
    public static function storeDomain(): string
    {
        return self::normalizeDomain(
            (string) (self::db('shopify_store_domain') ?: config('shopify.store_domain'))
        );
    }

    public static function accessToken(): string
    {
        $encrypted = self::db('shopify_access_token');
        if (filled($encrypted)) {
            try {
                return Crypt::decryptString($encrypted);
            } catch (Throwable) {
                return '';
            }
        }

        return (string) config('shopify.access_token');
    }

    public static function webhookSecret(): string
    {
        $encrypted = self::db('shopify_webhook_secret');
        if (filled($encrypted)) {
            try {
                return Crypt::decryptString($encrypted);
            } catch (Throwable) {
                return '';
            }
        }

        return (string) config('shopify.webhook_secret');
    }

    public static function callbackUrl(): string
    {
        $base = self::publicBase();
        if (str_ends_with($base, '/api/webhooks/shopify')) {
            return $base;
        }

        return $base.'/api/webhooks/shopify';
    }

    public static function callbackBase(): string
    {
        return self::publicBase();
    }

    public static function publicBase(): string
    {
        $saved = trim((string) self::db('shopify_callback_url'));
        $placeholder = $saved === ''
            || str_contains($saved, 'ngrok')
            || str_contains($saved, 'localhost')
            || str_contains($saved, '127.0.0.1');

        $base = $placeholder
            ? rtrim((string) config('app.url'), '/')
            : rtrim($saved, '/');

        if (str_starts_with($base, 'http://')) {
            $base = 'https://'.substr($base, 7);
        }

        return rtrim($base, '/');
    }

    public static function apiVersion(): string
    {
        return (string) config('shopify.api_version', '2025-01');
    }

    public static function isLive(): bool
    {
        $flag = self::db('shopify_live');
        if ($flag !== null && $flag !== '') {
            return $flag === '1' || $flag === 'true';
        }

        return ! filter_var(config('shopify.mock'), FILTER_VALIDATE_BOOL);
    }

    public static function hasToken(): bool
    {
        return filled(self::accessToken());
    }

    public static function tokenHint(): string
    {
        $token = self::accessToken();
        if ($token === '') {
            return '';
        }

        $len = strlen($token);

        return str_repeat('•', max(8, min(12, $len - 4))).substr($token, -4);
    }

    public static function normalizeDomain(string $raw): string
    {
        $raw = strtolower(trim($raw));
        $raw = (string) preg_replace('#^https?://#', '', $raw);
        $raw = explode('/', $raw)[0] ?? $raw;
        $raw = rtrim($raw, '.');

        if ($raw === '') {
            return '';
        }

        if (! str_contains($raw, '.')) {
            $raw .= '.myshopify.com';
        }

        return $raw;
    }

    public static function applyToConfig(): void
    {
        config([
            'shopify.store_domain' => self::storeDomain(),
            'shopify.access_token' => self::accessToken(),
            'shopify.webhook_secret' => self::webhookSecret(),
            'shopify.mock' => ! self::isLive(),
        ]);
    }

    public static function saveConnection(string $domain, ?string $token, ?string $secret, string $callbackBase, bool $live): void
    {
        ProgramSetting::putValue('shopify_store_domain', self::normalizeDomain($domain));
        ProgramSetting::putValue('shopify_callback_url', rtrim($callbackBase, '/'));
        ProgramSetting::putValue('shopify_live', $live ? '1' : '0');

        if (filled($token)) {
            ProgramSetting::putValue('shopify_access_token', Crypt::encryptString($token));
        }
        if (filled($secret)) {
            ProgramSetting::putValue('shopify_webhook_secret', Crypt::encryptString($secret));
        }

        self::applyToConfig();
    }

    private static function db(string $key): ?string
    {
        try {
            if (! Schema::hasTable('program_settings')) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        $value = ProgramSetting::getValue($key);

        return $value === null ? null : (string) $value;
    }
}
