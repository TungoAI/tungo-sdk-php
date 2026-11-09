<?php

declare(strict_types=1);

namespace Tungo\Sdk\Tests;

use Tungo\Sdk\TungoApiError;
use Tungo\Sdk\TungoClient;
use Tungo\Sdk\TungoMoney;
use Tungo\Sdk\TungoPaymentRequiredError;
use Tungo\Sdk\TungoRateLimitError;
use Tungo\Sdk\TungoWebhooks;

final class ConformanceTest
{
    public static function run(): void
    {
        $raw = (string) file_get_contents(__DIR__ . '/../../tungo-sdkgen/conformance/vectors.json');
        $v = json_decode($raw, true);

        // 1. Svix Webhook
        $svix = $v['webhook_verification'];
        $headers = [
            'svix-id' => $svix['msg_id'],
            'svix-timestamp' => (string) $svix['timestamp'],
            'svix-signature' => $svix['dual_signature'],
        ];
        $env = TungoWebhooks::verifyWebhook($svix['body'], $headers, $svix['secret'], $svix['timestamp'], 300);
        assert(($env['type'] ?? $env['event'] ?? '') === 'proposal.written');

        // 2. Incoming Hook HMAC
        $hook = $v['incoming_hook'];
        assert(TungoWebhooks::signHookPayload($hook['body'], $hook['secret']) === $hook['prefixed_signature']);
        assert(TungoWebhooks::verifyHookSignature($hook['body'], $hook['prefixed_signature'], $hook['secret']) === true);
        assert(TungoWebhooks::verifyHookSignature($hook['body'], $hook['hex_signature'], $hook['secret']) === true);

        // 3. Money & Kazakhstan BIN/IIN
        foreach ($v['money'] as $m) {
            assert(TungoMoney::toTiyn($m['tenge']) === $m['tiyn']);
            assert(TungoMoney::formatTiyn($m['tiyn'], 'KZT') === $m['formatted']);
        }
        foreach ($v['bin_iin'] as $b) {
            assert(TungoMoney::isValidBinIin($b['value']) === $b['valid']);
        }

        // 4. Errors
        foreach ($v['errors'] as $e) {
            $err = TungoApiError::fromResponse($e['status'], $e['body']);
            assert($err->errorCode === $e['error_code']);
            assert($err->hint === $e['hint']);
            if ($e['status'] === 429) {
                assert($err instanceof TungoRateLimitError);
            }
            if ($e['status'] === 402) {
                assert($err instanceof TungoPaymentRequiredError);
            }
        }

        // 5. SSE
        $events = TungoClient::parseSse($v['sse']['raw_stream']);
        assert(count($events) === count($v['sse']['events']));
    }
}
