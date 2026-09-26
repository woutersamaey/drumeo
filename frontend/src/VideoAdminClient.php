<?php

declare(strict_types=1);

namespace Drumeo\App;

/**
 * Server-side calls from the frontend to the video API.
 * The admin token stays in this process; the browser never sees it.
 */
final class VideoAdminClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $adminToken,
        private readonly string $apiToken,
    ) {
    }

    /** @return array<string,mixed> */
    public function overview(): array
    {
        return $this->request('GET', '/api/admin/overview', null, 240);
    }

    /** @return array<string,mixed> */
    public function cleanup(): array
    {
        return $this->request('POST', '/api/admin/cleanup', ['confirm' => true], 180);
    }

    /** @param array<string,mixed>|null $body @return array<string,mixed> */
    private function request(string $method, string $path, ?array $body, int $timeout): array
    {
        if ($this->adminToken === '' || preg_match('/[\r\n]/', $this->adminToken) === 1) {
            throw new \RuntimeException('ADMIN_TOKEN ontbreekt');
        }
        if (preg_match('/[\r\n]/', $this->apiToken) === 1) {
            throw new \RuntimeException('API_TOKEN is ongeldig');
        }

        $headers = [
            'Accept: application/json',
            'X-Drumeo-Admin: ' . $this->adminToken,
            'Connection: close',
        ];
        if ($this->apiToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiToken;
        }
        $content = '';
        if ($body !== null) {
            $encoded = json_encode($body, JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new \RuntimeException('JSON encode mislukt');
            }
            $content = $encoded;
            $headers[] = 'Content-Type: application/json';
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $content,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);
        $url = rtrim($this->baseUrl, '/') . $path;
        $raw = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        if ($raw === false) {
            throw new \RuntimeException('Video-API niet bereikbaar');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Video-API gaf geen JSON' . ($status > 0 ? ' (' . $status . ')' : ''));
        }
        if ($status >= 400 || $status === 0) {
            $err = $data['error'] ?? ('HTTP ' . $status);
            throw new \RuntimeException(is_string($err) ? $err : 'Video-API fout');
        }
        return $data;
    }
}
