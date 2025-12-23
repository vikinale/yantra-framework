<?php
namespace System\Utilities;

/**
 * Sanitizer - small helper for sanitizing input arrays.
 *
 * Usage:
 *   $clean = Sanitizer::clean($data, [
 *     'name' => ['trim','strip_tags'],
 *     'email' => ['trim','lower'],
 *     'bio' => ['trim']
 *   ]);
 *
 * Rules are applied in order. If a field has no rule it's copied as-is.
 */
class Sanitizer
{
    public static function clean(array $data, array $rules = []): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (!array_key_exists($k, $rules)) {
                $out[$k] = $v;
                continue;
            }
            $ops = (array)$rules[$k];
            $val = $v;
            foreach ($ops as $op) {
                if (is_callable($op)) {
                    $val = $op($val);
                    continue;
                }
                switch ($op) {
                    case 'trim':
                        if (is_string($val)) $val = trim($val);
                        break;
                    case 'strip_tags':
                        if (is_string($val)) $val = strip_tags($val);
                        break;
                    case 'escape':
                        if (is_string($val)) $val = htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        break;
                    case 'lower':
                        if (is_string($val)) $val = mb_strtolower($val);
                        break;
                    case 'upper':
                        if (is_string($val)) $val = mb_strtoupper($val);
                        break;
                    case 'int':
                        $val = is_numeric($val) ? (int)$val : $val;
                        break;
                    case 'float':
                        $val = is_numeric($val) ? (float)$val : $val;
                        break;
                    case 'null_if_empty':
                        if ($val === '' || (is_array($val) && count($val) === 0)) $val = null;
                        break;
                    default:
                        // unknown token -> ignore
                        break;
                }
            }
            $out[$k] = $val;
        }
        return $out;
    }
}
