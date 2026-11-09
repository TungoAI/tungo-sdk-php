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
        $svix = $v['webhook_svix'];
        $env = TungoWebhooks::verifyWebhook($svix['payload'], $svix['headers'], $svix['secret'], $svix['timestamp'], 300);
        assert($env['event'] === 'proposal.written');

        // 2. Incoming Hook HMAC
        $hook = $v['hook_hmac'];
        assert(TungoWebhooks::signHookPayload($hook['payload'], $hook['secret']) === $hook['header_signature']);

        // 3. Money
        foreach ($v['money'] as $m) {
            assert(TungoMoney::toTiyn($m['tenge']) === $m['tiyn']);
            assert(TungoMoney::formatTiyn($m['tiyn'], 'KZT') === $m['formatted_kzt']);
        }

        // 4. Errors
        foreach ($v['errors'] as $e) {
            $err = TungoApiError::fromResponse($e['status'], (string) json_encode($e['body']));
            if ($e['status'] === 429) {
                assert($err instanceof TungoRateLimitError);
            }
            if ($e['status'] === 402) {
                assert($err instanceof TungoPaymentRequiredError);
            }
        }

        // 5. SSE
        $events = TungoClient::parseSse($v['sse_stream']['raw']);
        assert(count($events) === count($v['sse_stream']['expected_events']));
    }
}
