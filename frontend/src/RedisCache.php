<?php

declare(strict_types=1);

namespace Drumeo\App;

use Predis\Client;
use Throwable;

final class RedisCache
{
    private ?Client $client = null;
    private bool $disabled = false;

    public function __construct(private readonly Config $config)
    {
    }

    public function get(string $key): mixed
    {
        try {
            $raw = $this->client()?->get($key);
            if (!is_string($raw) || $raw === '') {
                return null;
            }
            return json_decode($raw, true);
        } catch (Throwable) {
            $this->disabled = true;
            return null;
        }
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        try {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return;
            }
            $this->client()?->setex($key, $ttl, $json);
        } catch (Throwable) {
            $this->disabled = true;
        }
    }

    public function del(string $key): void
    {
        try {
            $this->client()?->del([$key]);
        } catch (Throwable) {
            $this->disabled = true;
        }
    }

    public function ping(): bool
    {
        try {
            return $this->client()?->ping() !== null;
        } catch (Throwable) {
            return false;
        }
    }

    private function client(): ?Client
    {
        if ($this->disabled) {
            return null;
        }
        if ($this->client instanceof Client) {
            return $this->client;
        }
        try {
            $this->client = new Client([
                'scheme' => 'tcp',
                'host' => $this->config->redisHost,
                'port' => $this->config->redisPort,
                'timeout' => 0.4,
            ]);
            $this->client->connect();
            return $this->client;
        } catch (Throwable) {
            $this->disabled = true;
            return null;
        }
    }
}
