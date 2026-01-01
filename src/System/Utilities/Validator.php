<?php
declare(strict_types=1);

namespace System\Utilities;

use System\Database\Database;

/**
 * Validator
 *
 * Features:
 * - Dot notation + wildcards (items.*.id)
 * - each:<rules> for array elements
 * - Conditional required rules
 * - DB exists/unique checks
 * - Custom rules via extend()
 * - validated() returns nested structure with numeric segments normalized to indexed arrays
 *
 * Additions (controller-friendly):
 * - validate(): bool (alias to passes)
 * - validateOrFail(): array (returns validated or throws ValidationException-like RuntimeException)
 * - firstError(): ?string
 */
class Validator
{
    protected array $data;
    protected array $rules;
    protected array $messages;
    protected array $errors = [];
    protected array $validated = [];
    protected array $fieldOrder = [];

    /** @var array<string,callable> */
    protected static array $customRules = [];

    public function __construct(array $data, array $rules, array $messages = [])
    {
        $this->data     = $data;
        $this->rules    = $this->normalizeRules($rules);
        $this->messages = $messages;
    }

    public static function make(array $data, array $rules, array $messages = []): self
    {
        return new self($data, $rules, $messages);
    }

    /**
     * Register custom rule callback:
     * callback signature: function(string $fieldPath, mixed $value, array $params, array $data): true|string
     */
    public static function extend(string $name, callable $callback): void
    {
        self::$customRules[$name] = $callback;
    }

    public function validate(): bool
    {
        return $this->passes();
    }

    /**
     * Returns validated data or throws (useful for APIs/services).
     * In controllers you can catch and map to response.
     */
    public function validateOrFail(?string $exceptionMessage = null): array
    {
        if ($this->passes()) {
            return $this->validated();
        }
        $msg = $exceptionMessage ?? 'Validation failed';
        throw new \RuntimeException($msg);
    }

    public function passes(): bool
    {
        $this->errors    = [];
        $this->validated = [];

        foreach ($this->fieldOrder as $field) {
            $rules = $this->rules[$field] ?? [];

            // Expand rules with dot/wildcards to concrete paths
            $paths = $this->expandFieldToPaths($field, $this->data);

            if (empty($paths)) {
                // Field not present - validate missing only when required-like rules exist
                if ($this->shouldValidateMissingField($field, $rules)) {
                    $paths = [$field];
                } else {
                    continue;
                }
            }

            foreach ($paths as $path) {
                $missingSentinel = '__MISSING__';
                $value = $this->getValueByPath($this->data, $path, $missingSentinel);

                // If path expanded but actually missing, treat as missing (important for wildcards)
                if ($value === $missingSentinel) {
                    $value = null;
                }

                $this->validateFieldPath($path, $value, $rules, $field);
            }
        }

        return empty($this->errors);
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        foreach ($this->errors as $field => $msgs) {
            if (!empty($msgs[0])) return (string)$msgs[0];
        }
        return null;
    }

    public function validated(): array
    {
        return $this->normalizeValidated($this->validated);
    }

    /* -----------------------
     * Normalization of rules
     * ----------------------- */

    protected function normalizeRules(array $rules): array
    {
        $out = [];
        foreach ($rules as $field => $spec) {
            $k = (string)$field;

            if (is_string($spec)) {
                $out[$k] = array_values(array_filter(array_map('trim', explode('|', $spec)), fn($x) => $x !== ''));
            } elseif (is_array($spec)) {
                $out[$k] = $spec;
            } else {
                $out[$k] = [$spec]; // callable rule
            }

            $this->fieldOrder[] = $k;
        }
        return $out;
    }

    /* -----------------------
     * Validation pipeline
     * ----------------------- */

