<?php

declare(strict_types=1);

namespace Drumeo\Video\Http;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly array $headers = [],
    ) {
    }

    /** @param array<string,mixed> $body */
    public static function json(int $status, array $body, array $headers = []): self
    {
        return new self($status, $body, $headers);
    }

    public static function ok(array $body): self
    {
        return self::json(200, $body);
    }

    public static function accepted(array $body): self
    {
        return self::json(202, $body);
    }

    public static function badRequest(string $error, array $extra = []): self
    {
        return self::json(400, ['error' => $error] + $extra);
    }

    public static function unauthorized(): self
    {
        return self::json(401, ['error' => 'unauthorized']);
    }

    public static function notFound(string $error = 'not found'): self
    {
        return self::json(404, ['error' => $error]);
    }

    public static function conflict(string $error, array $extra = []): self
    {
        return self::json(409, ['error' => $error] + $extra);
    }

    public static function tooMany(string $error = 'queue full', int $retryAfter = 15): self
    {
        return self::json(429, ['error' => $error], ['Retry-After' => (string) $retryAfter]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
