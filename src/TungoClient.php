<?php

declare(strict_types=1);

namespace Tungo\Sdk;

use Generator;

final class TungoClient
{
    public readonly WorkspacesResource $workspaces;
    public readonly CompaniesResource $companies;
    public readonly PlatformResource $platform;
    public readonly ApiKeysResource $apiKeys;
    public readonly WebhooksResource $webhooks;
    public readonly ChatResource $chat;
    public readonly ProposalsResource $proposals;
    public readonly FeedResource $feed;
    public readonly BatchesResource $batches;
    public readonly EsfResource $esf;
    public readonly FormsResource $forms;
    public readonly OnecResource $onec;
    public readonly AutomationsResource $automations;

    private ?array $lastRateLimit = null;

    public function __construct(
        private ?string $apiKey = null,
        private string $baseUrl = 'https://api.tungo.kz',
        private ?string $workspaceId = null,
        private ?string $companyId = null,
        private int $maxRetries = 2,
    ) {
        $this->apiKey = $this->apiKey ?? (getenv('TUNGO_API_KEY') ?: null);
        $this->baseUrl = rtrim($this->baseUrl ?: (getenv('TUNGO_BASE_URL') ?: 'https://api.tungo.kz'), '/');
        $this->workspaceId = $this->workspaceId ?? (getenv('TUNGO_WORKSPACE_ID') ?: null);
        $this->companyId = $this->companyId ?? (getenv('TUNGO_COMPANY_ID') ?: null);
        $this->workspaces = new WorkspacesResource($this);
        $this->companies = new CompaniesResource($this);
        $this->platform = new PlatformResource($this);
        $this->apiKeys = new ApiKeysResource($this);
        $this->webhooks = new WebhooksResource($this);
        $this->chat = new ChatResource($this);
        $this->proposals = new ProposalsResource($this);
        $this->feed = new FeedResource($this);
        $this->batches = new BatchesResource($this);
        $this->esf = new EsfResource($this);
        $this->forms = new FormsResource($this);
        $this->onec = new OnecResource($this);
        $this->automations = new AutomationsResource($this);
    }

    public function lastRateLimit(): ?array
    {
        return $this->lastRateLimit;
    }

    public function resolveCompanyId(?string $explicit = null): string
    {
        $co = $explicit ?: $this->companyId;
        if (!$co) {
            throw new TungoError('Не указан companyId');
        }
        return $co;
    }

    public function resolveWorkspaceId(?string $explicit = null): string
    {
        $ws = $explicit ?: $this->workspaceId;
        if (!$ws) {
            throw new TungoError('Не указан workspaceId');
        }
        return $ws;
    }

    /** Быстрый синхронный запрос к ИИ-бухгалтеру (stream = false). */
    public function ask(string $prompt, ?string $companyId = null, ?string $dialogId = null): array
    {
        $body = ['message' => $prompt, 'stream' => false];
        if ($dialogId !== null) {
            $body['dialog_id'] = $dialogId;
        }
        $params = $companyId ? ['co' => $companyId] : [];
        $res = $this->chat->create($body, $params);
        $dlg = $res['dialog_id'] ?? $res['conversation_id'] ?? '';
        $ans = $res['answer'] ?? $res['assistant_message']['text'] ?? '';
        return [
            'dialog_id' => $dlg,
            'conversation_id' => $dlg,
            'answer' => $ans,
            'reply' => $ans,
            'raw' => $res,
        ];
    }

