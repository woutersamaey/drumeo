<?php

declare(strict_types=1);

namespace Drumeo\App;

final class Http
{
    /** @var array<string,mixed>|null decoded JSON request body */
    private ?array $bodyCache = null;

    public function __construct(
        private readonly Config $config,
        private readonly Catalog $catalog,
        private readonly Progress $progress,
        private readonly Schema $schema,
        private readonly RedisCache $cache,
        private readonly Database $db,
        private readonly ImageScaler $scaler,
        private readonly AdminDashboard $admin,
        private readonly AdminAuth $auth,
    ) {
    }

    /** Front controller. Nothing escapes as a PHP fatal; details go to the error log. */
    public static function main(): void
    {
        try {
            self::boot()->run();
        } catch (\Throwable $e) {
            self::serverError($e);
        }
    }

    /** Wires the services. No I/O here: the DB, Redis and catalog are touched only by routes that need them. */
    public static function boot(): self
    {
        $config = Config::fromEnv();
        $cache = new RedisCache($config);
        $db = new Database($config);
        $catalog = new Catalog($config, $cache);
        $progress = new Progress($db, $catalog);
        $admin = new AdminDashboard(
            $config,
            new VideoAdminClient($config->videoApiUrl, $config->adminToken, $config->apiToken),
            $catalog,
            $progress,
            $cache,
            $db,
        );
        return new self(
            $config,
            $catalog,
            $progress,
            new Schema($db, $catalog),
            $cache,
            $db,
            ImageScaler::fromThumbs($config->imageCacheDir, $config->thumbsDir),
            $admin,
            new AdminAuth($config->adminPassword, $cache),
        );
    }

    public function run(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = self::requestPath();
        if (str_starts_with($path, '/img/')) {
            $this->sendVariant($path);
            return;
        }
        if (str_starts_with($path, '/app/')) {
            $this->api($method, $path);
            return;
        }
        $this->shell();
    }