    protected function validateFieldPath(string $path, mixed $value, array $rules, string $originalFieldKey): void
    {
        $nullable  = $this->hasRule($rules, 'nullable');
        $sometimes = $this->hasRule($rules, 'sometimes');
        $bail      = $this->hasRule($rules, 'bail');

        // sometimes => if key missing AND not required-like => skip
        // Here, $value===null may mean missing or explicit null. We treat "missing" as null; that's acceptable.
        if ($value === null && $sometimes && !$this->hasAnyRequiredRule($rules)) {
            return;
        }

        // required handling
        if ($this->hasRule($rules, 'required') && $this->isEmptyValue($value)) {
            $this->addErrorFor($path, $this->messageFor($originalFieldKey, 'required', "{$originalFieldKey} is required"));
            return;
        }

        // nullable handling: if empty and nullable, accept and skip further rules
        if ($this->isEmptyValue($value) && $nullable) {
            $this->setValidatedValue($path, $value);
            return;
        }

        foreach ($rules as $rule) {
            if ($rule === 'required' || $rule === 'nullable' || $rule === 'sometimes' || $rule === 'bail') {
                continue;
            }

            [$rname, $params] = $this->parseRule($rule);

            // Callable rules passed directly
            if (is_callable($rname)) {
                $res = call_user_func($rname, $path, $value, $params, $this->data);
                if ($res !== true) {
                    $msg = is_string($res) ? $res : $this->messageFor($originalFieldKey, 'custom', "{$originalFieldKey} invalid");
                    $this->addErrorFor($path, $msg);
                    if ($bail) return;
                    break;
                }
                continue;
            }

            // Built-in rules
            $method = 'rule_' . $rname;
            if (method_exists($this, $method)) {
                $res = $this->{$method}($path, $value, $params, $originalFieldKey);
                if ($res !== true) {
                    $this->addErrorFor($path, $this->messageFor($originalFieldKey, $rname, is_string($res) ? $res : "{$originalFieldKey} failed {$rname}"));
                    if ($bail) return;
                    break;
                }
                continue;
            }

            // Custom rule registry
            if (isset(self::$customRules[$rname])) {
                $res = call_user_func(self::$customRules[$rname], $path, $value, $params, $this->data);
                if ($res !== true) {
                    $this->addErrorFor($path, $this->messageFor($originalFieldKey, $rname, is_string($res) ? $res : "{$originalFieldKey} failed {$rname}"));
                    if ($bail) return;
                    break;
                }
                continue;
            }

            // Unknown rule => ignore
        }

        if (!isset($this->errors[$path])) {
            $this->setValidatedValue($path, $value);
        }
    }

    /* -----------------------
     * Built-in rule handlers
     * Return true on success OR string error message on failure
     * ----------------------- */

    protected function rule_string($path, $value, $params, $orig) { return is_string($value) ? true : "Must be a string"; }
    protected function rule_numeric($path, $value, $params, $orig) { return is_numeric($value) ? true : "Must be numeric"; }
    protected function rule_integer($path, $value, $params, $orig) { return filter_var($value, FILTER_VALIDATE_INT) !== false ? true : "Must be integer"; }
    protected function rule_email($path, $value, $params, $orig) { return filter_var($value, FILTER_VALIDATE_EMAIL) ? true : "Invalid email"; }

    protected function rule_boolean($path, $value, $params, $orig)
    {
        if (is_bool($value)) return true;
        $v = is_string($value) ? strtolower(trim($value)) : $value;
        return in_array($v, [0,1,'0','1','true','false','yes','no','on','off'], true) ? true : "Must be boolean";
    }

    protected function rule_url($path, $value, $params, $orig)
    {
        if ($value === null || $value === '') return true;
        return filter_var($value, FILTER_VALIDATE_URL) ? true : "Invalid URL";
    }

    protected function rule_alpha_num($path, $value, $params, $orig)
    {
        return is_string($value) && preg_match('/^[a-z0-9]+$/i', $value) ? true : "Only letters and numbers allowed";
    }

    protected function rule_same($path, $value, $params, $orig)
    {
        $otherPath = (string)($params[0] ?? '');
        if ($otherPath === '') return true;
        $other = $this->getValueByPath($this->data, $otherPath, null);
        return ((string)$other === (string)$value) ? true : "Does not match {$otherPath}";
    }

    protected function rule_min($path, $value, $params, $orig)
    {
        $min = (int)($params[0] ?? 0);
        if (is_string($value)) return mb_strlen($value) >= $min ? true : "Minimum {$min} characters required";
        if (is_numeric($value)) return $value >= $min ? true : "Minimum {$min}";
        if (is_array($value)) return count($value) >= $min ? true : "Minimum {$min} items required";
        return true;
    }

