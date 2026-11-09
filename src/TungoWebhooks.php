<?php

declare(strict_types=1);

namespace Tungo\Sdk;

final class TungoWebhooks
{
    public static function signHookPayload(string $rawBody, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
    }

    public static function verifyHookSignature(string $rawBody, string $signature, string $secret): bool
    {
        $trimmed = trim($signature);
        if ($trimmed === '' || $secret === '') {
            return false;
        }
        $hex = str_starts_with($trimmed, 'sha256=') ? substr($trimmed, 7) : $trimmed;
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals(strtolower($expected), strtolower($hex));
    }

    public static function verifyWebhook(
        string $rawBody,
        array $headers,
        string $secret,
        ?int $nowUnix = null,
        int $toleranceSec = 300,
    ): array {
        $norm = [];
        foreach ($headers as $k => $v) {
            $norm[strtolower((string) $k)] = is_array($v) ? (string) ($v[0] ?? '') : (string) $v;
        }
        $msgId = $norm['svix-id'] ?? $norm['webhook-id'] ?? '';
        $msgTs = $norm['svix-timestamp'] ?? $norm['webhook-timestamp'] ?? '';
        $msgSig = $norm['svix-signature'] ?? $norm['webhook-signature'] ?? '';
        if ($msgId === '' || $msgTs === '' || $msgSig === '') {
            throw new TungoError('Отсутствуют заголовки svix-id / svix-timestamp / svix-signature');
        }
        $ts = (int) $msgTs;
        if ($nowUnix !== null && $toleranceSec > 0 && abs($nowUnix - $ts) > $toleranceSec) {
            throw new TungoError('Метка времени вебхука вне допустимого окна');
        }
        $cleanSecret = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
        $keyBytes = base64_decode($cleanSecret, true);
        if ($keyBytes === false) {
            throw new TungoError('Некорректный секрет whsec_');
        }
        $signed = "{$msgId}.{$msgTs}.{$rawBody}";
        $expectedB64 = base64_encode(hash_hmac('sha256', $signed, $keyBytes, true));

        $matched = false;
        foreach (preg_split('/\s+/', trim($msgSig)) ?: [] as $part) {
            if (str_starts_with($part, 'v1,')) {
                $cand = substr($part, 3);
                if (hash_equals($expectedB64, $cand)) {
                    $matched = true;
                    break;
                }
            }
        }
        if (!$matched) {
            throw new TungoError('Неверная подпись svix-signature');
        }
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new TungoError('Некорректный JSON тела вебхука');
        }
        return $decoded;
    }
}
