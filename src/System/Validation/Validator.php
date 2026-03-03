<?php
declare(strict_types=1);

namespace System\Validation;

use System\Validation\Contracts\RuleInterface;


use System\Validation\Exceptions\ValidationException;
use System\Validation\Rules\Required;
use System\Validation\Rules\IsString;
use System\Validation\Rules\IsInteger;
use System\Validation\Rules\IsNumeric;
use System\Validation\Rules\IsBoolean;
use System\Validation\Rules\IsArray;
use System\Validation\Rules\Min;
use System\Validation\Rules\Max;
use System\Validation\Rules\MinLength;
use System\Validation\Rules\MaxLength;
use System\Validation\Rules\Between;
use System\Validation\Rules\Regex;
use System\Validation\Rules\Email;
use System\Validation\Rules\Phone;
use System\Validation\Rules\Mobile;
use System\Validation\Rules\Url;
use System\Validation\Rules\InSet;
use System\Validation\Rules\NotIn;
use System\Validation\Rules\Same;
use System\Validation\Rules\Different;
use System\Validation\Rules\Confirmed;
use System\Validation\Rules\Date;
use System\Validation\Rules\ZipCode;
use System\Validation\Rules\PinCode;
use System\Validation\Rules\FullName;
use System\Validation\Rules\Slug;
use System\Validation\Rules\Alpha;
use System\Validation\Rules\AlphaNum;
use System\Validation\Rules\AlphaDash;
use System\Validation\Rules\StartsWith;
use System\Validation\Rules\EndsWith;
use System\Validation\Rules\Ip;
use System\Validation\Rules\Uuid;
use System\Validation\Rules\Json;
use System\Validation\Rules\Digits;
use System\Validation\Rules\DigitsBetween;
use System\Validation\Rules\Lowercase;
use System\Validation\Rules\Uppercase;
use System\Validation\Rules\Gte;
use System\Validation\Rules\Lte;
use System\Validation\Rules\Decimal;
use System\Validation\Rules\Before;
use System\Validation\Rules\After;
use System\Validation\Rules\RequiredWith;
use System\Validation\Rules\RequiredWithout;
use System\Validation\Rules\Mac;
use System\Validation\Rules\HexColor;
use System\Validation\Rules\Pan;
use System\Validation\Rules\Aadhaar;
use System\Validation\Rules\Gstin;
use System\Validation\Rules\Ifsc;
use System\Validation\Rules\CreditCard;
use System\Validation\Rules\Distinct;
use System\Validation\Rules\PasswordStrength;
use System\Validation\Rules\Exists;
use System\Validation\Rules\Unique;
use System\Validation\Rules\RequiredIf;
use System\Validation\Rules\Mimes;
use System\Validation\Rules\MaxFileSize;
use System\Validation\Rules\Each;
use System\Validation\Rules\File;
use System\Validation\Rules\Honeypot;
use System\Validation\Rules\KeyExists;
use System\Validation\Rules\ProhibitedIf;
use System\Validation\Util\Arr;
use System\Validation\ErrorBag;
use System\Validation\ValidationResult;

/**
 * Enhanced Validator Class
 * 
 * Replaces the monolithic simple validator with a robust rule-based system.
 * Fully compatible with existing Validator::make() API.
 */
final class Validator
{
    private ?ValidationResult $result = null;

    /**
     * @param array<string,mixed> $data
     * @param array<string, string|array<int, string|RuleInterface>> $rules
     */
    public function __construct(
        private array $data,
        private array $rules = []
    ) {
        // Rules must be explicitly provided. If empty, no validation occurs.
    }

    public function rules(array $rules): self
    {
        $this->rules = $rules;
        return $this;
    }

    /**
     * Create a validator instance.
     * 
     * @param array<string,mixed> $data
     * @param array<string, string|array<int, string|RuleInterface>> $rules
     */
    public static function make(array $data, array $rules): self
    {
        // Instantiation - validation is lazy now, runs on first check
        return new self($data, $rules);
    }

    /**
     * Run validation and store result.
     */
    public function validate(): ValidationResult
    {
        if ($this->result) {
            return $this->result;
        }

        $errors = new ErrorBag();
        $validated = [];// flat dotted keys
        $validatedNested = [];
        $data = $this->data; // capture locally

        foreach ($this->rules as $field => $fieldRules) {
            $parsed = $this->normalizeRules($fieldRules);

            $hasKey = Arr::has($data, $field);
            $value = Arr::get($data, $field);

            // Modifiers logic
            if (in_array('sometimes', $parsed['modifiers'], true) && !$hasKey) {
                continue;
            }

            // Required check
            if (in_array('required', $parsed['modifiers'], true)) {
                $req = new Required();
                if (!$req->passes($field, $value, $data)) {
                    $errors->add($field, $req->message($field));
                    continue; // Skip further rules if required fails
                }
            } else {
                // Not required, skip if missing
                if (!$hasKey) continue;
            }

            // Nullable check
            if (in_array('nullable', $parsed['modifiers'], true)) {
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    $validated[$field] = null;
                    Arr::set($validatedNested, $field, null);
                    continue; // Skip rules if null allowed
                }
            }

            // Run rules
            $allPass = true;
            foreach ($parsed['rules'] as $rule) {
                if (!$rule->passes($field, $value, $data)) {
                    $errors->add($field, $rule->message($field));
                    $allPass = false;
                }
            }

            if ($allPass) {
                $validated[$field] = $value;
                Arr::set($validatedNested, $field, $value);
            }
        }

