<?php
namespace System\Utilities;

use System\Database\Database;

/**
 * Validator - extended feature set with wildcard-validated output mapping.
 *
 * Key additions:
 * - Numeric path segments are treated as integer indices when populating validated data.
 * - validated() returns normalized arrays: integer-keyed arrays are sorted and reindexed
 *   (0..n-1) where appropriate so 'items.0.id' becomes ['items'][0]['id'].
 *
 * Rest of the features (dot notation, wildcards, each, conditional rules, DB checks, custom rules)
 * are the same as the extended Validator you had.
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
        $this->data = $data;
        $this->rules = $this->normalizeRules($rules);
        $this->messages = $messages;
    }

    public static function make(array $data, array $rules, array $messages = []): Validator
    {
        return new self($data, $rules, $messages);
    }

    /**
     * Register custom rule callback:
     * callback signature: function(string $fieldPath, $value, array $params, array $data): true|string
     * Return true for success, or string message for failure.
     */
    public static function extend(string $name, callable $callback): void
    {
        self::$customRules[$name] = $callback;
    }

    protected function normalizeRules(array $rules): array
    {
        $out = [];
        foreach ($rules as $field => $spec) {
            if (is_string($field) || is_int($field)) {
                $k = (string)$field;
                if (is_string($spec)) {
                    $out[$k] = array_map('trim', explode('|', $spec));
                } elseif (is_array($spec)) {
                    $out[$k] = $spec;
                } else {
                    // single callable rule
                    $out[$k] = [$spec];
                }
                $this->fieldOrder[] = $k;
            }
        }
        return $out;
    }

    public function passes(): bool
    {
        $this->errors = [];
        $this->validated = [];

        // iterate fields in the same order rules were provided
        foreach ($this->fieldOrder as $field) {
            $rules = $this->rules[$field] ?? [];
            // expand rules with dot/wildcards to concrete paths
            $paths = $this->expandFieldToPaths($field, $this->data);
            if (empty($paths)) {
                // field not present - handle 'sometimes' and 'required*' rules
                if ($this->shouldValidateMissingField($field, $rules)) {
                    // still treat as present with null value for rules like required
                    $paths = [$field];
                } else {
                    continue; // skip field entirely
                }
            }

            foreach ($paths as $path) {
                $value = $this->getValueByPath($this->data, $path, null);
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

    /**
     * Returns nested validated data (only keys that had rules),
     * with wildcard numeric indices normalized to indexed arrays.
     */
    public function validated(): array
    {
        return $this->normalizeValidated($this->validated);
    }

    /* -----------------------
     * Field validation helpers
     * ----------------------- */

    protected function validateFieldPath(string $path, $value, array $rules, string $originalFieldKey): void
    {
        $nullable = $this->hasRule($rules, 'nullable');
        $sometimes = $this->hasRule($rules, 'sometimes');
        $bail = $this->hasRule($rules, 'bail');

        // if value is missing and sometimes => skip
        if ($value === null && $sometimes && !$this->hasAnyRequiredRule($rules)) {
            return;
        }

        // required handling: if required and empty => add error
        if ($this->hasRule($rules, 'required') && $this->isEmptyValue($value)) {
            $this->addErrorFor($path, $this->messageFor($originalFieldKey, 'required', "{$originalFieldKey} is required"));
            return;
        }

        // if value is empty and nullable -> skip other rules
        if ($this->isEmptyValue($value) && $nullable) {
            $this->setValidatedValue($path, $value);
            return;
        }

        foreach ($rules as $rule) {
            if ($rule === 'required' || $rule === 'nullable' || $rule === 'sometimes' || $rule === 'bail') {
                continue;
            }

            [$rname, $params] = $this->parseRule($rule);

            // Callable rules (closures) passed directly
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

            // Built-in handlers
            $method = 'rule_' . $rname;
            if (method_exists($this, $method)) {
                $res = $this->{$method}($path, $value, $params, $originalFieldKey);
                if ($res !== true) {
                    $this->addErrorFor($path, $this->messageFor($originalFieldKey, $rname, $res ?? "{$originalFieldKey} failed {$rname}"));
                    if ($bail) return;
                    break;
                } else {
                    continue;
                }
            }

            // Custom rules registered with extend
            if (isset(self::$customRules[$rname])) {
                $res = call_user_func(self::$customRules[$rname], $path, $value, $params, $this->data);
                if ($res !== true) {
                    $this->addErrorFor($path, $this->messageFor($originalFieldKey, $rname, $res ?? "{$originalFieldKey} failed {$rname}"));
                    if ($bail) return;
                    break;
                }
                continue;
            }

            // Unknown rule: ignore
        }

        // if reached here and no errors for this path, set validated value
        if (!isset($this->errors[$path])) {
            $this->setValidatedValue($path, $value);
        }
    }

    /* -----------------------
     * Built-in rule handlers - return true on success or error message string on failure
     * ----------------------- */

    protected function rule_string($path, $value, $params, $orig) { return is_string($value) ? true : "Must be a string"; }
    protected function rule_numeric($path, $value, $params, $orig) { return is_numeric($value) ? true : "Must be numeric"; }
    protected function rule_integer($path, $value, $params, $orig) { return filter_var($value, FILTER_VALIDATE_INT) !== false ? true : "Must be integer"; }
    protected function rule_email($path, $value, $params, $orig) { return filter_var($value, FILTER_VALIDATE_EMAIL) ? true : "Invalid email"; }
    protected function rule_min($path, $value, $params, $orig)
    {
        $min = (int)($params[0] ?? 0);
        if (is_string($value)) {
            return mb_strlen($value) >= $min ? true : "Minimum {$min} characters required";
        } elseif (is_numeric($value)) {
            return $value >= $min ? true : "Minimum {$min}";
        } elseif (is_array($value)) {
            return count($value) >= $min ? true : "Minimum {$min} items required";
        }
        return true;
    }
    protected function rule_max($path, $value, $params, $orig)
    {
        $max = (int)($params[0] ?? 0);
        if (is_string($value)) {
            return mb_strlen($value) <= $max ? true : "Maximum {$max} characters allowed";
        } elseif (is_numeric($value)) {
            return $value <= $max ? true : "Maximum {$max}";
        } elseif (is_array($value)) {
            return count($value) <= $max ? true : "Maximum {$max} items allowed";
        }
        return true;
    }
    protected function rule_in($path, $value, $params, $orig)
    {
        if (!in_array((string)$value, $params, true)) return "Invalid value";
        return true;
    }
    protected function rule_regex($path, $value, $params, $orig)
    {
        $pat = $params[0] ?? '';
        if (@preg_match($pat, '') === false) return "Invalid pattern";
        return preg_match($pat, (string)$value) ? true : "Invalid format";
    }

    protected function rule_confirmed($path, $value, $params, $orig)
    {
        $confirmPath = $path . '_confirmation';
        $other = $this->getValueByPath($this->data, $confirmPath, null);
        return $other === $value ? true : "Confirmation does not match";
    }

    // file rules expect $_FILES-like array
    protected function rule_file($path, $value, $params, $orig)
    {
        if (!is_array($value) || !isset($value['error'])) return "File required";
        if ($value['error'] !== UPLOAD_ERR_OK) return "File upload error";
        return true;
    }

    protected function rule_mimes($path, $value, $params, $orig)
    {
        if (!is_array($value) || !isset($value['type'])) return "Invalid file";
        $mime = strtolower($value['type']);
        foreach ($params as $p) {
            $p = strtolower($p);
            if (str_contains($mime, $p) || str_ends_with($value['name'] ?? '', '.' . $p)) return true;
        }
        return "Invalid file type";
    }

    protected function rule_maxfile($path, $value, $params, $orig)
    {
        $max = (int)($params[0] ?? 0);
        if (!is_array($value) || !isset($value['size'])) return "Invalid file";
        return ((int)$value['size'] <= $max) ? true : "File exceeds maximum size";
    }

    protected function rule_array($path, $value, $params, $orig)
    {
        return is_array($value) ? true : "Must be an array";
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
                [$rname,$rparams] = $this->parseRule($r);
                $method = 'rule_' . $rname;
                if (method_exists($this, $method)) {
                    $res = $this->{$method}($path . '.' . $i, $v, $rparams, $orig);
                    if ($res !== true) {
                        $this->addErrorFor($path . '.' . $i, $this->messageFor($orig, $rname, $res ?? "Invalid"));
                        return false;
                    }
                } elseif (isset(self::$customRules[$rname])) {
                    $res = call_user_func(self::$customRules[$rname], $path . '.' . $i, $v, $rparams, $this->data);
                    if ($res !== true) {
                        $this->addErrorFor($path . '.' . $i, $this->messageFor($orig, $rname, $res ?? "Invalid"));
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
        $val = $params[1] ?? null;
        if ($other === null) return true;
        $otherVal = $this->getValueByPath($this->data, $other, null);
        if ((string)$otherVal === (string)$val) {
            return !$this->isEmptyValue($value) ? true : "Field is required";
        }
        return true;
    }

    protected function rule_required_unless($path, $value, $params, $orig)
    {
        $other = $params[0] ?? null;
        $val = $params[1] ?? null;
        if ($other === null) return true;
        $otherVal = $this->getValueByPath($this->data, $other, null);
        if ((string)$otherVal !== (string)$val) {
            return !$this->isEmptyValue($value) ? true : "Field is required";
        }
        return true;
    }

    protected function rule_required_with($path, $value, $params, $orig)
    {
        foreach ($params as $p) {
            if ($this->getValueByPath($this->data, $p, null) !== null) {
                return !$this->isEmptyValue($value) ? true : "Field is required when {$p} present";
            }
        }
        return true;
    }

    protected function rule_required_without($path, $value, $params, $orig)
    {
        foreach ($params as $p) {
            if ($this->getValueByPath($this->data, $p, null) === null) {
                return !$this->isEmptyValue($value) ? true : "Field is required when {$p} is missing";
            }
        }
        return true;
    }

    protected function rule_exists($path, $value, $params, $orig)
    {
        $table = $params[0] ?? null;
        $col = $params[1] ?? $path;
        if (!$table) return true;
        try {
            $pdo = Database::getInstance()->getPDO();
            $sql = "SELECT COUNT(*) FROM `$table` WHERE `$col` = :val";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':val' => $value]);
            $count = (int)$stmt->fetchColumn();
            return $count > 0 ? true : "Does not exist";
        } catch (\Throwable $e) {
            return "Database lookup failed";
        }
    }

    protected function rule_unique($path, $value, $params, $orig)
    {
        $table = $params[0] ?? null;
        $col = $params[1] ?? $path;
        $except = $params[2] ?? null;
        if (!$table) return true;
        try {
            $pdo = Database::getInstance()->getPDO();
            $sql = "SELECT COUNT(*) FROM `$table` WHERE `$col` = :val";
            $p = [':val' => $value];
            if ($except !== null) {
                if (str_contains($except, ':')) {
                    [$exceptCol, $exceptVal] = explode(':', $except, 2);
                    $sql .= " AND `$exceptCol` != :except";
                    $p[':except'] = $exceptVal;
                } else {
                    $sql .= " AND id != :except";
                    $p[':except'] = $except;
                }
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($p);
            $count = (int)$stmt->fetchColumn();
            return $count === 0 ? true : "Already taken";
        } catch (\Throwable $e) {
            return "Database lookup failed";
        }
    }

    protected function rule_date($path, $value, $params, $orig)
    {
        if ($value === null) return true;
        if (strtotime($value) === false) return "Invalid date";
        return true;
    }

    protected function rule_before($path, $value, $params, $orig)
    {
        $target = $params[0] ?? null;
        if ($target === null) return true;
        $compare = $this->getValueByPath($this->data, $target, $target);
        $t1 = strtotime($value);
        $t2 = strtotime($compare);
        if ($t1 === false || $t2 === false) return "Invalid date comparison";
        return $t1 < $t2 ? true : "Must be before {$target}";
    }

    protected function rule_after($path, $value, $params, $orig)
    {
        $target = $params[0] ?? null;
        if ($target === null) return true;
        $compare = $this->getValueByPath($this->data, $target, $target);
        $t1 = strtotime($value);
        $t2 = strtotime($compare);
        if ($t1 === false || $t2 === false) return "Invalid date comparison";
        return $t1 > $t2 ? true : "Must be after {$target}";
    }

    /* -----------------------
     * Utility internals
     * ----------------------- */

    /** expand a field like "items.*.id" into actual data paths present in $data
     * returns array of concrete paths e.g. ['items.0.id','items.1.id'] or empty if not present
     */
    protected function expandFieldToPaths(string $field, array $data): array
    {
        if (strpos($field, '*') === false) {
            $exists = $this->getValueByPath($data, $field, '__MISSING__') !== '__MISSING__';
            return $exists ? [$field] : [];
        }

        $parts = explode('.', $field);
        $results = ['']; // prefix
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
        }

        $out = [];
        foreach ($results as $r) {
            if ($this->getValueByPath($data, $r, '__MISSING__') !== '__MISSING__') {
                $out[] = $r;
            }
        }
        return array_values(array_unique($out));
    }

    protected function getValueByPath(array $data, string $path, $default = null)
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

    /**
     * Set validated value into nested $this->validated structure.
     * This version converts numeric path segments to integers so arrays become indexed properly.
     */
    protected function setValidatedValue(string $path, $value): void
    {
        $parts = explode('.', $path);
        $ref =& $this->validated;
        foreach ($parts as $p) {
            // treat numeric keys as integers (so arrays are numeric arrays)
            if (is_string($p) && ctype_digit($p)) {
                $p = (int)$p;
            }
            if (!is_array($ref)) $ref = [];
            if (!array_key_exists($p, $ref)) $ref[$p] = [];
            $ref =& $ref[$p];
        }
        $ref = $value;
    }

    protected function parseRule($rule): array
    {
        if ($rule === '') return ['', []];
        if (is_callable($rule)) return [$rule, []];
        if (strpos((string)$rule, ':') === false) return [(string)$rule, []];
        [$name, $rest] = explode(':', (string)$rule, 2);
        $params = array_map('trim', explode(',', $rest));
        return [$name, $params];
    }

    protected function addErrorFor(string $path, string $message): void
    {
        $this->errors[$path][] = $message;
    }

    protected function messageFor(string $field, string $rule, ?string $default = null): string
    {
        if (isset($this->messages["{$field}.{$rule}"])) return $this->messages["{$field}.{$rule}"];
        if (isset($this->messages[$rule])) return $this->messages[$rule];
        return $default ?? "{$field} failed {$rule}";
    }

    protected function isEmptyValue($v): bool
    {
        return $v === null || $v === '' || (is_array($v) && count($v) === 0);
    }

    protected function hasRule(array $rules, string $name): bool
    {
        foreach ($rules as $r) {
            if (is_string($r) && (strpos($r, $name) === 0 || $r === $name)) return true;
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
        if ($this->hasAnyRequiredRule($rules)) return true;
        if ($this->hasRule($rules, 'sometimes')) return false;
        return false;
    }

    /**
     * Normalize validated structure:
     * - convert arrays with integer keys to sorted arrays
     * - reindex sequential integer-keyed arrays to 0..n-1 (array_values)
     * - recurse into nested arrays
     */
    protected function normalizeValidated($node)
    {
        if (!is_array($node)) return $node;

        // process children first
        foreach ($node as $k => $v) {
            $node[$k] = $this->normalizeValidated($v);
        }

        // if all keys are integers (or numeric strings already converted) then ensure they're ints
        $allKeys = array_keys($node);
        $intKeys = array_filter($allKeys, function($k){ return is_int($k) || (is_string($k) && ctype_digit($k)); });

        if (count($intKeys) === count($allKeys)) {
            // cast keys to int and sort by key
            $tmp = [];
            foreach ($node as $k => $v) {
                $intk = is_int($k) ? $k : (int)$k;
                $tmp[$intk] = $v;
            }
            ksort($tmp, SORT_NUMERIC);
            // reindex if keys are sequential starting at 0
            $keys = array_keys($tmp);
            $sequential = ($keys === range(0, count($tmp) - 1));
            return $sequential ? array_values($tmp) : $tmp;
        }

        return $node;
    }
}