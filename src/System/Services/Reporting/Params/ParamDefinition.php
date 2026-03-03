<?php
declare(strict_types=1);

namespace System\Services\Reporting\Params;

use InvalidArgumentException;

final class ParamDefinition
{
    public string $name;
    public string $type;
    public bool $required;
    public mixed $default;
    public ?string $label;
    public ?string $description;

    /** @var list<string>|null */
    public ?array $allowed;

    public int|float|null $min;
    public int|float|null $max;

    public ?int $minLength;
    public ?int $maxLength;

    public ?string $pattern;

    /** For ARRAY params, optional scalar type for items (ParamType::*). */
    public ?string $itemsType;

    /**
     * Optional custom caster.
     * Signature: fn(mixed $raw, ParamDefinition $def, ?\Services\Reporting\ReportContext $ctx): mixed
     */
    public $caster;

    /**
     * Optional custom validators.
     * Each callable should return null if OK, or an error string.
     * Signature: fn(mixed $value, array $params, ParamDefinition $def, ?\Services\Reporting\ReportContext $ctx): ?string
     *
     * @var list<callable>
     */
    public array $validators;

    /**
     * @param list<string>|null $allowed
     * @param list<callable> $validators
     */
    public function __construct(
        string $name,
        string $type,
        bool $required = false,
        mixed $default = null,
        ?string $label = null,
        ?string $description = null,
        ?array $allowed = null,
        int|float|null $min = null,
        int|float|null $max = null,
        ?int $minLength = null,
        ?int $maxLength = null,
        ?string $pattern = null,
        ?string $itemsType = null,
        ?callable $caster = null,
        array $validators = []
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('Param name cannot be empty');
        }
        if (!in_array($type, ParamType::all(), true)) {
            throw new InvalidArgumentException("Unsupported param type: {$type}");
        }
        if ($itemsType !== null && $type !== ParamType::ARRAY) {
            throw new InvalidArgumentException('itemsType is only valid when type is array');
        }

        $this->name = $name;
        $this->type = $type;
        $this->required = $required;
        $this->default = $default;
        $this->label = $label;
        $this->description = $description;
        $this->allowed = $allowed;
        $this->min = $min;
        $this->max = $max;
        $this->minLength = $minLength;
        $this->maxLength = $maxLength;
        $this->pattern = $pattern;
        $this->itemsType = $itemsType;
        $this->caster = $caster;
        $this->validators = $validators;
    }
}
