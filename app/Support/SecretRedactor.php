<?php

namespace App\Support;

/**
 * Redacts secrets (API keys, tokens) from strings before they are logged or
 * shown to users. Defense-in-depth: even if an HTTP client embeds a request
 * URL or header containing a credential in an exception message, this strips
 * it so it can never reach logs or the browser.
 */
class SecretRedactor
{
    private const REPLACEMENT = '[REDACTED]';

    /**
     * Patterns that match a secret value anywhere in a string.
     *
     * @var array<int, string>
     */
    private static array $patterns = [
        // `key=...` / `api_key=...` / `apikey=...` / `access_token=...` query params
        '/\b(?:api[_-]?key|key|access[_-]?token|token|secret)=[^\s&"\'\\\\]+/i',
        // Google API keys (AIza...)
        '/\bAIza[0-9A-Za-z\-_]{10,}/',
        // OpenAI / Stripe style secret keys (sk-..., rk-..., pk_live_..., sk_live_...)
        '/\b(?:sk|rk)[-_][0-9A-Za-z\-_]{16,}/',
        '/\b(?:pk|sk|rk)_(?:live|test)_[0-9A-Za-z]{16,}/',
        // Bearer tokens
        '/\bBearer\s+[0-9A-Za-z\-._~+\/]+=*/i',
        // Google OAuth tokens (ya29...)
        '/\bya29\.[0-9A-Za-z\-_]+/',
    ];

    public static function redact(?string $value): string
    {
        if ($value === null || $value === '') {
            return (string) $value;
        }

        foreach (self::$patterns as $pattern) {
            $value = preg_replace_callback($pattern, function (array $m): string {
                // Preserve the `key=` style prefix so the message still reads
                // sensibly (e.g. "key=[REDACTED]"), redacting only the value.
                if (str_contains($m[0], '=')) {
                    return explode('=', $m[0], 2)[0] . '=' . self::REPLACEMENT;
                }

                return self::REPLACEMENT;
            }, $value) ?? $value;
        }

        return $value;
    }
}