    /** Отправка события во входящий хук /hooks/{token} с автоматической HMAC-подписью X-Signature. */
    public function sendHook(string $token, array $payload, ?string $secret = null): array
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $params = [];
        if ($secret !== null && $secret !== '') {
            $params['X-Signature'] = TungoWebhooks::signHookPayload((string) $raw, $secret);
        }
        return $this->automations->postHook($token, $payload, $params);
    }

    /** Запустить сценарий автоматизации и дождаться завершения (done / error / cancelled / skipped). */
    public function runScenarioAndWait(string $scenarioId, array $body = [], ?string $companyId = null, int $timeoutSec = 120): array
    {
        $params = $companyId ? ['co' => $companyId] : [];
        $started = $this->automations->runScenario($scenarioId, $body, $params);
        return $this->waitForRun((string) ($started['id'] ?? ''), $companyId, $timeoutSec);
    }

    /** Дождаться завершения запуска сценария автоматизации. */
    public function waitForRun(string $runId, ?string $companyId = null, int $timeoutSec = 120): array
    {
        $params = $companyId ? ['co' => $companyId] : [];
        $deadline = time() + $timeoutSec;
        while (true) {
            $run = $this->automations->getRun($runId, $params);
            $st = $run['state'] ?? '';
            if (in_array($st, ['done', 'error', 'cancelled', 'skipped'], true)) {
                return $run;
            }
            if (time() >= $deadline) {
                throw new TungoError("Превышено время ожидания запуска {$runId}");
            }
            sleep(1);
        }
    }

    /** Дождаться завершения задания пачки (running === false). */
    public function waitForBatch(string $batchId, ?string $companyId = null, int $timeoutSec = 120): array
    {
        $params = $companyId ? ['co' => $companyId] : [];
        $deadline = time() + $timeoutSec;
        while (true) {
            $job = $this->batches->get($batchId, $params);
            if (empty($job['running'])) {
                return $job;
            }
            if (time() >= $deadline) {
                throw new TungoError("Превышено время ожидания пачки {$batchId}");
            }
            sleep(1);
        }
    }

    /** Загрузить банковскую выписку на разбор в пачку предложений. */
    public function uploadBankStatement(string $fileName, string $content, bool $isPlainText = false, ?string $companyId = null): array
    {
        $params = $companyId ? ['co' => $companyId] : [];
        $body = ['file_name' => $fileName];
        if ($isPlainText) {
            $body['text'] = $content;
        } else {
            $body['file'] = base64_encode($content);
        }
        return $this->batches->createBankStatement($body, $params);
    }

    /** Разбирает строку потока SSE в массив событий. */
    public static function parseSse(string $rawStream): array
    {
        $events = [];
        $eventType = '';
        $dataLines = [];
        $flush = static function () use (&$eventType, &$dataLines, &$events): void {
            if ($eventType === '' && $dataLines === []) {
                return;
            }
            $raw = implode("\n", $dataLines);
            $ev = $eventType !== '' ? $eventType : 'message';
            $eventType = '';
            $dataLines = [];
            $decoded = json_decode($raw, true);
            $events[] = [
                'event' => $ev,
                'data' => json_last_error() === JSON_ERROR_NONE ? $decoded : $raw,
                'raw' => $raw,
            ];
        };

        foreach (preg_split("/\r?\n/", $rawStream) ?: [] as $line) {
            if ($line === '') {
                $flush();
                continue;
            }
            if (str_starts_with($line, ':')) {
                continue;
            }
            if (str_starts_with($line, 'event:')) {
                $eventType = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $val = substr($line, 5);
                if (str_starts_with($val, ' ')) {
                    $val = substr($val, 1);
                }
                $dataLines[] = $val;
            }
        }
        $flush();
        return $events;
    }

    public function request(
        string $method,
        string $path,
        array $params = [],
        ?array $body = null,
        string $auth = 'key',
        bool $idempotent = false,
        bool $retrySafe = false,
        string $accept = 'application/json',
    ): array {
        $raw = $this->requestRawString($method, $path, $params, $body, $auth, $idempotent, $retrySafe, $accept);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function requestRawString(
        string $method,
        string $path,
        array $params = [],
        ?array $body = null,
        string $auth = 'key',
        bool $idempotent = false,
        bool $retrySafe = false,
        string $accept = 'application/json',
    ): string {
        $idemKey = $params['idempotency_key'] ?? ($idempotent ? bin2hex(random_bytes(16)) : null);
        unset($params['idempotency_key']);
        $url = $this->baseUrl . $path . ($params ? '?' . http_build_query($params) : '');
        $headers = [
            'Accept: ' . $accept,
            'User-Agent: tungo-sdk-php/0.1.0',
        ];
        if ($auth === 'key') {
            if (!$this->apiKey) {
                throw new TungoAuthError(401, 'missing_api_key', 'API ключ не задан');
            }
            $headers[] = 'x-api-key: ' . $this->apiKey;
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }
        if ($idemKey) {
            $headers[] = 'Idempotency-Key: ' . $idemKey;
        }
        $content = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $content = (string) json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        $maxAttempts = ($retrySafe || $idempotent) ? (1 + $this->maxRetries) : 1;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $ctx = stream_context_create([
                'http' => [
                    'method' => $method,
                    'header' => implode("\r\n", $headers),
                    'content' => $content ?? '',
                    'ignore_errors' => true,
                    'timeout' => 30,
                ],
            ]);
            $http_response_header = [];
            $raw = (string) @file_get_contents($url, false, $ctx);
            $status = 200;
            $respHeaders = [];
            foreach ($http_response_header as $hdrLine) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $hdrLine, $m)) {
                    $status = (int) $m[1];
                } elseif (str_contains($hdrLine, ':')) {
                    [$hk, $hv] = explode(':', $hdrLine, 2);
                    $respHeaders[strtolower(trim($hk))] = trim($hv);
                }
            }
            if (isset($respHeaders['x-ratelimit-limit'], $respHeaders['x-ratelimit-remaining'], $respHeaders['x-ratelimit-reset'])) {
                $this->lastRateLimit = [
                    'limit' => (int) $respHeaders['x-ratelimit-limit'],
                    'remaining' => (int) $respHeaders['x-ratelimit-remaining'],
                    'reset' => (int) $respHeaders['x-ratelimit-reset'],
                ];
            }
            if ($status >= 200 && $status < 300) {
                return $raw;
            }
            $apiErr = TungoApiError::fromResponse($status, $raw, $respHeaders);
            if ($attempt + 1 < $maxAttempts && in_array($status, [429, 502, 503, 504], true)) {
                $waitUs = (int) (($apiErr->retryAfter ?? (0.25 * (2 ** $attempt))) * 1_000_000);
                usleep($waitUs);
                continue;
            }
            throw $apiErr;
        }
        throw new TungoError('Превышено число попыток запроса к Tungo API');
    }

    public function requestStream(
        string $method,
        string $path,
        array $params = [],
        ?array $body = null,
        string $auth = 'key',
    ): Generator {
        $raw = $this->requestRawString($method, $path, $params, $body, $auth, false, false, 'text/event-stream');
        foreach (self::parseSse($raw) as $event) {
            yield $event;
        }
    }
}