    private function api(string $method, string $path): void
    {
        // Routes without the database.
        if ($path === '/app/health') {
            $this->health();
            return;
        }
        if ($path === '/app/notation.pdf') {
            $this->notation();
            return;
        }
        if ($path === '/app/admin/login' && $method === 'POST') {
            $this->adminLogin();
            return;
        }
        if ($path === '/app/admin/logout' && $method === 'POST') {
            $this->setAdminCookie('', time() - 3600);
            $this->json(200, ['ok' => true]);
            return;
        }

        $this->schema->migrate();

        if (str_starts_with($path, '/app/admin/')) {
            $this->adminApi($method, $path);
            return;
        }

        if ($path === '/app/profile' && $method === 'POST') {
            $profile = $this->progress->profileBySlug(self::str($this->body()['slug'] ?? ''));
            if (!$profile) {
                $this->json(400, ['error' => 'unknown profile']);
                return;
            }
            $this->setNamedCookie($this->config->cookieName, $profile['slug']);
            $this->clearMateCookie();
            $this->json(200, ['profile' => $profile]);
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

        if ($path === '/app/mate' && $method === 'POST') {
            $slug = trim(self::str($this->body()['slug'] ?? ''));
            if ($slug === '' || $slug === $profile['slug']) {
                $this->clearMateCookie();
                $this->json(200, ['mate' => null]);
                return;
            }
            $mate = $this->progress->profileBySlug($slug);
            if (!$mate) {
                $this->json(400, ['error' => 'unknown profile']);
                return;
            }
            $this->setNamedCookie($this->config->cookieMateName, $mate['slug']);
            $this->json(200, ['mate' => $mate]);
            return;
        }

        if (preg_match('#^/app/lesson/(\d+)$#', $path, $m) === 1 && $method === 'GET') {
            $lesson = $this->visibleLesson($profile, (int) $m[1]);
            if ($lesson === null) {
                $this->json(404, ['error' => 'not found']);
                return;
            }
            $this->json(200, $lesson);
            return;
        }

        if ($path === '/app/progress' && $method === 'POST') {
            $this->saveProgress($profile);
            return;
        }

        if ($path === '/app/reset' && $method === 'POST') {
            $lessonId = (int) self::num($this->body()['lessonId'] ?? 0);
            if ($this->visibleLesson($profile, $lessonId) === null) {
                $this->json(404, ['error' => 'unknown lesson']);
                return;
            }
            foreach ($this->sessionProfileIds($profile) as $sid) {
                $this->progress->resetLesson($sid, $lessonId);
            }
            $this->json(200, ['ok' => true]);
            return;
        }

        if ($path === '/app/rating' && $method === 'POST') {
            $body = $this->body();
            $lessonId = (int) self::num($body['lessonId'] ?? 0);
            $score = (int) self::num($body['score'] ?? 0);
            if ($score < 1 || $score > 4) {
                $this->json(400, ['error' => 'invalid rating']);
                return;
            }
            if ($this->visibleLesson($profile, $lessonId) === null) {
                $this->json(404, ['error' => 'unknown lesson']);
                return;
            }
            $actor = $this->ratingActor($profile, self::str($body['slug'] ?? ''));
            if ($actor === null) {
                $this->json(403, ['error' => 'not in session']);
                return;
            }
            $this->progress->addRating((int) $actor['id'], $lessonId, $score);
            $this->json(200, ['ok' => true]);
            return;
        }

        if ($path === '/app/audio' && $method === 'POST') {
            $idx = $this->progress->setAudio($pid, (int) self::num($this->body()['audioIndex'] ?? 1, 1.0));
            $this->json(200, [
                'ok' => true,
                'audioIndex' => $idx,
                'language' => $idx === 1 ? 'nl' : 'en',
            ]);
            return;
        }

        if ($path === '/app/path-view' && $method === 'POST') {
            $view = $this->progress->setPathView($pid, self::str($this->body()['view'] ?? 'order'));
            $this->json(200, ['ok' => true, 'pathView' => $view]);
            return;
        }

        if ($path === '/app/stats' && $method === 'GET') {
            $this->json(200, $this->progress->stats($pid));
            return;
        }

        if ($path === '/app/client-log' && $method === 'POST') {
            $body = $this->body();
            $event = strtolower(self::str($body['event'] ?? 'event'));
            $event = preg_replace('/[^a-z0-9_-]/', '', $event) ?: 'event';
            unset($body['event']);
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($json)) {
                $json = '{}';
            }
            if (strlen($json) > 1500) {
                $json = mb_strcut($json, 0, 1500, 'UTF-8') . '…';
            }
            error_log('[drumeo] ' . $event . ' profile=' . $profile['slug'] . ' ' . $json);
            $this->json(200, ['ok' => true]);
            return;
        }

        if ($path === '/app/note' && $method === 'POST') {
            $body = $this->body();
            $lessonId = (int) self::num($body['lessonId'] ?? 0);
            if ($this->visibleLesson($profile, $lessonId) === null) {
                $this->json(404, ['error' => 'unknown lesson']);
                return;
            }
            $saved = $this->progress->saveNote($pid, $lessonId, self::str($body['body'] ?? ''));
            $this->json(200, ['ok' => true, 'body' => $saved]);
            return;
        }

        $this->json(404, ['error' => 'unknown endpoint']);
    }

    private function health(): void
    {
        $redis = $this->cache->ping();
        $db = false;
        try {
            $this->db->pdo()->query('SELECT 1');
            $db = true;
        } catch (\Throwable $e) {
            error_log('[drumeo] health: mysql ' . $e->getMessage());
        }
        $this->json($db ? 200 : 503, ['ok' => $db, 'redis' => $redis, 'mysql' => $db]);
    }

    private function notation(): void
    {
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
        header('Content-Length: ' . (string) filesize($file));
        readfile($file);
    }

    /** @param array<string,mixed> $profile */
    private function saveProgress(array $profile): void
    {
        $body = $this->body();
        $lessonId = (int) self::num($body['lessonId'] ?? 0);
        $lesson = $this->visibleLesson($profile, $lessonId);
        if ($lesson === null) {
            $this->json(404, ['error' => 'unknown lesson']);
            return;
        }
        // The catalog decides the video and the length; the client only refines
        // the length within a small tolerance.
        $duration = Progress::clampDuration(self::num($body['duration'] ?? 0), (int) ($lesson['seconds'] ?? 0));
        $position = Progress::clampPosition(self::num($body['position'] ?? 0), $duration);
        $buckets = is_array($body['playedBuckets'] ?? null)
            ? array_slice(array_values($body['playedBuckets']), 0, Progress::bucketCap($duration))
            : [];
        $playedDelta = self::num($body['playedDelta'] ?? 0);
        $vimeoId = (string) $lesson['vimeoId'];

        $pid = (int) $profile['id'];
        $result = null;
        foreach ($this->sessionProfileIds($profile) as $sid) {
            $saved = $this->progress->saveProgress($sid, $lessonId, $vimeoId, $position, $duration, $playedDelta, $buckets);
            if ($sid === $pid) {
                $result = $saved;
            }
        }
        $this->json(200, $result ?? ['watched' => false, 'position' => $position]);
    }