    protected function rule_max($path, $value, $params, $orig)
    {
        $max = (int)($params[0] ?? 0);
        if (is_string($value)) return mb_strlen($value) <= $max ? true : "Maximum {$max} characters allowed";
        if (is_numeric($value)) return $value <= $max ? true : "Maximum {$max}";
        if (is_array($value)) return count($value) <= $max ? true : "Maximum {$max} items allowed";
        return true;
    }

    protected function rule_in($path, $value, $params, $orig)
    {
        return in_array((string)$value, $params, true) ? true : "Invalid value";
    }

    protected function rule_regex($path, $value, $params, $orig)
    {
        $pat = $params[0] ?? '';
        if ($pat === '' || @preg_match($pat, '') === false) return "Invalid pattern";
        return preg_match($pat, (string)$value) ? true : "Invalid format";
    }

    protected function rule_confirmed($path, $value, $params, $orig)
    {
        $confirmPath = $path . '_confirmation';
        $other = $this->getValueByPath($this->data, $confirmPath, null);
        return $other === $value ? true : "Confirmation does not match";
    }

    protected function rule_array($path, $value, $params, $orig)
    {
        return is_array($value) ? true : "Must be an array";
    }

    /**
     * required_array_keys:key1,key2
     * Ensures an array has the listed keys (useful for nested objects).
     */
    protected function rule_required_array_keys($path, $value, $params, $orig)
    {
        if (!is_array($value)) return "Must be an array";
        foreach ($params as $k) {
            $k = trim((string)$k);
            if ($k === '') continue;
            if (!array_key_exists($k, $value)) return "Missing key: {$k}";
        }
        return true;
    }

    /**
     * each:<rules> apply given inner rules to each element if array.
     * Example: 'tags' => 'array|each:string|min:2'
     */
    protected function rule_each($path, $value, $params, $orig)
    {
        if (!is_array($value)) return "Must be an array";

        $inner = $params;
        if (count($inner) === 1 && is_string($inner[0]) && str_contains($inner[0], '|')) {
            $inner = array_map('trim', explode('|', $inner[0]));
        }

        foreach ($value as $i => $v) {
            foreach ($inner as $r) {
                [$rname, $rparams] = $this->parseRule($r);
                $method = 'rule_' . $rname;

                if (method_exists($this, $method)) {
                    $res = $this->{$method}($path . '.' . $i, $v, $rparams, $orig);
                    if ($res !== true) {
                        $this->addErrorFor($path . '.' . $i, $this->messageFor($orig, $rname, is_string($res) ? $res : "Invalid"));
                        return false;
                    }
                } elseif (isset(self::$customRules[$rname])) {
                    $res = call_user_func(self::$customRules[$rname], $path . '.' . $i, $v, $rparams, $this->data);
                    if ($res !== true) {
                        $this->addErrorFor($path . '.' . $i, $this->messageFor($orig, $rname, is_string($res) ? $res : "Invalid"));
                        return false;
                    }
                }
            }
        }
        return true;
    }

    protected function rule_required_if($path, $value, $params, $orig)
    {
        $other = $params[0] ?? null;
        $val   = $params[1] ?? null;
        if ($other === null) return true;

        $otherVal = $this->getValueByPath($this->data, (string)$other, null);
        if ((string)$otherVal === (string)$val) {
            return !$this->isEmptyValue($value) ? true : "Field is required";
        }
        return true;
    }

    protected function rule_required_unless($path, $value, $params, $orig)
    {
        $other = $params[0] ?? null;
        $val   = $params[1] ?? null;
        if ($other === null) return true;

        $otherVal = $this->getValueByPath($this->data, (string)$other, null);
        if ((string)$otherVal !== (string)$val) {
            return !$this->isEmptyValue($value) ? true : "Field is required";
        }
        return true;
    }

    protected function rule_required_with($path, $value, $params, $orig)
    {
        foreach ($params as $p) {
            if ($this->getValueByPath($this->data, (string)$p, null) !== null) {
                return !$this->isEmptyValue($value) ? true : "Field is required when {$p} present";
            }
        }
        return true;
    }

    protected function rule_required_without($path, $value, $params, $orig)
    {
        foreach ($params as $p) {
            if ($this->getValueByPath($this->data, (string)$p, null) === null) {
                return !$this->isEmptyValue($value) ? true : "Field is required when {$p} is missing";
            }
        }
        return true;
    }

