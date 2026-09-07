<?php

declare(strict_types=1);

namespace Drumeo\App;

final class Http
{
    public function __construct(
        private readonly Config $config,
        private readonly Catalog $catalog,
        private readonly Progress $progress,
        private readonly RedisCache $cache,
        private readonly Database $db,
        private readonly ImageScaler $scaler,
    ) {
    }

    public static function boot(): self
    {
        $config = Config::fromEnv();
        $cache = new RedisCache($config);
        $db = new Database($config);
        $catalog = new Catalog($config, $cache);
        $progress = new Progress($db, $catalog);
        $scaler = ImageScaler::fromCatalog($catalog, $config->imageCacheDir);
        return new self($config, $catalog, $progress, $cache, $db, $scaler);
    }

    public function run(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        if ($method === 'OPTIONS') {
            $this->cors();
            http_response_code(204);
            return;
        }

        try {
            if (str_starts_with($path, '/img/')) {
                $this->sendVariant($path);
                return;
            }
            if (str_starts_with($path, '/app/')) {
                $this->api($method, $path);
                return;
            }
            $this->shell();
        } catch (\Throwable $e) {
            if (str_starts_with($path, '/app/')) {
                $this->json(500, ['error' => $e->getMessage()]);
                return;
            }
            http_response_code(500);
            if (!str_starts_with($path, '/img/')) {
                echo 'Server error';
            }
        }
    }

    private function api(string $method, string $path): void
    {
        $this->cors();
        if ($path === '/app/health') {
            $ok = true;
            $redis = $this->cache->ping();
            $db = false;
            try {
                $this->db->pdo()->query('SELECT 1');
                $db = true;
            } catch (\Throwable) {
                $ok = false;
            }
            $this->json($ok ? 200 : 503, ['ok' => $ok, 'redis' => $redis, 'mysql' => $db]);
            return;
        }

        if ($path === '/app/profiles' && $method === 'GET') {
            $this->json(200, ['profiles' => $this->progress->profiles()]);
            return;
        }

        if ($path === '/app/profile' && $method === 'POST') {
            $body = $this->body();
            $slug = (string) ($body['slug'] ?? '');
            $profile = $this->progress->profileBySlug($slug);
            if (!$profile) {
                $this->json(400, ['error' => 'unknown profile']);
                return;
            }
            $this->setProfileCookie($profile['slug']);
            $this->json(200, ['profile' => $profile]);
            return;
        }

        if ($path === '/app/media' || preg_match('#^/app/thumb/([a-zA-Z0-9_-]+)\.(jpg|jpeg|png)$#', $path, $m)) {
            $id = $m[1] ?? '';
            $this->sendThumb($id);
            return;
        }
        if (preg_match('#^/app/thumb/([a-zA-Z0-9_-]+)$#', $path, $m)) {
            $this->sendThumb($m[1]);
            return;
        }
        if ($path === '/app/notation.pdf') {
            $file = $this->config->notationPath;
            if (!is_file($file)) {
                $alt = dirname(__DIR__, 2) . '/drumeo-method-notation-key.pdf';
                $file = is_file($alt) ? $alt : $file;
            }
            if (!is_file($file)) {
                http_response_code(404);
                return;
            }
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="drumeo-method-notation-key.pdf"');
            header('Cache-Control: public, max-age=86400');
            readfile($file);
            return;
        }

        $profile = $this->currentProfile();

        if ($path === '/app/bootstrap' && $method === 'GET') {
            $this->json(200, $this->bootstrap($profile));
            return;
        }

        if ($path === '/app/catalog' && $method === 'GET') {
            $all = $this->catalog->all();
            $this->json(200, [
                'intro' => $all['intro'],
                'paths' => $all['paths'],
                'available' => array_keys($this->catalog->availableVideos()),
            ]);
            return;
        }

        if (preg_match('#^/app/path/([a-zA-Z0-9_-]+)$#', $path, $m) && $method === 'GET') {
            $p = $this->catalog->path($m[1]);
            if (!$p) {
                $this->json(404, ['error' => 'not found']);
                return;
            }
            $this->json(200, $p);
            return;
        }

        if (preg_match('#^/app/lesson/(\d+)$#', $path, $m) && $method === 'GET') {
            $lesson = $this->catalog->lesson((int) $m[1]);
            if (!$lesson) {
                $this->json(404, ['error' => 'not found']);
                return;
            }
            $this->json(200, $lesson);
            return;
        }

        if ($profile === null) {
            $this->json(401, ['error' => 'pick a profile']);
            return;
        }
        $pid = (int) $profile['id'];

        if ($path === '/app/progress' && $method === 'POST') {
            $body = $this->body();
            $lessonId = (int) ($body['lessonId'] ?? 0);
            $lesson = $this->catalog->lesson($lessonId);
            if (!$lesson) {
                $this->json(404, ['error' => 'unknown lesson']);
                return;
            }
            $result = $this->progress->saveProgress(
                $pid,
                $lessonId,
                (string) ($body['vimeoId'] ?? $lesson['vimeoId']),
                (float) ($body['position'] ?? 0),
                (float) ($body['duration'] ?? $lesson['seconds']),
            );
            $this->json(200, $result);
            return;
        }

        if ($path === '/app/watched' && $method === 'POST') {
            $body = $this->body();
            $lessonId = (int) ($body['lessonId'] ?? 0);
            $lesson = $this->catalog->lesson($lessonId);
            if (!$lesson) {
                $this->json(404, ['error' => 'unknown lesson']);
                return;
            }
            $this->progress->markWatched($pid, $lessonId, $lesson['vimeoId'], (float) ($body['duration'] ?? $lesson['seconds']));
            $this->json(200, ['ok' => true]);
            return;
        }

        if ($path === '/app/reset' && $method === 'POST') {
            $body = $this->body();
            $this->progress->resetLesson($pid, (int) ($body['lessonId'] ?? 0));
            $this->json(200, ['ok' => true]);
            return;
        }

        if ($path === '/app/rating' && $method === 'POST') {
            $body = $this->body();
            $lessonId = (int) ($body['lessonId'] ?? 0);
            $score = (int) ($body['score'] ?? 0);
            if ($this->catalog->lesson($lessonId) === null || $score < 1 || $score > 4) {
                $this->json(400, ['error' => 'invalid rating']);
                return;
            }
            $this->progress->addRating($pid, $lessonId, $score);
            $this->json(200, ['ok' => true]);
            return;
        }

        if ($path === '/app/audio' && $method === 'POST') {
            $idx = $this->progress->setAudio($pid, (int) ($this->body()['audioIndex'] ?? 1));
            $this->json(200, [
                'ok' => true,
                'audioIndex' => $idx,
                'language' => $idx === 1 ? 'nl' : 'en',
            ]);
            return;
        }

        if ($path === '/app/compat' && $method === 'POST') {
            $this->progress->setCompat($pid, (bool) ($this->body()['on'] ?? false));
            $this->json(200, ['ok' => true]);
            return;
        }

        $this->json(404, ['error' => 'unknown endpoint']);
    }