    private function adminLogin(): void
    {
        [$status, $body] = $this->auth->login(self::str($this->body()['password'] ?? ''));
        if ($status === 200) {
            $now = time();
            $this->setAdminCookie($this->auth->cookieValue($now), $now + AdminAuth::COOKIE_TTL);
            error_log('[drumeo] admin login ok');
        } elseif ($status === 401 || $status === 429) {
            error_log('[drumeo] admin login refused status=' . $status);
        }
        header('Cache-Control: no-store');
        $this->json($status, $body);
    }

    private function adminApi(string $method, string $path): void
    {
        if (!$this->adminUnlocked()) {
            $this->json(401, ['error' => 'login', 'login' => true]);
            return;
        }
        header('Cache-Control: no-store');

        if ($path === '/app/admin/overview' && $method === 'GET') {
            $this->json(200, $this->admin->overview(($_GET['fresh'] ?? '') === '1'));
            return;
        }

        if ($path === '/app/admin/cleanup' && $method === 'POST') {
            if (($this->body()['confirm'] ?? false) !== true) {
                $this->json(400, ['error' => 'bevestig de opruiming']);
                return;
            }
            try {
                $result = $this->admin->cleanup();
            } catch (\Throwable $e) {
                error_log('[drumeo] admin cleanup failed: ' . $e->getMessage());
                $this->json(502, ['error' => $e->getMessage()]);
                return;
            }
            $count = (int) ($result['count'] ?? 0);
            $bytes = (int) ($result['bytes'] ?? 0);
            $who = $this->currentProfile()['slug'] ?? '-';
            error_log('[drumeo] admin cleanup profile=' . $who . ' count=' . $count . ' bytes=' . $bytes);
            $this->json(200, [
                'dryRun' => (bool) ($result['dryRun'] ?? false),
                'count' => $count,
                'bytes' => $bytes,
            ]);
            return;
        }

        $this->json(404, ['error' => 'unknown endpoint']);
    }

