<?php

namespace System\Helpers;

use InvalidArgumentException;

/**
 * Class FormHelper
 *
 * Lightweight, safe, production-ready form helper.
 *
 * Responsibilities:
 *  - Build form open/close tags (method spoofing + enctype auto)
 *  - Provide input builders: input, password, hidden, textarea, select, checkbox, radio, file, submit
 *  - Provide CSRF token generation & validation (session backed, TTL)
 *  - Support "old" input retrieval and flashing (for re-populating after validation errors)
 *  - Render validation errors (simple markup)
 *
 * Dependencies:
 *  - SecurityHelper::random / escape / constantTimeEquals
 *  - HtmlHelper::attrs / escape
 *
 * Usage (example):
 *  echo FormHelper::open('/users', 'POST');
 *  echo FormHelper::csrfField();
 *  echo FormHelper::input('name');
 *  echo FormHelper::submit('Save');
 *  echo FormHelper::close();
 */
class FormHelper
{
    /**
     * Session key used to store CSRF tokens.
     */
    protected const CSRF_SESSION_KEY = '_yantra_csrf_tokens';

    /**
     * Session key used to flash old input between requests.
     */
    protected const OLD_INPUT_SESSION_KEY = '_yantra_old_input';

    /**
     * CSRF token TTL in seconds (default 1 hour).
     */
    protected const CSRF_TTL = 3600;

    /**
     * Default CSRF field name.
     */
    protected const CSRF_FIELD = '_csrf_token';

