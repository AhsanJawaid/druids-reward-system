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
        $raw = self::db('shopify_access_token');
        if (filled($raw)) {
            try {
                $decrypted = Crypt::decryptString($raw);
                if (filled($decrypted)) {
                    return self::sanitizeToken($decrypted);
                }
            } catch (Throwable) {
                $plain = self::sanitizeToken((string) $raw);
                if (str_starts_with($plain, 'shpat_')) {
                    return $plain;
                }
            }
        }

        return self::sanitizeToken((string) config('shopify.access_token'));
    }

    public static function clientId(): string
    {
        $value = (string) (self::db('shopify_client_id') ?: config('shopify.client_id', ''));

        return trim($value);
    }

    public static function clientSecret(): string
    {
        $encrypted = self::db('shopify_client_secret');
        if (filled($encrypted)) {
            try {
                return Crypt::decryptString($encrypted);
            } catch (Throwable) {
                return '';
            }
        }

        $fromEnv = trim((string) config('shopify.client_secret', ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        return self::webhookSecret();
    }

    public static function hasClientCredentials(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '';
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

    /**
     * @return list<string>
     */
    public static function webhookSigningSecrets(): array
    {
        $secrets = [];
        foreach ([self::webhookSecret(), self::dedicatedClientSecret()] as $secret) {
            $secret = trim((string) $secret);
            if ($secret !== '') {
                $secrets[] = $secret;
            }
        }

        return array_values(array_unique($secrets));
    }

    public static function dedicatedClientSecret(): string
    {
        $encrypted = self::db('shopify_client_secret');
        if (filled($encrypted)) {
            try {
                return Crypt::decryptString($encrypted);
            } catch (Throwable) {
                return '';
            }
        }

        return trim((string) config('shopify.client_secret', ''));
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
        return (string) config('shopify.api_version', '2026-07');
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
        return filled(self::accessToken()) || self::hasClientCredentials();
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

    public static function sanitizeToken(string $token): string
    {
        $token = trim($token);
        $token = preg_replace('/^Bearer\s+/i', '', $token) ?? $token;
        $token = trim($token, " \t\n\r\0\x0B\"'");
        $token = str_replace(["\r", "\n", "\t"], '', $token);
        $token = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $token) ?? $token;

        return trim($token);
    }

    public static function applyToConfig(): void
    {
        config([
            'shopify.store_domain' => self::storeDomain(),
            'shopify.access_token' => self::accessToken(),
            'shopify.webhook_secret' => self::webhookSecret(),
            'shopify.client_id' => self::clientId(),
            'shopify.mock' => ! self::isLive(),
        ]);
    }

    public static function saveConnection(
        string $domain,
        ?string $token,
        ?string $secret,
        string $callbackBase,
        bool $live,
        ?string $clientId = null,
        ?string $clientSecret = null,
    ): void {
        ProgramSetting::putValue('shopify_store_domain', self::normalizeDomain($domain));
        ProgramSetting::putValue('shopify_callback_url', rtrim($callbackBase, '/'));
        ProgramSetting::putValue('shopify_live', $live ? '1' : '0');

        if (filled($token)) {
            ProgramSetting::putValue('shopify_access_token', Crypt::encryptString(self::sanitizeToken($token)));
        }
        if (filled($secret)) {
            ProgramSetting::putValue('shopify_webhook_secret', Crypt::encryptString($secret));
        }
        if (filled($clientId)) {
            ProgramSetting::putValue('shopify_client_id', trim($clientId));
        }
        if (filled($clientSecret)) {
            ProgramSetting::putValue('shopify_client_secret', Crypt::encryptString($clientSecret));
        }

        self::forgetCachedOauthToken();
        self::applyToConfig();
    }

    public static function cachedOauthToken(): ?string
    {
        $token = (string) self::db('shopify_oauth_token');
        $expires = (int) self::db('shopify_oauth_expires_at');
        if ($token === '' || $expires < time() + 60) {
            return null;
        }

        return $token;
    }

    public static function rememberOauthToken(string $token, int $expiresIn): void
    {
        ProgramSetting::putValue('shopify_oauth_token', $token);
        ProgramSetting::putValue('shopify_oauth_expires_at', (string) (time() + max(60, $expiresIn)));
    }

    public static function forgetCachedOauthToken(): void
    {
        if (self::db('shopify_oauth_token') !== null) {
            ProgramSetting::putValue('shopify_oauth_token', '');
            ProgramSetting::putValue('shopify_oauth_expires_at', '0');
        }
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