        $this->result = new ValidationResult($errors->isEmpty(), $errors, $validated, $validatedNested);
        return $this->result;
    }

    /**
     * Check if validation failed.
     * Ensures validation runs first.
     */
    public function fails(): bool
    {
        return !$this->validate()->ok();
    }

    /**
     * Check if validation passed.
     */
    public function passes(): bool
    {
        return $this->validate()->ok();
    }

    /**
     * Get all validation errors as [field => [msg, msg]].
     */
    public function errors(): array
    {
        return $this->validate()->errors()->toArray();
    }

    /**
     * Get the first error message.
     */
    public function firstError(): ?string
    {
        // Flatten errors or just peek first field
        $errs = $this->errors();
        if (empty($errs)) return null;
        
        $firstField = reset($errs);
        return $firstField[0] ?? null; // first message of first field
    }

    /**
     * Get validated data (only fields with rules that passed).
     */
    public function validated(): array
    {
        return $this->validate()->validated();
    }

    /**
     * Get nested validated data.
     */
    public function validatedNested(): array
    {
        return $this->validate()->validatedNested();
    }

    /**
     * Helper to throw on failure.
     */
    public function validateOrThrow(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->validate()->errors());
        }
        return $this->validated();
    }

    /**
     * Identical logic to reference for rule parsing.
     */
    private function normalizeRules(string|array $rules): array
    {
        $tokens = [];
        if (is_string($rules)) {
            $tokens = array_filter(array_map('trim', explode('|', $rules)));
        } else {
            foreach ($rules as $r) $tokens[] = $r;
        }

        $modifiers = [];
        $compiled = [];

        foreach ($tokens as $t) {
            if ($t instanceof RuleInterface) {
                $compiled[] = $t;
                continue;
            }

            $t = trim((string)$t);
            if ($t === '') continue;

            if (in_array($t, ['required','nullable','sometimes'], true)) {
                $modifiers[] = $t;
                continue;
            }

            $name = $t;
            $paramStr = null;
            if (str_contains($t, ':')) [$name, $paramStr] = explode(':', $t, 2);

            $compiled[] = $this->makeRule(strtolower(trim($name)), $paramStr);
        }

        return ['modifiers' => array_values(array_unique($modifiers)), 'rules' => $compiled];
    }

    private function makeRule(string $name, ?string $paramStr): RuleInterface
    {
        return match ($name) {
            'string' => new IsString(),
            'integer', 'int' => new IsInteger(),
            'numeric' => new IsNumeric(),
            'boolean', 'bool' => new IsBoolean(),
            'array' => new IsArray(),
            'min' => new Min($this->numParam($paramStr, 0)),
            'max' => new Max($this->numParam($paramStr, 0)),
            'min_length' => new MinLength((int)$this->numParam($paramStr, 0)),
            'max_length' => new MaxLength((int)$this->numParam($paramStr, 0)),
            'between' => $this->betweenRule($paramStr),
            'regex' => new Regex($this->strParam($paramStr, '/.*/')),
            'email' => new Email(),
            'phone' => new Phone(),
            'mobile' => new Mobile($this->strParam($paramStr, 'any')),
            'url' => new Url(),
            'in' => $this->inRule($paramStr),
            'not_in' => $this->notInRule($paramStr),
            'same' => new Same($this->strParam($paramStr, '')),
            'different' => new Different($this->strParam($paramStr, '')),
            'confirmed' => new Confirmed(),
            'date' => new Date($this->strParam($paramStr, 'Y-m-d')),
            'zip_code', 'zipcode' => new ZipCode($this->strParam($paramStr, 'any')),
            'pin_code', 'pincode' => new PinCode(),
            'full_name', 'fullname' => new FullName((int)$this->numParam($paramStr, 2)),
            'slug' => new Slug(),
            'alpha' => new Alpha($this->parseBool($paramStr, false)),
            'alpha_num' => new AlphaNum($this->parseBool($paramStr, false)),
            'alpha_dash' => new AlphaDash(),
            'starts_with' => $this->startsWithRule($paramStr),
            'ends_with' => $this->endsWithRule($paramStr),
            'ip' => $this->ipRule($paramStr),
            'uuid' => new Uuid($this->parseVersion($paramStr)),
            'json' => new Json(),
            'digits' => new Digits((int)$this->numParam($paramStr, 0)),
            'digits_between' => $this->digitsBetweenRule($paramStr),
            'lowercase' => new Lowercase(),
            'uppercase' => new Uppercase(),
            'gte' => new Gte($this->strParam($paramStr, '0')),
            'lte' => new Lte($this->strParam($paramStr, '0')),
            'decimal' => new Decimal((int)$this->numParam($paramStr, 2)),
            'before' => new Before($this->strParam($paramStr, '')),
            'after' => new After($this->strParam($paramStr, '')),
            'required_with' => $this->requiredWithRule($paramStr),
            'required_without' => $this->requiredWithoutRule($paramStr),
            'mac' => new Mac(),
            'hex_color' => new HexColor($this->parseBool($paramStr, true)),
            'pan' => new Pan(),
            'aadhaar' => new Aadhaar(),
            'gstin' => new Gstin(),
            'ifsc' => new Ifsc(),
            'credit_card' => new CreditCard($this->parseBool($paramStr, true)),
            'exists' => $this->existsRule($paramStr),
            'unique' => $this->uniqueRule($paramStr),
            'required_if' => $this->requiredIfRule($paramStr),
            'prohibited_if' => $this->prohibitedIfRule($paramStr),
            'mimes' => $this->mimesRule($paramStr),
            'file' => new File(),
            'max_file_size' => $this->maxFileSizeRule($paramStr),
            'distinct' => new Distinct($this->parseBool($paramStr, true)),
            'key_exists' => new KeyExists($this->strParam($paramStr, null) ? explode(',', $paramStr) : []),
            'honeypot' => new Honeypot(),
            'each' => new Each($this->strParam($paramStr, '')),
            'password_strength' => $this->passwordStrengthRule($paramStr),
            default => new class($name) implements RuleInterface {
                public function __construct(private string $name) {}
                public function passes(string $field, mixed $value, array $data): bool { return false; }
                public function message(string $field): string { return "Unknown validation rule: {$this->name}"; }
            }
        };
    }

    private function numParam(?string $paramStr, int $index): int|float
    {
        if ($paramStr === null) return 0;
        $parts = array_map('trim', explode(',', $paramStr));
        $v = $parts[$index] ?? '0';
        return str_contains($v, '.') ? (float)$v : (int)$v;
    }

    private function strParam(?string $paramStr, string $default): string
    {
        return $paramStr === null ? $default : trim($paramStr);
    }

    private function betweenRule(?string $paramStr): RuleInterface
    {
        $parts = $paramStr ? array_map('trim', explode(',', $paramStr)) : ['0','0'];
        $a = $parts[0] ?? '0';
        $b = $parts[1] ?? '0';
        $min = str_contains($a, '.') ? (float)$a : (int)$a;
        $max = str_contains($b, '.') ? (float)$b : (int)$b;
        return new Between($min, $max);
    }

    private function inRule(?string $paramStr): RuleInterface
    {
        $parts = $paramStr ? array_map('trim', explode(',', $paramStr)) : [];
        $parts = array_values(array_filter($parts, fn($x) => $x !== ''));
        return new InSet($parts);
    }

    private function notInRule(?string $paramStr): RuleInterface
    {
        $parts = $paramStr ? array_map('trim', explode(',', $paramStr)) : [];
        $parts = array_values(array_filter($parts, fn($x) => $x !== ''));
        return new NotIn($parts);
    }

    private function startsWithRule(?string $paramStr): RuleInterface
    {
        $parts = $paramStr ? array_map('trim', explode(',', $paramStr)) : [];
        $parts = array_values(array_filter($parts, fn($x) => $x !== ''));
        return new StartsWith($parts);
    }

    private function endsWithRule(?string $paramStr): RuleInterface
    {
        $parts = $paramStr ? array_map('trim', explode(',', $paramStr)) : [];
        $parts = array_values(array_filter($parts, fn($x) => $x !== ''));
        return new EndsWith($parts);
    }

    private function ipRule(?string $paramStr): RuleInterface
    {
        // Parse v4=true,v6=false or just 'v4' or 'v6'
        $allowV4 = true;
        $allowV6 = true;
        
        if ($paramStr) {
            $lower = strtolower(trim($paramStr));
            if ($lower === 'v4' || $lower === 'ipv4') {
                $allowV6 = false;
            } elseif ($lower === 'v6' || $lower === 'ipv6') {
                $allowV4 = false;
            }
        }
        
        return new Ip($allowV4, $allowV6);
    }

    private function parseVersion(?string $paramStr): ?int
    {
        if ($paramStr === null || $paramStr === '') return null;
        $v = (int)trim($paramStr);
        return ($v >= 1 && $v <= 5) ? $v : null;
    }

    private function digitsBetweenRule(?string $paramStr): RuleInterface
    {
        $parts = $paramStr ? array_map('trim', explode(',', $paramStr)) : ['0','0'];
        $min = (int)($parts[0] ?? '0');
        $max = (int)($parts[1] ?? '0');
        return new DigitsBetween($min, $max);
    }

    private function parseBool(?string $paramStr, bool $default): bool
    {
        if ($paramStr === null || $paramStr === '') return $default;
        $lower = strtolower(trim($paramStr));
        return in_array($lower, ['true', '1', 'yes', 'on'], true);
    }

    private function requiredWithRule(?string $paramStr): RuleInterface
    {
        $fields = $paramStr ? array_map('trim', explode(',', $paramStr)) : [];
        $fields = array_filter($fields, fn($f) => $f !== '');
        return new RequiredWith(array_values($fields));
    }

    private function requiredWithoutRule(?string $paramStr): RuleInterface
    {
        $fields = $paramStr ? array_map('trim', explode(',', $paramStr)) : [];
        $fields = array_filter($fields, fn($f) => $f !== '');
        return new RequiredWithout(array_values($fields));
    }

    private function passwordStrengthRule(?string $paramStr): RuleInterface
    {
        // usage: MinLen,MinUpper,MinLower,MinDigit,MinSymbol
        // defaults: 8,1,1,1,1
        $parts = $paramStr ? array_map('trim', explode(',', $paramStr)) : [];
        
        $len = isset($parts[0]) && $parts[0] !== '' ? (int)$parts[0] : 8;
        $upper = isset($parts[1]) && $parts[1] !== '' ? (int)$parts[1] : 1;
        $lower = isset($parts[2]) && $parts[2] !== '' ? (int)$parts[2] : 1;
        $digit = isset($parts[3]) && $parts[3] !== '' ? (int)$parts[3] : 1;
        $symbol = isset($parts[4]) && $parts[4] !== '' ? (int)$parts[4] : 1;

        return new PasswordStrength($len, $upper, $lower, $digit, $symbol);
    }

    private function existsRule(?string $paramStr): RuleInterface
    {
        // syntax: exists:table,column
        $parts = $paramStr ? array_map('trim', explode(',', $paramStr)) : [];
        $table = $parts[0] ?? '';
        $column = $parts[1] ?? 'id'; // Default column is id or should be field name?
        // if column is missing, it's safer to require it or default to 'id'. 
        // Ideally should default to field name but we don't have field name here easily without context.
        return new Exists($table, $column);
    }

    private function uniqueRule(?string $paramStr): RuleInterface
    {
        // syntax: unique:table,column,ignore_id,id_column
        $parts = $paramStr ? array_map('trim', explode(',', $paramStr)) : [];
        $table = $parts[0] ?? '';
        $column = $parts[1] ?? 'id';
        $ignoreId = isset($parts[2]) && $parts[2] !== '' && $parts[2] !== 'NULL' ? $parts[2] : null;
        $idColumn = $parts[3] ?? 'id'; 
        return new Unique($table, $column, $ignoreId, $idColumn);
    }

    private function requiredIfRule(?string $paramStr): RuleInterface
    {
        // syntax: required_if:otherField,val1,val2...
        $parts = $paramStr ? array_map('trim', explode(',', $paramStr)) : [];
        $otherField = array_shift($parts) ?? '';
        $values = $parts;
        return new RequiredIf($otherField, $values);
    }

    private function mimesRule(?string $paramStr): RuleInterface
    {
        // syntax: mimes:jpg,png,pdf
        $parts = $paramStr ? array_map('trim', explode(',', $paramStr)) : [];
        return new Mimes($parts);
    }

    private function maxFileSizeRule(?string $paramStr): RuleInterface
    {
        // syntax: max_file_size:1024 or max_file_size:2MB
        $val = trim($paramStr ?? '0');
        // Simple conversion if needed, but Rule expects int bytes.
        // Let's assume input is bytes or handle simple K/M suffixes
        $bytes = (int)$val;
        if (str_ends_with(strtoupper($val), 'K') || str_ends_with(strtoupper($val), 'KB')) $bytes = (int)$val * 1024;
        elseif (str_ends_with(strtoupper($val), 'M') || str_ends_with(strtoupper($val), 'MB')) $bytes = (int)$val * 1024 * 1024;
        
        return new MaxFileSize($bytes);
    }

    private function prohibitedIfRule(?string $paramStr): RuleInterface
    {
        // syntax: prohibited_if:otherField,val1,val2...
        $parts = $paramStr ? array_map('trim', explode(',', $paramStr)) : [];
        $otherField = array_shift($parts) ?? '';
        $values = $parts;
        return new ProhibitedIf($otherField, $values);
    }
}
