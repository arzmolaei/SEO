<?php

namespace App\Domain\Keywords;

use App\Enums\KeywordStatus;
use InvalidArgumentException;

final readonly class KeywordGridQuery
{
    /** @var list<string> */
    public const INPUT_FIELDS = [
        'q',
        'source',
        'status',
        'volume_operator',
        'volume',
        'sort',
        'direction',
        'per_page',
    ];

    /** @var array<string, string> */
    private const VOLUME_OPERATORS = [
        'eq' => '=',
        'gt' => '>',
        'gte' => '>=',
        'lt' => '<',
        'lte' => '<=',
    ];

    /** @var list<string> */
    private const SORTS = ['keyword', 'volume', 'created_at'];

    public function __construct(
        public ?string $search,
        public ?string $source,
        public ?KeywordStatus $status,
        public ?string $volumeOperator,
        public ?float $volume,
        public string $sort,
        public string $direction,
        public int $perPage,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $search = self::optionalString($input['q'] ?? null, 200, 'q');
        $source = self::optionalString($input['source'] ?? null, 64, 'source');
        $statusValue = self::optionalString($input['status'] ?? null, 16, 'status');
        $status = $statusValue === null ? null : KeywordStatus::tryFrom($statusValue);

        if ($statusValue !== null && $status === null) {
            throw new InvalidArgumentException('Unsupported keyword status.');
        }

        $volumeOperator = self::optionalString($input['volume_operator'] ?? null, 8, 'volume_operator');

        if ($volumeOperator !== null && ! array_key_exists($volumeOperator, self::VOLUME_OPERATORS)) {
            throw new InvalidArgumentException('Unsupported volume operator.');
        }

        $volume = self::optionalNumber($input['volume'] ?? null);

        if (($volumeOperator === null) !== ($volume === null)) {
            throw new InvalidArgumentException('Volume and volume operator must be supplied together.');
        }

        $sort = self::optionalString($input['sort'] ?? null, 32, 'sort') ?? 'keyword';

        if (! in_array($sort, self::SORTS, true)) {
            throw new InvalidArgumentException('Unsupported keyword grid sort.');
        }

        $direction = self::optionalString($input['direction'] ?? null, 4, 'direction') ?? 'asc';

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Unsupported sort direction.');
        }

        $perPage = self::positiveInteger($input['per_page'] ?? 25, 'per_page');

        if ($perPage > 100) {
            throw new InvalidArgumentException('Keyword grid page size cannot exceed 100.');
        }

        return new self(
            search: $search,
            source: $source,
            status: $status,
            volumeOperator: $volumeOperator,
            volume: $volume,
            sort: $sort,
            direction: $direction,
            perPage: $perPage,
        );
    }

    public function sqlVolumeOperator(): ?string
    {
        return $this->volumeOperator === null ? null : self::VOLUME_OPERATORS[$this->volumeOperator];
    }

    /** @return array<string, string|float|int|null> */
    public function toArray(): array
    {
        return [
            'q' => $this->search,
            'source' => $this->source,
            'status' => $this->status?->value,
            'volume_operator' => $this->volumeOperator,
            'volume' => $this->volume,
            'sort' => $this->sort,
            'direction' => $this->direction,
            'per_page' => $this->perPage,
        ];
    }

    private static function optionalString(mixed $value, int $maxLength, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("{$field} must be a string.");
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException("{$field} is too long.");
        }

        return $value;
    }

    private static function optionalNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Volume must be numeric.');
        }

        $number = (float) $value;

        if ($number < 0) {
            throw new InvalidArgumentException('Volume cannot be negative.');
        }

        return $number;
    }

    private static function positiveInteger(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $integer = (int) $value;
        } else {
            throw new InvalidArgumentException("{$field} must be a positive integer.");
        }

        if ($integer < 1) {
            throw new InvalidArgumentException("{$field} must be a positive integer.");
        }

        return $integer;
    }
}
