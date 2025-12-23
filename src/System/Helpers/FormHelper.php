<?php
declare(strict_types=1);

namespace System\Helpers;

use System\Utilities\SessionStore;
use System\Security\Crypto;

/**
 * FormHelper (Yantra optimized)
 *
 * Goals:
 *  - Framework-safe (SessionStore adapter friendly; no session_start in helpers)
 *  - Secure CSRF (TTL + one-time tokens by default)
 *  - Safe "old input" flashing (sensitive keys excluded by default)
 *  - Minimal dependencies; keeps HTML escaping delegated to HtmlHelper
 *
 * Notes:
 *  - Requires HtmlHelper::attrs() and HtmlHelper::escape()
 *  - Uses UrlHelper::current() if action not provided (same as your original)
 */
final class FormHelper
{
    /** Session key for CSRF token bag */
    private const CSRF_SESSION_KEY = '_yantra_csrf_tokens';

    /** Session key for flashed "old input" */
    private const OLD_INPUT_SESSION_KEY = '_yantra_old_input';

    /** Default CSRF TTL seconds */
    private const CSRF_TTL = 3600;

    /** Default CSRF field name */
    private const CSRF_FIELD = '_csrf_token';

    /**
     * Default sensitive keys to exclude from old input flash.
     * (You can pass additional excludes to flashOld()).
     */
    private const DEFAULT_OLD_EXCLUDE_KEYS = [
        'password', 'pass', 'passwd', 'password_confirmation', 'confirm_password',
        'token', 'access_token', 'refresh_token', 'id_token',
        'authorization', 'auth', 'bearer', 'secret', 'api_key', 'apikey',
        self::CSRF_FIELD, '_method',
    ];

    /* -------------------------------------------------------------------------
     * SessionStore bootstrap
     * ------------------------------------------------------------------------- */

    private static function ensureStore(): void
    {
        // No-op if already initialized; will default to NativeSessionAdapter otherwise.
        SessionStore::init();
    }

    /* -------------------------------------------------------------------------
     * Form tags
     * ------------------------------------------------------------------------- */

    /**
     * Open a form tag with method spoofing.
     *
     * @param string $action
     * @param string $method GET|POST|PUT|PATCH|DELETE
     * @param array $attrs Attributes for <form>
     */
    public static function open(string $action = '', string $method = 'POST', array $attrs = []): string
    {
        $method = strtoupper(trim($method));
        if ($method === '') $method = 'POST';

        $htmlMethod = in_array($method, ['GET', 'POST'], true) ? $method : 'POST';

        $attrs = array_merge(
            [
                'action' => ($action !== '' ? $action : UrlHelper::current()),
                'method' => strtolower($htmlMethod),
            ],
            $attrs
        );

        // Convenience: ['files' => true] => enctype multipart
        if (!empty($attrs['files'])) {
            $attrs['enctype'] = 'multipart/form-data';
            unset($attrs['files']);
        }

        $html = '<form' . HtmlHelper::attrs($attrs) . '>';

        if ($htmlMethod !== $method) {
            $html .= self::hidden('_method', $method);
        }

        return $html;
    }

    public static function close(): string
    {
        return '</form>';
    }

    /* -------------------------------------------------------------------------
     * CSRF
     * ------------------------------------------------------------------------- */

    /**
     * Generate a CSRF token, store in session bag with expiry, and return token.
     * Multiple tokens are supported (per-form).
     */
    public static function generateCsrfToken(?int $ttl = null): string
    {
        self::ensureStore();

        $ttl = $ttl ?? self::CSRF_TTL;
        if ($ttl <= 0) {
            $ttl = self::CSRF_TTL;
        }

        // 32 bytes => 64 hex chars (safe for HTML)
        $token = Crypto::randomHex(32);
        $expiresAt = time() + $ttl;

        $bag = SessionStore::get(self::CSRF_SESSION_KEY, []);
        if (!is_array($bag)) $bag = [];

        // Add token
        $bag[$token] = $expiresAt;

        // GC expired tokens
        $now = time();
        foreach ($bag as $t => $exp) {
            if (!is_int($exp) && !is_numeric($exp)) {
                unset($bag[$t]);
                continue;
            }
            if ((int)$exp < $now) {
                unset($bag[$t]);
            }
        }

        SessionStore::set(self::CSRF_SESSION_KEY, $bag);

        return $token;
    }

