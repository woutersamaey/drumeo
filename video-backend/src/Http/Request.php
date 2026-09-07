<?php

declare(strict_types=1);

namespace Drumeo\Video\Http;

final class Request
{
    /** @param array<string,string> $query */
    /** @param array<string,string> $headers */
    /** @param array<string,mixed> $json */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly array $json,
        public readonly string $rawBody,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $query = [];
        parse_str((string) (parse_url($uri, PHP_URL_QUERY) ?? ''), $query);

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['AUTHORIZATION']) && !isset($headers['authorization'])) {
            $headers['authorization'] = (string) $_SERVER['AUTHORIZATION'];
        }

        $raw = file_get_contents('php://input') ?: '';
        $json = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }

        return new self($method, $path, self::stringify($query), $headers, $json, $raw);
    }

    public function header(string $name): ?string
    {
        $key = strtolower($name);
        return $this->headers[$key] ?? null;
    }

    public function query(string $name, ?string $default = null): ?string
    {
        return $this->query[$name] ?? $default;
    }

    public function json(string $name, mixed $default = null): mixed
    {
        return $this->json[$name] ?? $default;
    }

    /** @param array<mixed> $in @return array<string,string> */
    private static function stringify(array $in): array
    {
        $out = [];
        foreach ($in as $k => $v) {
            if (is_string($k) && (is_string($v) || is_numeric($v))) {
                $out[$k] = (string) $v;
            }
        }
        return $out;
    }
}
