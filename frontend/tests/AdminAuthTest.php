<?php

declare(strict_types=1);

namespace Drumeo\App\Tests;

use Drumeo\App\AdminAuth;
use Drumeo\App\RedisCache;

require __DIR__ . '/helpers.php';

$now = 1_800_000_000;
$exp = $now + AdminAuth::COOKIE_TTL;
$cookie = AdminAuth::sign($exp, 'geheim');

check(preg_match('/^\d+\.[0-9a-f]{64}$/', $cookie) === 1, 'cookie is "<exp>.<hmac>", got ' . $cookie);
check(str_starts_with($cookie, $exp . '.'), 'cookie starts with exp');
check(substr($cookie, strlen((string) $exp) + 1) === hash_hmac('sha256', 'drumeo-admin|' . $exp, 'geheim'), 'hmac matches the contract');
ok('sign');

check(AdminAuth::verify($cookie, 'geheim', $now), 'valid cookie verifies');
check(!AdminAuth::verify($cookie, 'anders', $now), 'other password rejects');
check(!AdminAuth::verify($cookie, '', $now), 'empty password never verifies');
check(!AdminAuth::verify($cookie, 'geheim', $exp), 'expired at exp');
check(!AdminAuth::verify($cookie, 'geheim', $exp + 10), 'expired after exp');
check(!AdminAuth::verify(($exp + 999) . substr($cookie, strlen((string) $exp)), 'geheim', $now), 'extended exp rejects');
check(!AdminAuth::verify(substr($cookie, 0, -1) . (str_ends_with($cookie, 'a') ? 'b' : 'a'), 'geheim', $now), 'tampered hmac rejects');
check(!AdminAuth::verify(null, 'geheim', $now), 'missing cookie');
check(!AdminAuth::verify('', 'geheim', $now), 'empty cookie');
check(!AdminAuth::verify('garbage', 'geheim', $now), 'malformed cookie');
check(!AdminAuth::verify(strtoupper($cookie), 'geheim', $now), 'uppercase hex is not the issued value');
ok('verify');

check(AdminAuth::passwordMatches('geheim', 'geheim'), 'same password');
check(!AdminAuth::passwordMatches('geheim', 'geheim '), 'different password');
check(!AdminAuth::passwordMatches('', ''), 'empty password never matches');
ok('passwordMatches');

check(AdminAuth::showsLink(['slug' => 'wouter'], false), 'wouter sees Beheer while locked');
check(!AdminAuth::showsLink(['slug' => 'vic'], false), 'vic does not see Beheer while locked');
check(AdminAuth::showsLink(['slug' => 'vic'], true), 'anyone sees Beheer once unlocked');
check(AdminAuth::showsLink(null, true), 'unlocked without a profile');
check(!AdminAuth::showsLink(null, false), 'locked without a profile');
ok('showsLink');

// Redis is unreachable here, so the throttle is skipped (by design).
$cache = new RedisCache(offlineConfig());
$disabled = new AdminAuth('', $cache);
check(!$disabled->enabled(), 'empty ADMIN_PASSWORD disables login');
check($disabled->login('x') === [503, ['error' => 'ADMIN_PASSWORD is niet ingesteld']], 'disabled login is 503');
check(!$disabled->unlocked($disabled->cookieValue($now), $now), 'no cookie unlocks a disabled admin');

$auth = new AdminAuth('geheim', $cache);
check($auth->login('fout') === [401, ['error' => 'Onjuist wachtwoord']], 'wrong password is 401');
for ($i = 0; $i < AdminAuth::MAX_FAILURES + 2; $i++) {
    $auth->login('fout');
}
check($auth->login('geheim') === [200, ['ok' => true]], 'right password is 200 (no throttle without Redis)');
check($auth->unlocked($auth->cookieValue($now), $now + 60), 'issued cookie unlocks');
check(!$auth->unlocked($auth->cookieValue($now), $now + AdminAuth::COOKIE_TTL), 'issued cookie expires after a year');
check(!$auth->unlocked(['array'], $now), 'non-string cookie is locked');
ok('login flow');

fwrite(STDOUT, "all admin-auth tests passed\n");
