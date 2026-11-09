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
        private string $baseUrl = 'https://api.tungo.ai',
        private ?string $workspaceId = null,
        private ?string $companyId = null,
        private int $maxRetries = 2,
    ) {
        $this->apiKey = $this->apiKey ?? (getenv('TUNGO_API_KEY') ?: null);
        $this->baseUrl = rtrim($this->baseUrl ?: (getenv('TUNGO_BASE_URL') ?: 'https://api.tungo.ai'), '/');
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
    public function ask(string $prompt, ?string $companyId = null, ?string $conversationId = null): array
    {
        $body = ['message' => $prompt, 'stream' => false];
        if ($conversationId !== null) {
            $body['conversation_id'] = $conversationId;
        }
        $params = $companyId ? ['co' => $companyId] : [];
        $res = $this->chat->create($body, $params);
        return [
            'conversation_id' => $res['conversation_id'] ?? '',
            'message_id' => $res['assistant_message']['id'] ?? '',
            'reply' => $res['assistant_message']['text'] ?? '',
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
        return $this->chat->postHook($token, $payload, $params);
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
    ): array {
        $idemKey = $params['idempotency_key'] ?? ($idempotent ? bin2hex(random_bytes(16)) : null);
        unset($params['idempotency_key']);
        $url = $this->baseUrl . $path . ($params ? '?' . http_build_query($params) : '');
        $headers = [
            'Accept: application/json',
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
        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $content ?? '',
                'ignore_errors' => true,
            ],
        ]);
        $raw = (string) @file_get_contents($url, false, $ctx);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function requestStream(
        string $method,
        string $path,
        array $params = [],
        ?array $body = null,
        string $auth = 'key',
    ): Generator {
        $res = $this->request($method, $path, $params, $body, $auth);
        yield from $res;
    }
}
