<?php
declare(strict_types=1);

namespace System\Services\Reporting\Params;

use DateTimeInterface;
use System\Services\Reporting\Exceptions\ReportValidationException;
use System\Services\Reporting\ReportContext;

final class ParamValidator
{
    /**
     * Validate typed params.
     *
     * @param array<string, mixed> $params
     * @return array<string, list<string>>
     */
    public function validate(ParamSchema $schema, array $params, ?ReportContext $ctx = null): array
    {
        $errors = [];

        foreach ($schema->all() as $name => $def) {
            $v = $params[$name] ?? null;
            $errs = $this->validateOne($def, $v, $params, $ctx);
            if ($errs !== []) {
                $errors[$name] = $errs;
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $rawInput
     */
    public function validateOrThrow(ParamSchema $schema, array $params, ?ReportContext $ctx = null, array $rawInput = []): void
    {
        $errors = $this->validate($schema, $params, $ctx);

        // Improve JSON error clarity: if raw had a value but cast resulted in InvalidValue
        // (handled below) OR null (optional JSON), caller can still choose to treat it as error
        // by marking required=true.

        if ($errors !== []) {
            throw new ReportValidationException($errors, $rawInput);
        }
    }

    /**
     * @param array<string, mixed> $allParams
     * @return list<string>
     */
    private function validateOne(ParamDefinition $def, mixed $value, array $allParams, ?ReportContext $ctx): array
    {
        $errors = [];

        if ($value instanceof InvalidValue) {
            // Provide type-specific message where possible
            $errors[] = $this->invalidMessage($def->type);
            return $errors;
        }

        if ($def->required && $this->isEmpty($value)) {
            $errors[] = 'This field is required.';
            return $errors;
        }

        if ($this->isEmpty($value)) {
            return [];
        }

        if (!$this->typeMatches($def, $value)) {
            $errors[] = 'Invalid value type.';
            return $errors;
        }

        if ($def->allowed !== null && $def->allowed !== []) {
            $sv = is_scalar($value) ? (string)$value : '';
            if (!in_array($sv, $def->allowed, true)) {
                $errors[] = 'Value is not allowed.';
            }
        }

        if ($def->type === ParamType::INT) {
            if (!$this->isValidInt($value)) {
                $errors[] = $this->invalidMessage(ParamType::INT);
                return $errors;
            }
            $num = (float)$value;
            if ($def->min !== null && $num < (float)$def->min) {
                $errors[] = "Must be >= {$def->min}.";
            }
            if ($def->max !== null && $num > (float)$def->max) {
                $errors[] = "Must be <= {$def->max}.";
            }
        }

        if ($def->type === ParamType::FLOAT) {
            if (!$this->isValidFloat($value)) {
                $errors[] = $this->invalidMessage(ParamType::FLOAT);
                return $errors;
            }
            $num = (float)$value;
            if ($def->min !== null && $num < (float)$def->min) {
                $errors[] = "Must be >= {$def->min}.";
            }
            if ($def->max !== null && $num > (float)$def->max) {
                $errors[] = "Must be <= {$def->max}.";
            }
        }

        if ($def->type === ParamType::STRING && is_string($value)) {
            $len = $this->strlen($value);
            if ($def->minLength !== null && $len < $def->minLength) {
                $errors[] = "Must be at least {$def->minLength} characters.";
            }
            if ($def->maxLength !== null && $len > $def->maxLength) {
                $errors[] = "Must be at most {$def->maxLength} characters.";
            }
        }

        if ($def->pattern !== null && is_string($value)) {
            if (@preg_match($def->pattern, '') === false) {
                $errors[] = 'Invalid validation pattern (misconfigured report).';
            } elseif (preg_match($def->pattern, $value) !== 1) {
                $errors[] = 'Invalid format.';
            }
        }

        if ($def->type === ParamType::ARRAY && $def->itemsType !== null && is_array($value)) {
            $tmp = new ParamDefinition(name: $def->name, type: $def->itemsType);
            foreach ($value as $idx => $item) {
                if ($item instanceof InvalidValue) {
                    $errors[] = "Invalid item value at index {$idx}.";
                    break;
                }
                if (!$this->typeMatches($tmp, $item) && !$this->isEmpty($item)) {
                    $errors[] = "Invalid item type at index {$idx}.";
                    break;
                }
            }
        }

        foreach ($def->validators as $validator) {
            try {
                $msg = $validator($value, $allParams, $def, $ctx);
                if (is_string($msg) && $msg !== '') {
                    $errors[] = $msg;
                }
            } catch (\Throwable) {
                $errors[] = 'Validator error (misconfigured report).';
                break;
            }
        }

        return $errors;
    }

    /**
     * A genuine integer for the INT type: a real PHP int, or a string that is
     * strictly an integer literal (optional sign, digits only). Rejects floats,
     * numeric strings like "10.5"/"1e3", hex/octal notations, and non-scalars,
     * so range checks never run against a coerced value.
     */
    private function isValidInt(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }
        if (is_string($value)) {
            return preg_match('/^[+-]?\d+$/', $value) === 1;
        }
        return false;
    }

    /**
     * A genuine number for the FLOAT type: a non-array numeric scalar. Rejects
     * arrays, bools, objects and non-numeric strings so range checks only run
     * against a real numeric value.
     */
    private function isValidFloat(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return true;
        }
        if (is_string($value)) {
            return is_numeric($value);
        }
        return false;
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null) return true;
        if ($value instanceof InvalidValue) return false;
        if (is_string($value)) return trim($value) === '';
        if (is_array($value)) return $value === [];
        return false;
    }

    private function typeMatches(ParamDefinition $def, mixed $value): bool
    {
        switch ($def->type) {
            case ParamType::STRING:
            case ParamType::ENUM:
                return is_string($value) || is_numeric($value);
            case ParamType::INT:
                return is_int($value);
            case ParamType::FLOAT:
                return is_float($value) || is_int($value);
            case ParamType::BOOL:
                return is_bool($value);
            case ParamType::DATE:
            case ParamType::DATETIME:
                return $value instanceof DateTimeInterface;
            case ParamType::ARRAY:
                return is_array($value);
            case ParamType::JSON:
                return is_array($value);
            default:
                return true;
        }
    }

    private function invalidMessage(string $type): string
    {
        return match ($type) {
            ParamType::INT => 'Invalid integer.',
            ParamType::FLOAT => 'Invalid number.',
            ParamType::BOOL => 'Invalid boolean.',
            ParamType::DATE => 'Invalid date (expected YYYY-MM-DD).',
            ParamType::DATETIME => 'Invalid datetime.',
            ParamType::JSON => 'Invalid JSON.',
            ParamType::ARRAY => 'Invalid list.',
            default => 'Invalid value.',
        };
    }

    private function strlen(string $s): int
    {
        return function_exists('mb_strlen') ? (int)mb_strlen($s) : strlen($s);
    }
}
