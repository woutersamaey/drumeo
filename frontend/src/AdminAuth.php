<?php

declare(strict_types=1);

namespace Drumeo\App;

/**
 * Password gate for Beheer (/admin). Profiles stay password-free; only admin
 * needs this. The unlock cookie is "<exp>.<hmac>" signed with ADMIN_PASSWORD,
 * so changing the password invalidates every issued cookie.
 */
final class AdminAuth
{
    /** The profile that sees the Beheer link even while admin is locked. */
    public const ADMIN_SLUG = 'wouter';
    public const COOKIE_TTL = 365 * 86400;
    public const MAX_FAILURES = 10;
    public const FAILURE_WINDOW = 900;
    public const FAILURE_KEY = 'admin:login-failures';

    public function __construct(
        private readonly string $password,
        private readonly RedisCache $cache,
    ) {
    }

    public function enabled(): bool
    {
        return $this->password !== '';
    }

    public function unlocked(mixed $cookie, ?int $now = null): bool
    {
        return self::verify(is_string($cookie) ? $cookie : null, $this->password, $now ?? time());
    }

    /**
     * Check a login attempt. The failure counter is global (one admin) and is
     * skipped when Redis is unavailable.
     *
     * @return array{0:int,1:array<string,mixed>} HTTP status and JSON body
     */
    public function login(string $given): array
    {
        if (!$this->enabled()) {
            return [503, ['error' => 'ADMIN_PASSWORD is niet ingesteld']];
        }
        $failures = $this->cache->getInt(self::FAILURE_KEY);
        if ($failures !== null && $failures >= self::MAX_FAILURES) {
            return [429, ['error' => 'Te veel pogingen, probeer later opnieuw']];
        }
        if (!self::passwordMatches($this->password, $given)) {
            $this->cache->incr(self::FAILURE_KEY, self::FAILURE_WINDOW);
            return [401, ['error' => 'Onjuist wachtwoord']];
        }
        $this->cache->del(self::FAILURE_KEY);
        return [200, ['ok' => true]];
    }

    public function cookieValue(int $now): string
    {
        return self::sign($now + self::COOKIE_TTL, $this->password);
    }

    public static function sign(int $exp, string $password): string
    {
        return $exp . '.' . self::mac($exp, $password);
    }

    public static function verify(?string $cookie, string $password, int $now): bool
    {
        if ($password === '' || $cookie === null) {
            return false;
        }
        if (preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/', $cookie, $m) !== 1) {
            return false;
        }
        $exp = (int) $m[1];
        if ($exp <= $now) {
            return false;
        }
        return hash_equals(self::mac($exp, $password), $m[2]);
    }

    public static function passwordMatches(string $expected, string $given): bool
    {
        if ($expected === '') {
            return false;
        }
        // Compare fixed-length digests so the comparison does not leak the length.
        return hash_equals(hash('sha256', $expected), hash('sha256', $given));
    }

    /**
     * Whether the Beheer link shows: always for the admin profile, and for anyone
     * once admin is unlocked on this device.
     *
     * @param array<string,mixed>|null $profile
     */
    public static function showsLink(?array $profile, bool $unlocked): bool
    {
        return $unlocked || (is_array($profile) && ($profile['slug'] ?? '') === self::ADMIN_SLUG);
    }

    private static function mac(int $exp, string $password): string
    {
        return hash_hmac('sha256', 'drumeo-admin|' . $exp, $password);
    }
}