    /**
     * Validate CSRF token from request.
     *
     * - If $token is null, it checks POST then REQUEST (fallback).
     * - One-time by default: consumes token on success.
     */
    public static function validateCsrfToken(?string $token = null, bool $consume = true): bool
    {
        self::ensureStore();

        $token = $token ?? ($_POST[self::CSRF_FIELD] ?? ($_REQUEST[self::CSRF_FIELD] ?? null));
        if (!is_string($token) || trim($token) === '') {
            return false;
        }
        $token = trim($token);

        $bag = SessionStore::get(self::CSRF_SESSION_KEY, []);
        if (!is_array($bag) || $bag === []) {
            return false;
        }

        $now = time();
        $matchedKey = null;

        foreach ($bag as $stored => $exp) {
            // Remove junk/expired
            if (!is_int($exp) && !is_numeric($exp)) {
                unset($bag[$stored]);
                continue;
            }
            if ((int)$exp < $now) {
                unset($bag[$stored]);
                continue;
            }

            // Constant-time compare
            if (Crypto::hashEquals((string)$stored, $token)) {
                $matchedKey = (string)$stored;
                break;
            }
        }

        if ($matchedKey === null) {
            // Persist GC updates
            SessionStore::set(self::CSRF_SESSION_KEY, $bag);
            return false;
        }

        if ($consume) {
            unset($bag[$matchedKey]);
        }

        SessionStore::set(self::CSRF_SESSION_KEY, $bag);
        return true;
    }

    /**
     * Render hidden CSRF field.
     */
    public static function csrfField(): string
    {
        $token = self::generateCsrfToken();
        return self::hidden(self::CSRF_FIELD, $token);
    }

    /**
     * Return current CSRF field name.
     */
    public static function csrfFieldName(): string
    {
        return self::CSRF_FIELD;
    }

    /* -------------------------------------------------------------------------
     * Inputs
     * ------------------------------------------------------------------------- */

    public static function input(string $name, mixed $value = null, array $attrs = [], string $type = 'text'): string
    {
        $val = $value;
        if ($val === null) {
            $val = self::old($name, '');
        }

        $attrs = array_merge(
            ['name' => $name, 'type' => $type],
            $attrs
        );

        // Password should not be prefilled through this method
        if ($type === 'password') {
            $attrs['value'] = '';
        } else {
            $attrs['value'] = (string)$val;
        }

        return '<input' . HtmlHelper::attrs($attrs) . '>';
    }

    public static function password(string $name, array $attrs = []): string
    {
        $attrs = array_merge(['name' => $name, 'type' => 'password', 'value' => ''], $attrs);
        return '<input' . HtmlHelper::attrs($attrs) . '>';
    }

    public static function hidden(string $name, mixed $value = null, array $attrs = []): string
    {
        $attrs = array_merge(['type' => 'hidden', 'name' => $name, 'value' => (string)($value ?? '')], $attrs);
        return '<input' . HtmlHelper::attrs($attrs) . '>';
    }

    public static function textarea(string $name, ?string $value = null, array $attrs = []): string
    {
        $value = $value ?? (string)self::old($name, '');
        $attrs = array_merge(['name' => $name], $attrs);

        return '<textarea' . HtmlHelper::attrs($attrs) . '>' . HtmlHelper::escape($value) . '</textarea>';
    }