    /** @param array<string,mixed>|null $profile */
    private function bootstrap(?array $profile): array
    {
        $all = $this->catalog->all();
        $available = $this->catalog->availableVideos();
        $adminUnlocked = $this->adminUnlocked();
        $payload = [
            'profiles' => $this->progress->profiles(),
            'profile' => $profile,
            'mate' => $this->currentMate($profile),
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
            'loose' => [],
            'admin' => AdminAuth::showsLink($profile, $adminUnlocked),
            'adminUnlocked' => $adminUnlocked,
            'adminLogin' => $this->auth->enabled(),
        ];
        if ($profile) {
            $snap = $this->progress->snapshot((int) $profile['id']);
            $progressByLesson = $snap['progress'];
            $hideFuture = (bool) ($profile['hideFuture'] ?? true);
            $visibleIds = Progress::visibleIds($all['order'], $progressByLesson, $hideFuture);
            if ($hideFuture) {
                $filtered = Progress::filterCatalogData($all, $visibleIds);
                $payload['intro'] = $filtered['intro'];
                $payload['paths'] = $filtered['paths'];
                $payload['order'] = $filtered['order'];
            }
            $payload['progress'] = $progressByLesson ?: new \stdClass();
            $payload['latestScore'] = $snap['latestScore'] ?: new \stdClass();
            $payload['notes'] = $snap['notes'] ?: new \stdClass();
            $payload['lastAudioIndex'] = $snap['lastAudioIndex'];
            $payload['language'] = $snap['lastAudioIndex'] === 1 ? 'nl' : 'en';
            $payload['pathView'] = $snap['pathView'];
            $payload['resume'] = $this->progress->resume($snap);
            $practice = $this->progress->practice($snap);
            if ($hideFuture) {
                $allow = array_fill_keys($visibleIds, true);
                $practice = array_values(array_filter(
                    $practice,
                    static fn (array $row): bool => isset($allow[(int) $row['id']]),
                ));
            }
            $payload['practice'] = $practice;
            $payload['week'] = $this->progress->week((int) $profile['id'], $snap);
            $payload['loose'] = is_array($all['loose'] ?? null) ? $all['loose'] : [];
        } else {
            $payload['intro'] = null;
            $payload['paths'] = [];
            $payload['order'] = [];
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
        if (($lesson['role'] ?? '') === 'loose') {
            return $lesson;
        }
        if (!($profile['hideFuture'] ?? true)) {
            return $lesson;
        }
        $ids = Progress::visibleIds(
            $this->catalog->orderedLessons(),
            $this->progress->progressMap((int) $profile['id']),
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

    /** @param array<string,mixed>|null $profile */
    private function currentMate(?array $profile): ?array
    {
        if (!$profile) {
            return null;
        }
        $slug = $_COOKIE[$this->config->cookieMateName] ?? '';
        if (!is_string($slug) || $slug === '' || $slug === $profile['slug']) {
            return null;
        }
        return $this->progress->profileBySlug($slug);
    }

    /**
     * @param array<string,mixed> $profile
     * @return list<int>
     */
    private function sessionProfileIds(array $profile): array
    {
        $ids = [(int) $profile['id']];
        $mate = $this->currentMate($profile);
        if ($mate) {
            $ids[] = (int) $mate['id'];
        }
        return $ids;
    }

    /**
     * @param array<string,mixed> $profile
     * @return array<string,mixed>|null
     */
    private function ratingActor(array $profile, string $slug): ?array
    {
        if ($slug === '' || $slug === $profile['slug']) {
            return $profile;
        }
        $mate = $this->currentMate($profile);
        if ($mate && $mate['slug'] === $slug) {
            return $mate;
        }
        return null;
    }

    private function adminUnlocked(): bool
    {
        return $this->auth->unlocked($_COOKIE[$this->config->cookieAdminName] ?? null);
    }

    private function setAdminCookie(string $value, int $expires): void
    {
        setcookie($this->config->cookieAdminName, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private function clearMateCookie(): void
    {
        $this->setNamedCookie($this->config->cookieMateName, '', true);
    }

    private function setNamedCookie(string $name, string $value, bool $clear = false): void
    {
        setcookie($name, $clear ? '' : $value, [
            'expires' => $clear ? time() - 3600 : time() + 60 * 60 * 24 * 400,
            'path' => '/',
            'secure' => false,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    private static function isHttps(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        $proto = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0];
        return strtolower(trim($proto)) === 'https';
    }

    private function sendVariant(string $path): void
    {
        if (preg_match('#^/img/([a-zA-Z0-9_-]+)/(\d+)\.(webp|jpg|jpeg)$#', $path, $m) !== 1) {
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
        // ensure() may fall back to JPEG, so the file decides the type.
        $mime = str_ends_with($file, '.webp') ? 'image/webp' : 'image/jpeg';
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
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /** @return array<string,mixed> */
    private function body(): array
    {
        if ($this->bodyCache === null) {
            $data = json_decode(file_get_contents('php://input') ?: '', true);
            $this->bodyCache = is_array($data) ? $data : [];
        }
        return $this->bodyCache;
    }

    /** A JSON number or numeric string; anything else (arrays, INF, junk) is the default. */
    private static function num(mixed $value, float $default = 0.0): float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return $default;
        }
        $f = (float) $value;
        return is_finite($f) ? $f : $default;
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');
    }

    private static function requestPath(): string
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/';
    }

    private static function serverError(\Throwable $e): void
    {
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? '-');
        $path = self::requestPath();
        error_log(sprintf(
            '[drumeo] %s %s failed: %s: %s at %s:%d',
            $method,
            $path,
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
        ));
        if (headers_sent()) {
            return;
        }
        http_response_code(500);
        header('Cache-Control: no-store');
        if (str_starts_with($path, '/app/')) {
            header('Content-Type: application/json; charset=utf-8');
            echo '{"error":"Serverfout"}';
        } elseif (!str_starts_with($path, '/img/')) {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Server error';
        }
    }
}
