<?php

namespace App\Domain\Keywords;

use InvalidArgumentException;

final readonly class KeywordClusterCandidate
{
    /**
     * @param  list<int>  $keywordIds
     * @param  list<array{id:int, normalized_text:string, intent:string}>  $snapshots
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public int $projectId,
        public string $name,
        public int $primaryKeywordId,
        public array $keywordIds,
        public KeywordIntent $intent,
        public IntentConfidence $confidence,
        public int $score,
        public array $snapshots,
        public array $evidence,
    ) {}

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['version' => 1, 'project_id' => $this->projectId, 'name' => $this->name,
            'primary_keyword_id' => $this->primaryKeywordId, 'keyword_ids' => $this->keywordIds,
            'intent' => $this->intent->value, 'confidence' => $this->confidence->value,
            'score' => $this->score, 'snapshots' => $this->snapshots, 'evidence' => $this->evidence];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['version'] ?? null) !== 1 || ! is_int($data['project_id'] ?? null) || ! is_string($data['name'] ?? null)
            || ! is_int($data['primary_keyword_id'] ?? null) || ! is_array($data['keyword_ids'] ?? null)
            || ! is_string($data['intent'] ?? null) || ! is_string($data['confidence'] ?? null)
            || ! is_int($data['score'] ?? null) || ! is_array($data['snapshots'] ?? null) || ! is_array($data['evidence'] ?? null)) {
            throw new InvalidArgumentException('Invalid cluster candidate payload.');
        }
        $ids = [];
        foreach ($data['keyword_ids'] as $id) {
            if (! is_int($id) || $id < 1) {
                throw new InvalidArgumentException('Invalid candidate member.');
            }
            $ids[] = $id;
        }
        $snapshots = [];
        foreach ($data['snapshots'] as $row) {
            if (! is_array($row) || ! is_int($row['id'] ?? null) || ! is_string($row['normalized_text'] ?? null) || ! is_string($row['intent'] ?? null)) {
                throw new InvalidArgumentException('Invalid candidate snapshot.');
            }
            $snapshots[] = ['id' => $row['id'], 'normalized_text' => $row['normalized_text'], 'intent' => $row['intent']];
        }

        return new self($data['project_id'], $data['name'], $data['primary_keyword_id'], $ids,
            KeywordIntent::from($data['intent']), IntentConfidence::from($data['confidence']), $data['score'], $snapshots, $data['evidence']);
    }
}