    protected function rule_date($path, $value, $params, $orig)
    {
        if ($value === null || $value === '') return true;
        return strtotime((string)$value) === false ? "Invalid date" : true;
    }

    protected function rule_before($path, $value, $params, $orig)
    {
        $target = $params[0] ?? null;
        if ($target === null) return true;

        $compare = $this->getValueByPath($this->data, (string)$target, (string)$target);
        $t1 = strtotime((string)$value);
        $t2 = strtotime((string)$compare);
        if ($t1 === false || $t2 === false) return "Invalid date comparison";
        return $t1 < $t2 ? true : "Must be before {$target}";
    }

    protected function rule_after($path, $value, $params, $orig)
    {
        $target = $params[0] ?? null;
        if ($target === null) return true;

        $compare = $this->getValueByPath($this->data, (string)$target, (string)$target);
        $t1 = strtotime((string)$value);
        $t2 = strtotime((string)$compare);
        if ($t1 === false || $t2 === false) return "Invalid date comparison";
        return $t1 > $t2 ? true : "Must be after {$target}";
    }

    /* -----------------------
     * DB rules (safer identifiers)
     * ----------------------- */

    protected function rule_exists($path, $value, $params, $orig)
    {
        $table = (string)($params[0] ?? '');
        $col   = (string)($params[1] ?? $this->lastSegment($path));

        if ($table === '') return true;
        if (!$this->isSafeIdentifier($table) || !$this->isSafeIdentifier($col)) return "Invalid lookup";

        try {
            $pdo = Database::getInstance()->getPDO();
            $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` = :val";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':val' => $value]);
            return ((int)$stmt->fetchColumn() > 0) ? true : "Does not exist";
        } catch (\Throwable $e) {
            return "Database lookup failed";
        }
    }

    protected function rule_unique($path, $value, $params, $orig)
    {
        $table  = (string)($params[0] ?? '');
        $col    = (string)($params[1] ?? $this->lastSegment($path));
        $except = $params[2] ?? null;

        if ($table === '') return true;
        if (!$this->isSafeIdentifier($table) || !$this->isSafeIdentifier($col)) return "Invalid lookup";

        try {
            $pdo = Database::getInstance()->getPDO();
            $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` = :val";
            $p   = [':val' => $value];

            if ($except !== null) {
                $except = (string)$except;
                if (str_contains($except, ':')) {
                    [$exceptCol, $exceptVal] = explode(':', $except, 2);
                    $exceptCol = (string)$exceptCol;
                    if ($this->isSafeIdentifier($exceptCol)) {
                        $sql .= " AND `{$exceptCol}` != :except";
                        $p[':except'] = $exceptVal;
                    }
                } else {
                    $sql .= " AND `id` != :except";
                    $p[':except'] = $except;
                }
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($p);
            return ((int)$stmt->fetchColumn() === 0) ? true : "Already taken";
        } catch (\Throwable $e) {
            return "Database lookup failed";
        }
    }

    /* -----------------------
     * Path & wildcard expansion
     * ----------------------- */

    protected function expandFieldToPaths(string $field, array $data): array
    {
        if (strpos($field, '*') === false) {
            $exists = $this->getValueByPath($data, $field, '__MISSING__') !== '__MISSING__';
            return $exists ? [$field] : [];
        }

        $parts = explode('.', $field);
        $results = ['']; // prefixes

        foreach ($parts as $part) {
            $newResults = [];

            foreach ($results as $prefix) {
                if ($part === '*') {
                    $parentPath = rtrim($prefix, '.');
                    $arr = $parentPath === '' ? $data : $this->getValueByPath($data, $parentPath, null);
                    if (!is_array($arr)) continue;

                    foreach (array_keys($arr) as $k) {
                        $newResults[] = ($parentPath === '' ? (string)$k : $parentPath . '.' . $k);
                    }
                } else {
                    $newResults[] = ($prefix === '' ? $part : $prefix . '.' . $part);
                }
            }

            $results = $newResults;
            if (empty($results)) break;
        }

        // Only keep paths that exist
        $out = [];
        foreach ($results as $r) {
            if ($this->getValueByPath($data, $r, '__MISSING__') !== '__MISSING__') {
                $out[] = $r;
            }
        }
        return array_values(array_unique($out));
    }

    protected function getValueByPath(array $data, string $path, mixed $default = null): mixed
    {
        if ($path === '') return $default;

        $parts = explode('.', $path);
        $cur = $data;

        foreach ($parts as $p) {
            if (is_array($cur) && array_key_exists($p, $cur)) {
                $cur = $cur[$p];
            } else {
                return $default;
            }
        }
        return $cur;
    }

    protected function setValidatedValue(string $path, mixed $value): void
    {
        $parts = explode('.', $path);
        $ref   =& $this->validated;

        foreach ($parts as $p) {
            if (is_string($p) && ctype_digit($p)) {
                $p = (int)$p;
            }
            if (!is_array($ref)) $ref = [];
            if (!array_key_exists($p, $ref)) $ref[$p] = [];
            $ref =& $ref[$p];
        }

        $ref = $value;
    }

    protected function parseRule(mixed $rule): array
    {
        if ($rule === '') return ['', []];
        if (is_callable($rule)) return [$rule, []];

        $rule = (string)$rule;
        if (!str_contains($rule, ':')) return [$rule, []];

        [$name, $rest] = explode(':', $rule, 2);
        $params = array_map('trim', explode(',', $rest));
        $params = array_values(array_filter($params, fn($x) => $x !== ''));
        return [$name, $params];
    }

    protected function addErrorFor(string $path, string $message): void
    {
        $this->errors[$path][] = $message;
    }

    protected function messageFor(string $field, string $rule, ?string $default = null): string
    {
        if (isset($this->messages["{$field}.{$rule}"])) return (string)$this->messages["{$field}.{$rule}"];
        if (isset($this->messages[$rule])) return (string)$this->messages[$rule];
        return $default ?? "{$field} failed {$rule}";
    }

    protected function isEmptyValue(mixed $v): bool
    {
        return $v === null || $v === '' || (is_array($v) && count($v) === 0);
    }

    protected function hasRule(array $rules, string $name): bool
    {
        foreach ($rules as $r) {
            if (is_string($r) && ($r === $name || str_starts_with($r, $name . ':') || str_starts_with($r, $name))) {
                return true;
            }
        }
        return false;
    }

    protected function hasAnyRequiredRule(array $rules): bool
    {
        foreach (['required', 'required_if', 'required_with', 'required_without', 'required_unless'] as $r) {
            if ($this->hasRule($rules, $r)) return true;
        }
        return false;
    }

    protected function shouldValidateMissingField(string $field, array $rules): bool
    {
        // If required-like rules exist, validate missing.
        if ($this->hasAnyRequiredRule($rules)) return true;

        // sometimes => do not validate missing
        if ($this->hasRule($rules, 'sometimes')) return false;

        return false;
    }

    protected function normalizeValidated(mixed $node): mixed
    {
        if (!is_array($node)) return $node;

        foreach ($node as $k => $v) {
            $node[$k] = $this->normalizeValidated($v);
        }

        $keys = array_keys($node);

        // all keys int-like?
        $allInt = true;
        foreach ($keys as $k) {
            if (!(is_int($k) || (is_string($k) && ctype_digit($k)))) {
                $allInt = false;
                break;
            }
        }

        if ($allInt) {
            $tmp = [];
            foreach ($node as $k => $v) {
                $ik = is_int($k) ? $k : (int)$k;
                $tmp[$ik] = $v;
            }
            ksort($tmp, SORT_NUMERIC);
            $sortedKeys = array_keys($tmp);
            $sequential = ($sortedKeys === range(0, count($tmp) - 1));
            return $sequential ? array_values($tmp) : $tmp;
        }

        return $node;
    }

    protected function lastSegment(string $path): string
    {
        $parts = explode('.', $path);
        return (string)end($parts);
    }

    /**
     * Allow only [a-zA-Z0-9_]
     * This is to avoid SQL injection in identifiers (table/column) for exists/unique.
     */
    protected function isSafeIdentifier(string $s): bool
    {
        return (bool)preg_match('/^[a-zA-Z0-9_]+$/', $s);
    }
}