    /**
     * Ensure session is started.
     */
    protected static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Best-effort start; caller should have session started in web apps.
            @session_start();
        }
    }

    /**
     * Open a form tag.
     *
     * @param string $action
     * @param string $method GET|POST|PUT|PATCH|DELETE
     * @param array $attrs Additional attributes for the <form> tag
     * @return string
     */
    public static function open(string $action = '', string $method = 'POST', array $attrs = []): string
    {
        $method = strtoupper($method);
        $htmlMethod = in_array($method, ['GET', 'POST'], true) ? $method : 'POST';

        $attrs = array_merge(['action' => $action ?: UrlHelper::current(), 'method' => strtolower($htmlMethod)], $attrs);

        // If file inputs likely, user may pass 'files' => true; convert to enctype
        if (!empty($attrs['files'])) {
            $attrs['enctype'] = 'multipart/form-data';
            unset($attrs['files']);
        }

        $formTag = '<form' . HtmlHelper::attrs($attrs) . '>';

        // If method spoofing is required, include hidden _method field
        if ($htmlMethod !== $method) {
            $formTag .= self::hidden('_method', $method);
        }

        return $formTag;
    }

    /**
     * Close a form tag.
     *
     * @return string
     */
    public static function close(): string
    {
        return '</form>';
    }

    /**
     * Generate a CSRF token, store in session list, and return token.
     *
     * We allow multiple tokens (per form) and garbage collect old tokens.
     *
     * @param int|null $ttl seconds; uses CSRF_TTL by default
     * @return string token
     */
    public static function generateCsrfToken(?int $ttl = null): string
    {
        self::ensureSession();
        $ttl = $ttl ?? self::CSRF_TTL;

        $token = SecurityHelper::random(64);
        $now = time();

        if (!isset($_SESSION[self::CSRF_SESSION_KEY]) || !is_array($_SESSION[self::CSRF_SESSION_KEY])) {
            $_SESSION[self::CSRF_SESSION_KEY] = [];
        }

        // Save token with creation time
        $_SESSION[self::CSRF_SESSION_KEY][$token] = $now + $ttl;

        // Garbage collect expired tokens occasionally
        foreach ($_SESSION[self::CSRF_SESSION_KEY] as $t => $expiry) {
            if ($expiry < $now) {
                unset($_SESSION[self::CSRF_SESSION_KEY][$t]);
            }
        }

        return $token;
    }

    /**
     * Validate CSRF token from request (POST/GET).
     *
     * @param string|null $token
     * @return bool
     */
    public static function validateCsrfToken(?string $token = null): bool
    {
        self::ensureSession();

        // Allow explicit token param; otherwise check POST then GET
        $token = $token ?? ($_POST[self::CSRF_FIELD] ?? $_REQUEST[self::CSRF_FIELD] ?? null);

        if (!$token || !is_string($token)) {
            return false;
        }

        if (empty($_SESSION[self::CSRF_SESSION_KEY]) || !is_array($_SESSION[self::CSRF_SESSION_KEY])) {
            return false;
        }

        $now = time();

        // Check existence and expiry
        foreach ($_SESSION[self::CSRF_SESSION_KEY] as $stored => $expiry) {
            if ($expiry < $now) {
                // expired, remove
                unset($_SESSION[self::CSRF_SESSION_KEY][$stored]);
                continue;
            }

            if (SecurityHelper::constantTimeEquals($stored, $token)) {
                // valid token — consume it (one-time use)
                //unset($_SESSION[self::CSRF_SESSION_KEY][$stored]);
                return true;
            }
        }

        return false;
    }

    /**
     * Render a CSRF hidden input field (generate token if needed).
     *
     * @return string
     */
    public static function csrfField(): string
    {
        $token = self::generateCsrfToken();
        return self::hidden(self::CSRF_FIELD, $token);
    }

    /**
     * Create a generic input element.
     *
     * @param string $name
     * @param string|int|float|null $value
     * @param array $attrs Attributes like class, id, placeholder
     * @param string $type type attribute, default 'text'
     * @return string
     */
    public static function input(string $name, mixed $value = null, array $attrs = [], string $type = 'text'): string
    {
        $attrs = array_merge(['name' => $name, 'type' => $type, 'value' => (string) ($value ?? self::old($name, ''))], $attrs);

        // If value is null explicitly and type is checkbox/radio, don't include value attribute unless provided
        if (($type === 'checkbox' || $type === 'radio') && array_key_exists('checked', $attrs) && $attrs['checked']) {
            $attrs['checked'] = true;
        }

        return '<input' . HtmlHelper::attrs($attrs) . '>';
    }

    /**
     * Password input (value is never pre-populated for security).
     *
     * @param string $name
     * @param array $attrs
     * @return string
     */
    public static function password(string $name, array $attrs = []): string
    {
        $attrs = array_merge(['name' => $name, 'type' => 'password', 'value' => ''], $attrs);
        return '<input' . HtmlHelper::attrs($attrs) . '>';
    }

    /**
     * Hidden input.
     *
     * @param string $name
     * @param string|int|float|null $value
     * @param array $attrs
     * @return string
     */
    public static function hidden(string $name, mixed $value = null, array $attrs = []): string
    {
        $attrs = array_merge(['type' => 'hidden', 'name' => $name, 'value' => (string) ($value ?? '')], $attrs);
        return '<input' . HtmlHelper::attrs($attrs) . '>';
    }

    /**
     * Textarea builder.
     *
     * @param string $name
     * @param string|null $value
     * @param array $attrs
     * @return string
     */
    public static function textarea(string $name, ?string $value = null, array $attrs = []): string
    {
        $value = $value ?? self::old($name, '');
        $attrs = array_merge(['name' => $name], $attrs);
        return '<textarea' . HtmlHelper::attrs($attrs) . '>' . HtmlHelper::escape((string) $value) . '</textarea>';
    }

    /**
     * Select builder.
     *
     * @param string $name
     * @param array $options ['value' => 'Label' ...] or nested optgroup arrays
     * @param string|int|null $selected
     * @param array $attrs
     * @return string
     */
    public static function select(string $name, array $options = [], mixed $selected = null, array $attrs = []): string
    {
        $selected = $selected ?? self::old($name, null);
        $attrs = array_merge(['name' => $name], $attrs);

        $html = '<select' . HtmlHelper::attrs($attrs) . '>';
        foreach ($options as $k => $v) {
            // support optgroup: ['Group' => ['v' => 'Label']]
            if (is_array($v)) {
                $html .= '<optgroup label="' . HtmlHelper::escape((string) $k) . '">';
                foreach ($v as $ok => $ov) {
                    $isSel = (string) $ok === (string) $selected;
                    $html .= '<option value="' . HtmlHelper::escape((string) $ok) . '"' . ($isSel ? ' selected' : '') . '>' . HtmlHelper::escape((string) $ov) . '</option>';
                }
                $html .= '</optgroup>';
                continue;
            }

            $isSel = (string) $k === (string) $selected;
            $html .= '<option value="' . HtmlHelper::escape((string) $k) . '"' . ($isSel ? ' selected' : '') . '>' . HtmlHelper::escape((string) $v) . '</option>';
        }
        $html .= '</select>';

        return $html;
    }

    /**
     * Checkbox builder.
     *
     * Renders a hidden input with value '0' followed by actual checkbox with value '1' if $asBoolean true,
     * otherwise uses provided $value.
     *
     * @param string $name
     * @param mixed $value When used as boolean, $value true => checked
     * @param bool $checked
     * @param array $attrs
     * @param bool $asBoolean If true, generate hidden fallback + checkbox with values 0/1
     * @return string
     */
    public static function checkbox(string $name, mixed $value = 1, bool $checked = false, array $attrs = [], bool $asBoolean = true): string
    {
        $old = self::old($name, null);
        if ($old !== null) {
            // if old exists, determine checked from old value
            $checked = (string) $old === (string) $value || ($asBoolean && (bool) $old);
        }

        $html = '';
        if ($asBoolean) {
            // hidden fallback to ensure key is present in POST when unchecked
            $html .= self::hidden($name, 0);
            $attrs = array_merge(['type' => 'checkbox', 'name' => $name, 'value' => 1], $attrs);
            if ($checked) {
                $attrs['checked'] = true;
            }
            $html .= '<input' . HtmlHelper::attrs($attrs) . '>';
            return $html;
        }

        $attrs = array_merge(['type' => 'checkbox', 'name' => $name, 'value' => (string) $value], $attrs);
        if ($checked) {
            $attrs['checked'] = true;
        }

        return '<input' . HtmlHelper::attrs($attrs) . '>';
    }

    /**
     * Radio button builder.
     *
     * @param string $name
     * @param mixed $value
     * @param bool $checked
     * @param array $attrs
     * @return string
     */
    public static function radio(string $name, mixed $value, bool $checked = false, array $attrs = []): string
    {
        $old = self::old($name, null);
        if ($old !== null) {
            $checked = (string) $old === (string) $value;
        }

        $attrs = array_merge(['type' => 'radio', 'name' => $name, 'value' => (string) $value], $attrs);
        if ($checked) {
            $attrs['checked'] = true;
        }

        return '<input' . HtmlHelper::attrs($attrs) . '>';
    }

    /**
     * File input builder.
     *
     * @param string $name
     * @param array $attrs
     * @return string
     */
    public static function file(string $name, array $attrs = []): string
    {
        $attrs = array_merge(['type' => 'file', 'name' => $name], $attrs);
        return '<input' . HtmlHelper::attrs($attrs) . '>';
    }

    /**
     * Submit button.
     *
     * @param string $value
     * @param array $attrs
     * @return string
     */
    public static function submit(string $value = 'Submit', array $attrs = []): string
    {
        $attrs = array_merge(['type' => 'submit', 'value' => $value], $attrs);
        return '<button' . HtmlHelper::attrs($attrs) . '>' . HtmlHelper::escape($value) . '</button>';
    }

    /**
     * Label helper.
     *
     * @param string $for
     * @param string $text
     * @param array $attrs
     * @return string
     */
    public static function label(string $for, string $text, array $attrs = []): string
    {
        $attrs = array_merge(['for' => $for], $attrs);
        return '<label' . HtmlHelper::attrs($attrs) . '>' . HtmlHelper::escape($text) . '</label>';
    }

    /**
     * Render validation errors.
     *
     * $errors may be:
     *  - array of strings (global errors)
     *  - associative array: ['field' => ['err1','err2'], ...]
     *
     * If $field is provided, returns markup for that field only.
     *
     * @param array|string|null $errors
     * @param string|null $field
     * @param string $wrapTag e.g., 'div' or 'ul'
     * @param array $attrs attributes for outer wrapper
     * @return string
     */
    public static function errors(array|string|null $errors, ?string $field = null, string $wrapTag = 'div', array $attrs = []): string
    {
        if ($errors === null || $errors === []) {
            return '';
        }

        // If errors is a string, wrap it
        if (is_string($errors)) {
            $errors = [$errors];
        }

        // If field provided and errors is associative array
        if ($field !== null && is_array($errors) && array_key_exists($field, $errors)) {
            $list = (array) $errors[$field];
            return self::renderErrorList($list, $wrapTag, $attrs);
        }

        // If field provided but not found
        if ($field !== null) {
            return '';
        }

        // Global errors (non-associative)
        // If $errors is associative, flatten values
        $flat = [];
        foreach ($errors as $k => $v) {
            if (is_array($v)) {
                foreach ($v as $msg) {
                    $flat[] = $msg;
                }
            } else {
                $flat[] = $v;
            }
        }

        return self::renderErrorList($flat, $wrapTag, $attrs);
    }

    /**
     * Flash the current POST data as "old" input into session so forms can repopulate.
     *
     * Typically called by controller after validation fails.
     *
     * @param array|null $data if null, use $_POST
     */
    public static function flashOld(?array $data = null): void
    {
        self::ensureSession();
        $data = $data ?? ($_POST ?? []);
        // Do not store files
        if (isset($data['_FILES'])) {
            unset($data['_FILES']);
        }
        $_SESSION[self::OLD_INPUT_SESSION_KEY] = $data;
    }

    /**
     * Return old value for a field (from flashed session or current POST).
     *
     * @param string $name dot-notation supported (a.b.c)
     * @param mixed $default
     * @return mixed
     */
    public static function old(string $name, mixed $default = null): mixed
    {
        self::ensureSession();

        // Priority: flashed old input in session, then current POST/GET
        $old = $_SESSION[self::OLD_INPUT_SESSION_KEY] ?? null;

        // If old input available, use dot-notation lookup
        if (is_array($old) && $old !== []) {
            $val = ArrayHelper::get($old, $name, null);
            if ($val !== null) {
                return $val;
            }
        }

        // fallback to current request variables
        if (isset($_POST[$name])) {
            return $_POST[$name];
        }

        if (isset($_GET[$name])) {
            return $_GET[$name];
        }

        return $default;
    }

    /**
     * Clear flashed old input from session.
     */
    public static function clearOld(): void
    {
        self::ensureSession();
        unset($_SESSION[self::OLD_INPUT_SESSION_KEY]);
    }

    /**
     * Helper: render error list markup.
     *
     * @param array $messages
     * @param string $wrapTag
     * @param array $attrs
     * @return string
     */
    protected static function renderErrorList(array $messages, string $wrapTag, array $attrs): string
    {
        if (empty($messages)) {
            return '';
        }

        // Default attributes commonly used for errors
        $defaultAttrs = ['class' => 'form-errors'];
        $attrs = array_merge($defaultAttrs, $attrs);

        // If unordered list requested, render <ul><li>...</li></ul>
        if (in_array(strtolower($wrapTag), ['ul', 'ol'], true)) {
            $html = '<' . $wrapTag . HtmlHelper::attrs($attrs) . '>';
            foreach ($messages as $m) {
                $html .= '<li>' . HtmlHelper::escape((string) $m) . '</li>';
            }
            $html .= '</' . $wrapTag . '>';
            return $html;
        }

        // Default: wrap in container with <div class="form-errors"><div class="error">...</div></div>
        $html = '<' . $wrapTag . HtmlHelper::attrs($attrs) . '>';
        foreach ($messages as $m) {
            $html .= '<div class="error">' . HtmlHelper::escape((string) $m) . '</div>';
        }
        $html .= '</' . $wrapTag . '>';
        return $html;
    }
}
