<?php
declare(strict_types=1);

namespace System\Services\Reporting\Definition;

use InvalidArgumentException;

final class ReportMetadata
{
    public readonly string $key;
    public readonly string $title;
    public readonly string $description;
    public readonly ?string $category;

    /** @var list<string> */
    public readonly array $tags;

    /** @var list<string> */
    public readonly array $permissions;

    public readonly ?int $cacheTtlSeconds;
    public readonly string $version;

    /**
     * @param list<string> $tags
     * @param list<string> $permissions
     */
    public function __construct(
        string $key,
        string $title,
        string $description = '',
        ?string $category = null,
        array $tags = [],
        array $permissions = [],
        ?int $cacheTtlSeconds = null,
        string $version = '1'
    ) {
        if ($key === '') {
            throw new InvalidArgumentException('Report key cannot be empty');
        }
        if ($title === '') {
            throw new InvalidArgumentException('Report title cannot be empty');
        }
        $this->key = $key;
        $this->title = $title;
        $this->description = $description;
        $this->category = $category;
        $this->tags = array_values(array_map('strval', $tags));
        $this->permissions = array_values(array_map('strval', $permissions));
        $this->cacheTtlSeconds = $cacheTtlSeconds;
        $this->version = $version;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'tags' => $this->tags,
            'permissions' => $this->permissions,
            'cache_ttl_seconds' => $this->cacheTtlSeconds,
            'version' => $this->version,
        ];
    }
}
