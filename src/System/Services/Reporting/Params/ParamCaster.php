<?php
declare(strict_types=1);

namespace System\Services\Reporting\Params;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use System\Services\Reporting\ReportContext;
use RuntimeException;

final class ParamCaster
{
    /**
     * Cast raw input into typed params according to schema.
     *
     * Casting behavior:
     * - If a param is missing, schema default is used.
     * - If a param is present but cannot be cast, returns InvalidValue.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function cast(ParamSchema $schema, array $input, ?ReportContext $ctx = null): array
    {
        $out = [];
        foreach ($schema->all() as $name => $def) {
            $present = array_key_exists($name, $input);
            $raw = $present ? $input[$name] : $def->default;
            $out[$name] = $this->castValue($raw, $def, $ctx, $present);
        }
        return $out;
    }

    private function castValue(mixed $raw, ParamDefinition $def, ?ReportContext $ctx, bool $wasProvided): mixed
    {
        if ($def->caster !== null) {
            try {
                return ($def->caster)($raw, $def, $ctx);
            } catch (\Throwable $e) {
                return $wasProvided ? new InvalidValue($raw, 'custom_caster') : $this->misconfiguredDefault($def, $e);
            }
        }

        if ($raw === null) {
            return null;
        }

        $fail = fn(string $reason) => $wasProvided ? new InvalidValue($raw, $reason) : $this->misconfiguredDefault($def, null);

        switch ($def->type) {
            case ParamType::STRING:
                if (is_string($raw)) return $raw;
                if (is_scalar($raw)) return (string)$raw;
                return $fail('string');

            case ParamType::INT:
                if (is_int($raw)) return $raw;
                if (is_float($raw)) return (int)$raw;
                if (is_string($raw)) {
                    $s = trim($raw);
                    if ($s === '') return null;
                    if (preg_match('/^-?\d+$/', $s) === 1) return (int)$s;
                    return $fail('int');
                }
                return $fail('int');

            case ParamType::FLOAT:
                if (is_float($raw)) return $raw;
                if (is_int($raw)) return (float)$raw;
                if (is_string($raw)) {
                    $s = trim($raw);
                    if ($s === '') return null;
                    if (is_numeric($s)) return (float)$s;
                    return $fail('float');
                }
                return $fail('float');

            case ParamType::BOOL:
                if (is_bool($raw)) return $raw;
                if (is_int($raw)) return $raw !== 0;
                if (is_string($raw)) {
                    $v = strtolower(trim($raw));
                    if ($v === '') return null;
                    if (in_array($v, ['1','true','yes','y','on'], true)) return true;
                    if (in_array($v, ['0','false','no','n','off'], true)) return false;
                    return $fail('bool');
                }
                return $fail('bool');

            case ParamType::DATE:
                $d = $this->castDate($raw, $ctx, true);
                return $d ?? $fail('date');

            case ParamType::DATETIME:
                $d = $this->castDate($raw, $ctx, false);
                return $d ?? $fail('datetime');

            case ParamType::ENUM:
                if (is_string($raw)) return $raw;
                if (is_scalar($raw)) return (string)$raw;
                return $fail('enum');

            case ParamType::ARRAY:
                $a = $this->castArray($raw, $def, $ctx);
                return $a ?? $fail('array');

            case ParamType::JSON:
                $j = $this->castJson($raw);
                return $j ?? $fail('json');

            default:
                return $raw;
        }
    }

    private function castDate(mixed $raw, ?ReportContext $ctx, bool $dateOnly): ?DateTimeImmutable
    {
        $tz = new DateTimeZone($ctx?->timezone() ?? 'UTC');

        if ($raw instanceof DateTimeImmutable) {
            $d = $raw->setTimezone($tz);
            return $dateOnly ? $d->setTime(0, 0, 0) : $d;
        }
        if ($raw instanceof DateTimeInterface) {
            $d = DateTimeImmutable::createFromInterface($raw)->setTimezone($tz);
            return $dateOnly ? $d->setTime(0, 0, 0) : $d;
        }

        if (!is_string($raw)) {
            return null;
        }

        $s = trim($raw);
        if ($s === '') return null;

        if ($dateOnly) {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s, $tz);
            if ($d instanceof DateTimeImmutable) return $d->setTimezone($tz);
        } else {
            // ISO-ish: 2026-02-10T11:22:33+05:30
            $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $s);
            if ($d instanceof DateTimeImmutable) return $d->setTimezone($tz);
        }

        try {
            $d = new DateTimeImmutable($s, $tz);
            return $dateOnly ? $d->setTime(0, 0, 0) : $d;
        } catch (\Throwable) {
            return null;
        }
    }

    private function castArray(mixed $raw, ParamDefinition $def, ?ReportContext $ctx): ?array
    {
        if ($raw === null) return [];

        $arr = null;

        if (is_array($raw)) {
            $arr = $raw;
        } elseif (is_string($raw)) {
            $s = trim($raw);
            if ($s === '') {
                $arr = [];
            } elseif ((str_starts_with($s, '[') && str_ends_with($s, ']')) || (str_starts_with($s, '{') && str_ends_with($s, '}'))) {
                $decoded = json_decode($s, true);
                $arr = is_array($decoded) ? $decoded : null;
            } else {
                $arr = array_values(array_filter(array_map('trim', explode(',', $s)), fn($x) => $x !== ''));
            }
        } elseif (is_scalar($raw)) {
            $arr = [(string)$raw];
        }

        if ($arr === null) {
            return null;
        }

        if ($def->itemsType === null) {
            return $arr;
        }

        $tmp = new ParamDefinition(name: $def->name, type: $def->itemsType);
        $out = [];
        foreach ($arr as $item) {
            $out[] = $this->castValue($item, $tmp, $ctx, true);
        }
        return $out;
    }

    private function castJson(mixed $raw): ?array
    {
        if (is_array($raw)) return $raw;
        if (is_object($raw)) return (array)$raw;
        if (!is_string($raw)) return null;

        $s = trim($raw);
        if ($s === '') return null;

        $decoded = json_decode($s, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function misconfiguredDefault(ParamDefinition $def, ?\Throwable $previous): never
    {
        $msg = "Misconfigured report schema default for param '{$def->name}' ({$def->type}).";
        throw new RuntimeException($msg, 0, $previous);
    }
}
