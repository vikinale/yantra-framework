<?php
declare(strict_types=1);

namespace System\Routing;

/**
 * RouteCompiler
 *  - Converts RouteCollector route definitions into per-method cache buckets:
 *      {cacheDir}/GET.php     => ['static'=>..., 'dynamic'=>...]
 *      {cacheDir}/POST.php    => ['static'=>..., 'dynamic'=>...]
 *      {cacheDir}/__index.php => ['p:<sha1(path)>' => ['GET'=>true,'POST'=>true]]
 *
 * Dynamic patterns supported:
 *  - /users/{id}
 *  - /users/{id:\d+}
 */
final class RouteCompiler
{
    /**
     * @param array<int, array<string,mixed>> $routes
     * @return array<string, mixed>  // keyed by METHOD + '__index'
     */
    public function compile(array $routes): array
    {
        /** @var array<string, array{static:array<string,array>, dynamic:array<int,array>}> $compiled */
        $compiled = [];

        /** @var array<string, array<string,bool>> $pathIndex */
        $pathIndex = [];

        foreach ($routes as $r) {
            $this->assertRoute($r);

            $method = strtoupper(trim((string)$r['method']));
            $path   = (string)$r['path'];

            $handler = $r['handler'];

            $middleware = $r['middleware'] ?? [];
            if (is_string($middleware)) {
                $middleware = [$middleware];
            }
            if (!is_array($middleware)) {
                $middleware = [];
            }
            $middleware = $this->normalizeMiddlewareList($middleware);

            if (!isset($compiled[$method])) {
                $compiled[$method] = ['static' => [], 'dynamic' => []];
            }

            // Dynamic?
            if (str_contains($path, '{')) {
                [$regex, $vars] = $this->toRegexWithVars($path);

                $compiled[$method]['dynamic'][] = [
                    'regex'      => $regex,
                    'vars'       => $vars,
                    'handler'    => $handler,
                    'middleware' => $middleware,
                    'pattern'    => $path, // debug/helpful for introspection
                ];
            } else {
                $compiled[$method]['static'][$path] = [
                    'handler'    => $handler,
                    'middleware' => $middleware,
                ];
            }

            // 405 index: based on exact pattern string (same key used by Router)
            $pathIndex['p:' . sha1($path)][$method] = true;
        }

        $compiled['__index'] = $pathIndex;
        return $compiled;
    }

    /**
     * Generate per-method cache files into directory:
     *  - routes/GET.php
     *  - routes/POST.php
     *  - routes/__index.php
     *
     * @param array<int, array<string,mixed>> $routes
     */
    public function compileToMethodCacheDir(array $routes, string $cacheDir, array $errors = []): void
    {
        $compiled = $this->compile($routes);

        $this->ensureDir($cacheDir);

        foreach ($compiled as $key => $bucket) {
            if ($key === '__index') {
                continue;
            }
            if (!is_string($key) || $key === '') {
                continue;
            }

            $file = rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR . strtoupper($key) . '.php';
            $this->writePhpReturnFile($file, $bucket);
        }

        $indexFile = rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR . '__index.php';
        $this->writePhpReturnFile($indexFile, $compiled['__index'] ?? []);

        // NEW: errors cache
        $errorsFile = rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR . '__errors.php';
        $this->writePhpReturnFile($errorsFile, $this->normalizeErrors($errors));
    }

    /* ---------- middleware normalization ---------- */

    /**
     * @param array<int,mixed> $mw
     * @return array<int,string>
     */
    private function normalizeMiddlewareList(array $mw): array
    {
        $mw = array_map('strval', $mw);
        $mw = array_values(array_filter($mw, static fn(string $v) => trim($v) !== ''));

        $out = [];
        $seen = [];
        foreach ($mw as $m) {
            $m = trim($m);
            if ($m === '' || isset($seen[$m])) {
                continue;
            }
            $seen[$m] = true;
            $out[] = $m;
        }

        return $out;
    }

    /**
     * @param array<int, array{handler:mixed, middleware:mixed}> $errors
     * @return array<int, array{handler:mixed, middleware:array<int,string>}>
     */
    private function normalizeErrors(array $errors): array
    {
        $out = [];

        foreach ($errors as $code => $def) {
            $code = (int)$code;

            $mw = $def['middleware'] ?? [];
            if (is_string($mw)) {
                $mw = [$mw];
            }
            if (!is_array($mw)) {
                $mw = [];
            }

            $mw = array_values(array_filter(array_map('strval', $mw), static fn($v) => trim((string)$v) !== ''));

            $out[$code] = [
                'handler'    => $def['handler'] ?? null,
                'middleware' => $mw,
            ];
        }

        return $out;
    }


    /* ---------- regex + constraints ---------- */

    /**
     * @return array{0:string,1:array<int,string>}
     */
    private function toRegexWithVars(string $pattern): array
    {
        $out = '';
        $vars = [];
        $offset = 0;

        $re = '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/';

        if (preg_match_all($re, $pattern, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $i => $full) {
                [$token, $pos] = $full;

                $out .= preg_quote(substr($pattern, $offset, $pos - $offset), '~');

                $name = $m[1][$i][0];
                $constraint = trim($m[2][$i][0] ?? '');

                $vars[] = $name;

                if ($constraint === '') {
                    $constraint = '[^/]+';
                } else {
                    $constraint = $this->sanitizeConstraint($constraint);
                }

                $out .= '(?P<' . $name . '>' . $constraint . ')';
                $offset = $pos + strlen($token);
            }
        }

        $out .= preg_quote(substr($pattern, $offset), '~');
        $regex = '~^' . $out . '$~';

        // compile-time validation without emitting warnings
        set_error_handler(static fn() => true);
        $ok = preg_match($regex, '');
        restore_error_handler();

        if ($ok === false) {
            throw new \InvalidArgumentException(
                "Invalid route regex compiled from '{$pattern}': {$regex}"
            );
        }

        return [$regex, $vars];
    }

    private function sanitizeConstraint(string $c): string
    {
        // Hardening: disallow delimiter and anchors
        if (str_contains($c, '~') || str_contains($c, '^') || str_contains($c, '$')) {
            throw new \InvalidArgumentException("Invalid constraint: {$c}");
        }
        return $c;
    }

    private function assertRoute(array $r): void
    {
        foreach (['method', 'path', 'handler'] as $k) {
            if (!isset($r[$k])) {
                throw new \InvalidArgumentException("Invalid route definition: missing '{$k}'");
            }
        }
        if (!is_string($r['method']) || trim($r['method']) === '') {
            throw new \InvalidArgumentException("Invalid route definition: method must be non-empty string");
        }
        if (!is_string($r['path']) || $r['path'] === '' || $r['path'][0] !== '/') {
            throw new \InvalidArgumentException("Invalid route definition: path must start with '/'");
        }
    }

    /* ---------- file writing (atomic + OPcache invalidate) ---------- */

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Failed to create cache dir: {$dir}");
            }
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException("Cache dir not writable: {$dir}");
        }
    }

    private function writePhpReturnFile(string $file, mixed $data): void
    {
        $php  = "<?php\n";
        $php .= "/**\n";
        $php .= " * Auto-generated route cache.\n";
        $php .= " * Generated at: " . date('c') . "\n";
        $php .= " * DO NOT EDIT MANUALLY.\n";
        $php .= " */\n\n";
        $php .= "return " . var_export($data, true) . ";\n";

        $tmp = $file . '.' . uniqid('', true) . '.tmp';
        file_put_contents($tmp, $php, LOCK_EX);
        rename($tmp, $file);

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }
}