    /**
     * @param array $options ['value'=>'Label'] or ['Group'=>['v'=>'Label']]
     */
    public static function select(string $name, array $options = [], mixed $selected = null, array $attrs = []): string
    {
        $selected = $selected ?? self::old($name, null);
        $attrs = array_merge(['name' => $name], $attrs);

        $html = '<select' . HtmlHelper::attrs($attrs) . '>';

        foreach ($options as $k => $v) {
            // optgroup
            if (is_array($v)) {
                $html .= '<optgroup label="' . HtmlHelper::escape((string)$k) . '">';
                foreach ($v as $ok => $ov) {
                    $isSel = ((string)$ok === (string)$selected);
                    $html .= '<option value="' . HtmlHelper::escape((string)$ok) . '"' . ($isSel ? ' selected' : '') . '>'
                        . HtmlHelper::escape((string)$ov) . '</option>';
                }
                $html .= '</optgroup>';
                continue;
            }

            $isSel = ((string)$k === (string)$selected);
            $html .= '<option value="' . HtmlHelper::escape((string)$k) . '"' . ($isSel ? ' selected' : '') . '>'
                . HtmlHelper::escape((string)$v) . '</option>';
        }

        $html .= '</select>';
        return $html;
    }

    /**
     * Checkbox builder (boolean-friendly).
     *
     * If $asBoolean true, renders hidden fallback value=0 then checkbox value=1.
     */
    public static function checkbox(
        string $name,
        mixed $value = 1,
        bool $checked = false,
        array $attrs = [],
        bool $asBoolean = true
    ): string {
        $old = self::old($name, null);
        if ($old !== null) {
            if ($asBoolean) {
                $checked = (string)$old === '1' || $old === true || $old === 1;
            } else {
                $checked = (string)$old === (string)$value;
            }
        }

        $html = '';

        if ($asBoolean) {
            $html .= self::hidden($name, 0);

            $a = array_merge(['type' => 'checkbox', 'name' => $name, 'value' => 1], $attrs);
            if ($checked) $a['checked'] = true;

            $html .= '<input' . HtmlHelper::attrs($a) . '>';
            return $html;
        }

        $a = array_merge(['type' => 'checkbox', 'name' => $name, 'value' => (string)$value], $attrs);
        if ($checked) $a['checked'] = true;

        return '<input' . HtmlHelper::attrs($a) . '>';
    }

    public static function radio(string $name, mixed $value, bool $checked = false, array $attrs = []): string
    {
        $old = self::old($name, null);
        if ($old !== null) {
            $checked = ((string)$old === (string)$value);
        }

        $attrs = array_merge(['type' => 'radio', 'name' => $name, 'value' => (string)$value], $attrs);
        if ($checked) $attrs['checked'] = true;

        return '<input' . HtmlHelper::attrs($attrs) . '>';
    }

    public static function file(string $name, array $attrs = []): string
    {
        $attrs = array_merge(['type' => 'file', 'name' => $name], $attrs);
        return '<input' . HtmlHelper::attrs($attrs) . '>';
    }

    /**
     * Submit button.
     * (Your old version set type=submit and value but also used <button>; this fixes semantics.)
     */
    public static function submit(string $text = 'Submit', array $attrs = []): string
    {
        $attrs = array_merge(['type' => 'submit'], $attrs);
        return '<button' . HtmlHelper::attrs($attrs) . '>' . HtmlHelper::escape($text) . '</button>';
    }

    public static function label(string $for, string $text, array $attrs = []): string
    {
        $attrs = array_merge(['for' => $for], $attrs);
        return '<label' . HtmlHelper::attrs($attrs) . '>' . HtmlHelper::escape($text) . '</label>';
    }

    /* -------------------------------------------------------------------------
     * Errors rendering
     * ------------------------------------------------------------------------- */

    /**
     * Render validation errors.
     *
     * $errors can be:
     *  - string
     *  - string[]
     *  - ['field' => ['msg1','msg2'], ...]
     */
    public static function errors(array|string|null $errors, ?string $field = null, string $wrapTag = 'div', array $attrs = []): string
    {
        if ($errors === null || $errors === []) {
            return '';
        }

        if (is_string($errors)) {
            $errors = [$errors];
        }

        if ($field !== null && is_array($errors) && array_key_exists($field, $errors)) {
            return self::renderErrorList((array)$errors[$field], $wrapTag, $attrs);
        }

        if ($field !== null) {
            return '';
        }

        // Flatten
        $flat = [];
        foreach ($errors as $v) {
            if (is_array($v)) {
                foreach ($v as $msg) $flat[] = $msg;
            } else {
                $flat[] = $v;
            }
        }

        return self::renderErrorList($flat, $wrapTag, $attrs);
    }

