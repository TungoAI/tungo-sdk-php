<?php

declare(strict_types=1);

namespace Tungo\Sdk;

use RuntimeException;

class TungoError extends RuntimeException {}

class TungoApiError extends TungoError
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?string $hint = null,
        public readonly ?string $requestId = null,
        public readonly ?float $retryAfter = null,
    ) {
        parent::__construct($message, $status);
    }

    public static function fromResponse(int $status, string $rawBody, array $headers = []): self
    {
        $decoded = json_decode($rawBody, true);
        $err = is_array($decoded) ? ($decoded['error'] ?? $decoded) : [];
        $code = is_string($err['code'] ?? null) ? $err['code'] : 'api_error';
        $msg = is_string($err['message'] ?? null) ? $err['message'] : ($rawBody ?: "HTTP $status");
        $hint = is_string($err['hint'] ?? null) ? $err['hint'] : (is_array($decoded) && is_string($decoded['hint'] ?? null) ? $decoded['hint'] : null);
        $reqId = is_string($err['request_id'] ?? null)
            ? $err['request_id']
            : (is_array($decoded) && is_string($decoded['request_id'] ?? null) ? $decoded['request_id'] : ($headers['x-request-id'] ?? null));
        $retryAfter = isset($headers['retry-after']) ? (float) $headers['retry-after'] : null;

        return match ($status) {
            400, 422 => new TungoValidationError($status, $code, $msg, $hint, $reqId, $retryAfter),
            401 => new TungoAuthError($status, $code, $msg, $hint, $reqId, $retryAfter),
            402 => new TungoPaymentRequiredError($status, $code, $msg, $hint, $reqId, $retryAfter),
            403 => new TungoPermissionError($status, $code, $msg, $hint, $reqId, $retryAfter),
            404, 410 => new TungoNotFoundError($status, $code, $msg, $hint, $reqId, $retryAfter),
            409 => new TungoConflictError($status, $code, $msg, $hint, $reqId, $retryAfter),
            429 => new TungoRateLimitError($status, $code, $msg, $hint, $reqId, $retryAfter),
            default => new self($status, $code, $msg, $hint, $reqId, $retryAfter),
        };
    }
}

class TungoValidationError extends TungoApiError {}
class TungoAuthError extends TungoApiError {}
class TungoPaymentRequiredError extends TungoApiError {}
class TungoPermissionError extends TungoApiError {}
class TungoNotFoundError extends TungoApiError {}
class TungoConflictError extends TungoApiError {}
class TungoRateLimitError extends TungoApiError {}
