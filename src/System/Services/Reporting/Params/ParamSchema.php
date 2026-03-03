<?php
declare(strict_types=1);

namespace System\Services\Reporting\Params;

use InvalidArgumentException;

final class ParamSchema
{
    /** @var array<string, ParamDefinition> */
    private array $defs = [];

    /**
     * @param list<ParamDefinition> $definitions
     */
    public function __construct(array $definitions = [])
    {
        foreach ($definitions as $def) {
            $this->add($def);
        }
    }

    public function add(ParamDefinition $def): void
    {
        if (isset($this->defs[$def->name])) {
            throw new InvalidArgumentException("Duplicate param definition: {$def->name}");
        }
        $this->defs[$def->name] = $def;
    }

    /** @return array<string, ParamDefinition> */
    public function all(): array
    {
        return $this->defs;
    }

    public function has(string $name): bool
    {
        return isset($this->defs[$name]);
    }

    public function get(string $name): ParamDefinition
    {
        if (!isset($this->defs[$name])) {
            throw new InvalidArgumentException("Unknown param: {$name}");
        }
        return $this->defs[$name];
    }

    /**
     * Returns a new schema extended with standard pagination params: page, per_page.
     */
    public function withPagination(int $defaultPerPage = 50, int $maxPerPage = 500): self
    {
        $clone = new self(array_values($this->defs));
        foreach (self::paginationDefinitions($defaultPerPage, $maxPerPage) as $d) {
            if (!$clone->has($d->name)) {
                $clone->add($d);
            }
        }
        return $clone;
    }

    /**
     * @return list<ParamDefinition>
     */
    public static function paginationDefinitions(int $defaultPerPage = 50, int $maxPerPage = 500): array
    {
        return [
            new ParamDefinition(
                name: 'page',
                type: ParamType::INT,
                required: false,
                default: 1,
                label: 'Page',
                description: '1-based page index',
                min: 1
            ),
            new ParamDefinition(
                name: 'per_page',
                type: ParamType::INT,
                required: false,
                default: $defaultPerPage,
                label: 'Per Page',
                description: 'Items per page',
                min: 1,
                max: $maxPerPage
            ),
        ];
    }
}
