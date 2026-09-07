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
}
