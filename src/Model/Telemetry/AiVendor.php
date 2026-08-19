<?php

declare(strict_types=1);

namespace App\Model\Telemetry;

/**
 * Who billed a completion, named from the host we called rather than from our env labels.
 *
 * "primary" and "fallback1" are ours. The operator wants to know whether OpenAI or someone
 * else took the money, and the hostname in the URL is the only name that survives a model
 * swap.
 */
final class AiVendor
{
    public static function fromUrl(string $url): string
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

        $vendor = match (true) {
            $host === '' => 'unknown',
            str_contains($host, 'openai.com') || str_contains($host, 'azure.com') => 'openai',
            str_contains($host, 'googleapis.com') || str_contains($host, 'generativelanguage') => 'gemini',
            str_contains($host, 'groq.com') => 'groq',
            default => (string) preg_replace('/[^a-z0-9]+/', '-', explode('.', $host)[0]),
        };

        $vendor = trim($vendor, '-');

        return $vendor === '' ? 'unknown' : substr($vendor, 0, 32);
    }

    private function __construct()
    {
    }
}