    private static function renderErrorList(array $messages, string $wrapTag, array $attrs): string
    {
        $messages = array_values(array_filter($messages, static fn($m) => is_scalar($m) && (string)$m !== ''));
        if ($messages === []) return '';

        $attrs = array_merge(['class' => 'form-errors'], $attrs);

        $tag = strtolower($wrapTag);
        if ($tag === 'ul' || $tag === 'ol') {
            $html = '<' . $wrapTag . HtmlHelper::attrs($attrs) . '>';
            foreach ($messages as $m) {
                $html .= '<li>' . HtmlHelper::escape((string)$m) . '</li>';
            }
            $html .= '</' . $wrapTag . '>';
            return $html;
        }

        $html = '<' . $wrapTag . HtmlHelper::attrs($attrs) . '>';
        foreach ($messages as $m) {
            $html .= '<div class="error">' . HtmlHelper::escape((string)$m) . '</div>';
        }
        $html .= '</' . $wrapTag . '>';

        return $html;
    }

    /* -------------------------------------------------------------------------
     * Old input flash / retrieval
     * ------------------------------------------------------------------------- */

    /**
     * Flash old input to session so forms can repopulate after redirect.
     *
     * @param array|null $data     If null, uses $_POST
     * @param array      $exclude  Additional keys to exclude (case-insensitive)
     */
    public static function flashOld(?array $data = null, array $exclude = []): void
    {
        self::ensureStore();

        $data = $data ?? ($_POST ?? []);
        if (!is_array($data)) $data = [];

        // Remove files if someone accidentally passes them
        unset($data['_FILES'], $data['files'], $data['file']);

        $excludeKeys = array_merge(self::DEFAULT_OLD_EXCLUDE_KEYS, $exclude);
        $excludeMap = [];
        foreach ($excludeKeys as $k) {
            if (!is_string($k)) continue;
            $k = strtolower(trim($k));
            if ($k !== '') $excludeMap[$k] = true;
        }

        $filtered = self::removeSensitiveRecursive($data, $excludeMap);

        SessionStore::set(self::OLD_INPUT_SESSION_KEY, $filtered);
    }

    /**
     * Read old input value.
     * Supports dot notation: "user.email"
     */
    public static function old(string $name, mixed $default = null): mixed
    {
        self::ensureStore();

        $old = SessionStore::get(self::OLD_INPUT_SESSION_KEY, null);
        if (is_array($old) && $old !== []) {
            $val = self::dotGet($old, $name, null);
            if ($val !== null) return $val;
        }

        // Fallback to current request vars (useful for same-request validation)
        if (isset($_POST[$name])) return $_POST[$name];
        if (isset($_GET[$name]))  return $_GET[$name];

        return $default;
    }

    /**
     * Clear flashed old input.
     */
    public static function clearOld(): void
    {
        self::ensureStore();
        SessionStore::remove(self::OLD_INPUT_SESSION_KEY);
    }

    /* -------------------------------------------------------------------------
     * Small internal helpers (no ArrayHelper dependency)
     * ------------------------------------------------------------------------- */

    private static function dotGet(array $arr, string $key, mixed $default = null): mixed
    {
        $key = trim($key);
        if ($key === '') return $default;

        if (array_key_exists($key, $arr)) {
            return $arr[$key];
        }

        if (!str_contains($key, '.')) {
            return $default;
        }

        $parts = explode('.', $key);
        $cur = $arr;

        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '' || !is_array($cur) || !array_key_exists($p, $cur)) {
                return $default;
            }
            $cur = $cur[$p];
        }

        return $cur;
    }

    private static function removeSensitiveRecursive(array $data, array $excludeMap): array
    {
        $out = [];

        foreach ($data as $k => $v) {
            $keyStr = is_string($k) ? strtolower(trim($k)) : null;

            // Exclude by key
            if ($keyStr !== null && isset($excludeMap[$keyStr])) {
                continue;
            }

            // Recurse arrays; keep scalars
            if (is_array($v)) {
                $out[$k] = self::removeSensitiveRecursive($v, $excludeMap);
                continue;
            }

            // Only keep scalar/null values
            if (is_scalar($v) || $v === null) {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
