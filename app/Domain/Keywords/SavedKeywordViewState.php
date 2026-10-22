<?php

namespace App\Domain\Keywords;

use InvalidArgumentException;

final readonly class SavedKeywordViewState
{
    public const SCHEMA_VERSION = 1;

    /** @var list<string> */
    private const COLUMNS = [
        'keyword',
        'normalized',
        'status',
        'volume',
        'source',
        'created_at',
    ];

    /**
     * @param  array<string, mixed>  $query
     * @param  list<string>  $columns
     */
    private function __construct(
        public array $query,
        public array $columns,
    ) {}

    public static function fromMixed(mixed $value): self
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Saved keyword view state must be an object.');
        }

        self::assertExactKeys($value, ['query', 'columns'], 'state');

        $query = $value['query'] ?? null;
        $columns = $value['columns'] ?? null;

        if (! is_array($query)) {
            throw new InvalidArgumentException('Saved keyword view query must be an object.');
        }

        self::assertAllowedKeys($query, KeywordGridQuery::INPUT_FIELDS, 'query');
        KeywordGridQuery::fromArray($query);

        if (! is_array($columns) || ! array_is_list($columns) || $columns === []) {
            throw new InvalidArgumentException('Saved keyword view columns must be a non-empty list.');
        }

        $validatedColumns = [];

        foreach ($columns as $column) {
            if (! is_string($column) || ! in_array($column, self::COLUMNS, true)) {
                throw new InvalidArgumentException('Unsupported saved keyword view column.');
            }

            if (in_array($column, $validatedColumns, true)) {
                throw new InvalidArgumentException('Saved keyword view columns cannot contain duplicates.');
            }

            $validatedColumns[] = $column;
        }

        if (! in_array('keyword', $validatedColumns, true)) {
            throw new InvalidArgumentException('Saved keyword views must include the keyword column.');
        }

        /** @var array<string, mixed> $query */
        return new self($query, $validatedColumns);
    }

    /** @return array{query: array<string, mixed>, columns: list<string>} */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'columns' => $this->columns,
        ];
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $expected
     */
    private static function assertExactKeys(array $value, array $expected, string $scope): void
    {
        $keys = array_keys($value);
        sort($keys);
        $sortedExpected = $expected;
        sort($sortedExpected);

        if ($keys !== $sortedExpected) {
            throw new InvalidArgumentException("Unsupported {$scope} fields.");
        }
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $allowed
     */
    private static function assertAllowedKeys(array $value, array $allowed, string $scope): void
    {
        foreach (array_keys($value) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException("Unsupported {$scope} field.");
            }
        }
    }
}
