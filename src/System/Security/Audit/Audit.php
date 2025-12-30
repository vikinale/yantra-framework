<?php
declare(strict_types=1);

namespace System\Security\Audit;

final class Audit
{
    public static function log(string $event, array $ctx = []): void
    {
        $line = json_encode([
            'ts' => date('c'),
            'channel' => 'security',
            'event' => $event,
            'ctx' => $ctx,
        ], JSON_UNESCAPED_SLASHES);

        error_log($line ?: '{"event":"audit_encode_failed"}');
    }
}

/*
Then in guard/CSRF:

use System\Security\Audit\Audit;

Audit::log('auth_denied', ['code'=>$code,'path'=>($_SERVER['REQUEST_URI'] ?? '')]);
*/