    /** @param array<string,mixed>|null $profile */
    private function bootstrap(?array $profile): array
    {
        $all = $this->catalog->all();
        $available = $this->catalog->availableVideos();
        $payload = [
            'profiles' => $this->progress->profiles(),
            'profile' => $profile,
            'intro' => $all['intro'],
            'paths' => $all['paths'],
            'order' => $all['order'],
            'available' => array_map('strval', array_keys($available)),
            'resume' => null,
            'practice' => [],
            'progress' => new \stdClass(),
            'latestScore' => new \stdClass(),
            'lastAudioIndex' => 1,
            'language' => 'nl',
            'compatMode' => false,
        ];
        if ($profile) {
            $snap = $this->progress->snapshot((int) $profile['id']);
            $payload['progress'] = $snap['progress'];
            $payload['latestScore'] = $snap['latestScore'];
            $payload['lastAudioIndex'] = $snap['lastAudioIndex'];
            $payload['language'] = $snap['lastAudioIndex'] === 1 ? 'nl' : 'en';
            $payload['compatMode'] = $snap['compatMode'];
            $payload['resume'] = $this->progress->resume((int) $profile['id'], $snap);
            $payload['practice'] = $this->progress->practice($snap);
        }
        return $payload;
    }

    private function currentProfile(): ?array
    {
        $slug = $_COOKIE[$this->config->cookieName] ?? '';
        if (!is_string($slug) || $slug === '') {
            return null;
        }
        return $this->progress->profileBySlug($slug);
    }

    private function setProfileCookie(string $slug): void
    {
        setcookie($this->config->cookieName, $slug, [
            'expires' => time() + 60 * 60 * 24 * 400,
            'path' => '/',
            'secure' => false,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    private function sendThumb(string $id): void
    {
        $this->sendVariant('/img/' . $id . '/640.jpg');
    }

    private function sendVariant(string $path): void
    {
        if (!preg_match('#^/img/([a-zA-Z0-9_-]+)/(\d+)\.(webp|jpg|jpeg)$#', $path, $m)) {
            http_response_code(404);
            return;
        }
        $id = $m[1];
        $width = (int) $m[2];
        $format = $m[3] === 'jpeg' ? 'jpg' : $m[3];
        if (!ImageScaler::isAllowedWidth($width) || !ImageScaler::isAllowedFormat($format)) {
            http_response_code(404);
            return;
        }
        $file = $this->scaler->ensure($id, $width, $format);
        if ($file === null || !is_file($file)) {
            $this->sendPlaceholder($width, $format);
            return;
        }
        $mime = $format === 'webp' ? 'image/webp' : 'image/jpeg';
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=2592000, immutable');
        header('Content-Length: ' . (string) filesize($file));
        readfile($file);
    }

    private function sendPlaceholder(int $width, string $format): void
    {
        $width = max(160, min($width, 1920));
        $height = (int) round($width * 9 / 16);
        header('Cache-Control: public, max-age=60');
        if (!function_exists('imagecreatetruecolor')) {
            header('Content-Type: image/svg+xml');
            echo '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '"><rect fill="#182234" width="100%" height="100%"/></svg>';
            return;
        }
        $im = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($im, 24, 34, 52);
        imagefilledrectangle($im, 0, 0, $width, $height, $bg);
        if ($format === 'webp' && function_exists('imagewebp')) {
            header('Content-Type: image/webp');
            imagewebp($im, null, 80);
        } else {
            header('Content-Type: image/jpeg');
            imagejpeg($im, null, 80);
        }
        imagedestroy($im);
    }

    private function shell(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        require dirname(__DIR__) . '/views/shell.php';
    }

    /** @param array<string,mixed> $body */
    private function json(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string,mixed> */
    private function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function cors(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    }
}
