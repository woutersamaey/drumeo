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
        private readonly Coach $coach,
    ) {
    }

    public static function boot(): self
    {
        $config = Config::fromEnv();
        $cache = new RedisCache($config);
        $db = new Database($config);
        $catalog = new Catalog($config, $cache);
        $progress = new Progress($db, $catalog);
        $progress->ensureSchema();
        $coach = new Coach($db, $catalog, $config);
        $coach->ensureSchema();
        $scaler = ImageScaler::fromThumbs($config->imageCacheDir, $config->thumbsDir);
        return new self($config, $catalog, $progress, $cache, $db, $scaler, $coach);
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

        if ($profile === null) {
            $this->json(401, ['error' => 'pick a profile']);
            return;
        }
        $pid = (int) $profile['id'];

        if (preg_match('#^/app/lesson/(\d+)$#', $path, $m) && $method === 'GET') {
            $lesson = $this->visibleLesson($profile, (int) $m[1]);
            if ($lesson === null) {
                $this->json(404, ['error' => 'not found']);
                return;
            }
            $this->json(200, $lesson);
            return;
        }

        if ($path === '/app/progress' && $method === 'POST') {
            $body = $this->body();
            $lessonId = (int) ($body['lessonId'] ?? 0);
            $lesson = $this->visibleLesson($profile, $lessonId);
            if ($lesson === null) {
                $this->json(404, ['error' => 'unknown lesson']);
                return;
            }
            $buckets = $body['playedBuckets'] ?? [];
            if (!is_array($buckets)) {
                $buckets = [];
            }
            $result = $this->progress->saveProgress(
                $pid,
                $lessonId,
                (string) ($body['vimeoId'] ?? $lesson['vimeoId']),
                (float) ($body['position'] ?? 0),
                (float) ($body['duration'] ?? $lesson['seconds']),
                (float) ($body['playedDelta'] ?? 0),
                array_slice($buckets, 0, 400),
            );
            $this->json(200, $result);
            return;
        }

        if ($path === '/app/watched' && $method === 'POST') {
            $body = $this->body();
            $lessonId = (int) ($body['lessonId'] ?? 0);
            $lesson = $this->visibleLesson($profile, $lessonId);
            if ($lesson === null) {
                $this->json(404, ['error' => 'unknown lesson']);
                return;
            }
            $this->progress->markWatched($pid, $lessonId, $lesson['vimeoId'], (float) ($body['duration'] ?? $lesson['seconds']));
            $this->json(200, ['ok' => true]);
            return;
        }

        if ($path === '/app/reset' && $method === 'POST') {
            $body = $this->body();
            $lessonId = (int) ($body['lessonId'] ?? 0);
            if ($this->visibleLesson($profile, $lessonId) === null) {
                $this->json(404, ['error' => 'unknown lesson']);
                return;
            }
            $this->progress->resetLesson($pid, $lessonId);
            $this->json(200, ['ok' => true]);
            return;
        }

        if ($path === '/app/rating' && $method === 'POST') {
            $body = $this->body();
            $lessonId = (int) ($body['lessonId'] ?? 0);
            $score = (int) ($body['score'] ?? 0);
            if ($score < 1 || $score > 4) {
                $this->json(400, ['error' => 'invalid rating']);
                return;
            }
            if ($this->visibleLesson($profile, $lessonId) === null) {
                $this->json(404, ['error' => 'unknown lesson']);
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

        if ($path === '/app/path-view' && $method === 'POST') {
            $view = $this->progress->setPathView($pid, (string) ($this->body()['view'] ?? 'order'));
            $this->json(200, ['ok' => true, 'pathView' => $view]);
            return;
        }

        if ($path === '/app/stats' && $method === 'GET') {
            $this->json(200, $this->progress->stats($pid));
            return;
        }

        if ($path === '/app/note' && $method === 'POST') {
            $body = $this->body();
            $lessonId = (int) ($body['lessonId'] ?? 0);
            if ($this->visibleLesson($profile, $lessonId) === null) {
                $this->json(404, ['error' => 'unknown lesson']);
                return;
            }
            $saved = $this->progress->saveNote($pid, $lessonId, (string) ($body['body'] ?? ''));
            $this->json(200, ['ok' => true, 'body' => $saved]);
            return;
        }

        if ($path === '/app/coach/calibration' && $method === 'POST') {
            $body = $this->body();
            $saved = $this->coach->saveCalibration(
                $pid,
                (float) ($body['noiseRms'] ?? 0),
                (float) ($body['hitRms'] ?? 0),
                (int) ($body['hits'] ?? 0),
                (int) ($body['sampleRate'] ?? 48000),
                isset($body['latencyMs']) ? (float) $body['latencyMs'] : null,
            );
            $this->json(200, ['ok' => true, 'calibration' => $saved]);
            return;
        }

        if ($path === '/app/coach/recording/start' && $method === 'POST') {
            $body = $this->body();
            $lessonId = (int) ($body['lessonId'] ?? 0);
            $lesson = $this->visibleLesson($profile, $lessonId);
            if ($lesson === null || !$this->coach->forLesson($lesson)) {
                $this->json(404, ['error' => 'unknown lesson']);
                return;
            }
            $row = $this->coach->startRecording(
                $pid,
                $lessonId,
                (string) ($body['vimeoId'] ?? $lesson['vimeoId']),
                (float) ($body['videoOffset'] ?? 0),
            );
            $this->json(200, $row);
            return;
        }

        if (preg_match('#^/app/coach/recording/(\d+)/upload$#', $path, $m) && $method === 'POST') {
            $id = (int) $m[1];
            $file = $_FILES['audio'] ?? null;
            if (!is_array($file) || (int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
                $this->json(400, ['error' => 'missing audio']);
                return;
            }
            $mime = (string) ($file['type'] ?? 'audio/mp4');
            if ($mime === '' || $mime === 'application/octet-stream') {
                $mime = (string) ($_POST['mime'] ?? 'audio/mp4');
            }
            $duration = (float) ($_POST['duration'] ?? 0);
            $row = $this->coach->saveUpload($pid, $id, (string) $file['tmp_name'], $mime, $duration);
            if ($row === null) {
                $this->json(404, ['error' => 'unknown recording']);
                return;
            }
            $this->json(200, $row);
            return;
        }

        if ($path === '/app/coach/recordings' && $method === 'GET') {
            $this->json(200, ['recordings' => $this->coach->recordings($pid)]);
            return;
        }

        if (preg_match('#^/app/coach/recording/(\d+)/audio$#', $path, $m) && $method === 'GET') {
            $file = $this->coach->recordingFile($pid, (int) $m[1]);
            if ($file === null) {
                http_response_code(404);
                return;
            }
            header('Content-Type: ' . $file['mime']);
            header('Content-Length: ' . (string) filesize($file['path']));
            header('Cache-Control: private, max-age=3600');
            header('Accept-Ranges: bytes');
            readfile($file['path']);
            return;
        }

        if (preg_match('#^/app/coach/recording/(\d+)$#', $path, $m) && $method === 'GET') {
            $row = $this->coach->recording($pid, (int) $m[1]);
            if ($row === null) {
                $this->json(404, ['error' => 'not found']);
                return;
            }
            $this->json(200, $row);
            return;
        }

        if (preg_match('#^/app/coach/ref/(\d+)$#', $path, $m) && $method === 'GET') {
            $lesson = $this->visibleLesson($profile, (int) $m[1]);
            if ($lesson === null) {
                $this->json(404, ['error' => 'unknown lesson']);
                return;
            }
            $ref = $this->coach->teacherRef((string) $lesson['vimeoId']);
            if ($ref === null) {
                $this->json(200, ['ready' => false, 'vimeoId' => $lesson['vimeoId'], 'onsets' => []]);
                return;
            }
            $this->json(200, ['ready' => true] + $ref);
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
            'lessonTotal' => count($all['order']),
            'available' => array_map('strval', array_keys($available)),
            'resume' => null,
            'practice' => [],
            'progress' => new \stdClass(),
            'latestScore' => new \stdClass(),
            'lastAudioIndex' => 1,
            'language' => 'nl',
            'pathView' => 'order',
            'notes' => new \stdClass(),
            'coach' => ['fromLesson' => Coach::FROM_LESSON, 'calibration' => null, 'recent' => [], 'pending' => 0],
        ];
        if ($profile) {
            $snap = $this->progress->snapshot((int) $profile['id']);
            $progressByLesson = is_array($snap['progress'] ?? null) ? $snap['progress'] : [];
            $hideFuture = (bool) ($profile['hideFuture'] ?? true);
            $visibleIds = Progress::visibleIds($all['order'], $progressByLesson, $hideFuture);
            if ($hideFuture) {
                $filtered = Progress::filterCatalogData($all, $visibleIds);
                $payload['intro'] = $filtered['intro'];
                $payload['paths'] = $filtered['paths'];
                $payload['order'] = $filtered['order'];
            }
            $payload['progress'] = $snap['progress'];
            $payload['latestScore'] = $snap['latestScore'];
            $payload['notes'] = $snap['notes'] ?: new \stdClass();
            $payload['lastAudioIndex'] = $snap['lastAudioIndex'];
            $payload['language'] = $snap['lastAudioIndex'] === 1 ? 'nl' : 'en';
            $payload['pathView'] = $snap['pathView'] ?? 'order';
            $payload['resume'] = $this->progress->resume((int) $profile['id'], $snap);
            $practice = $this->progress->practice($snap);
            if ($hideFuture) {
                $allow = array_fill_keys($visibleIds, true);
                $kept = [];
                foreach ($practice as $row) {
                    if (isset($allow[(int) $row['id']])) {
                        $kept[] = $row;
                    }
                }
                $practice = $kept;
            }
            $payload['practice'] = $practice;
            $payload['week'] = $this->progress->week((int) $profile['id'], $snap);
            $payload['coach'] = $this->coach->bootstrap((int) $profile['id']);
        } else {
            $payload['intro'] = null;
            $payload['paths'] = [];
            $payload['order'] = [];
            $payload['coach'] = ['fromLesson' => Coach::FROM_LESSON, 'calibration' => null, 'recent' => [], 'pending' => 0];
        }
        return $payload;
    }

    /** @param array<string,mixed> $profile @return array<string,mixed>|null */
    private function visibleLesson(array $profile, int $lessonId): ?array
    {
        $lesson = $this->catalog->lesson($lessonId);
        if ($lesson === null) {
            return null;
        }
        if (!($profile['hideFuture'] ?? true)) {
            return $lesson;
        }
        $snap = $this->progress->snapshot((int) $profile['id']);
        $ids = Progress::visibleIds(
            $this->catalog->orderedLessons(),
            is_array($snap['progress'] ?? null) ? $snap['progress'] : [],
            true,
        );
        return in_array($lessonId, $ids, true) ? $lesson : null;
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
        $css = $this->assetUrl('/assets/app.css');
        $js = $this->assetUrl('/assets/app.js');
        require dirname(__DIR__) . '/views/shell.php';
    }

    private function assetUrl(string $rel): string
    {
        $file = dirname(__DIR__) . '/public' . $rel;
        $hash = is_file($file) ? sha1_file($file) : false;
        return $rel . '?v=' . (is_string($hash) ? $hash : '0');
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
