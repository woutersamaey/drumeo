<?php

declare(strict_types=1);

namespace Drumeo\Video\Http;

use Drumeo\Video\Config;

final class Auth
{
    public function __construct(private readonly Config $config)
    {
    }

    public function check(Request $request): ?Response
    {
        $token = $this->config->apiToken;
        if ($token === '') {
            return null;
        }
        $header = $request->header('authorization') ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m) && hash_equals($token, trim($m[1]))) {
            return null;
        }
        return Response::unauthorized();
    }

    /**
     * Admin routes stay closed unless ADMIN_TOKEN is set and the caller presents it.
     * The browser never gets this header; the frontend adds it server-side.
     */
    public function requireAdmin(Request $request): ?Response
    {
        $token = $this->config->adminToken;
        if ($token === '') {
            return Response::json(401, ['error' => 'admin disabled']);
        }
        $given = (string) ($request->header('x-drumeo-admin') ?? '');
        if ($given === '' || !hash_equals($token, $given)) {
            return Response::unauthorized();
        }
        return null;
    }
}
