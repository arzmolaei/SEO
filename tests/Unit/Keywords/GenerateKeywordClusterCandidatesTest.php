<?php

namespace Tests\Unit\Keywords;

use App\Domain\Keywords\IntentConfidence;
use App\Domain\Keywords\KeywordIntent;
use App\Services\Keywords\GenerateKeywordClusterCandidates;
use Tests\TestCase;

class GenerateKeywordClusterCandidatesTest extends TestCase
{
    public function test_determinism_includes_input_order_and_primary_tie_break(): void
    {
        $rows = [$this->row(8, 'خرید لپ تاپ'), $this->row(2, 'قیمت لپ تاپ')];
        $engine = app(GenerateKeywordClusterCandidates::class);
        $a = $engine->generate($rows);
        $b = $engine->generate(array_reverse($rows));
        $this->assertSame(array_map(fn ($c) => $c->toArray(), $a), array_map(fn ($c) => $c->toArray(), $b));
        $this->assertSame([2, 8], $a[0]->keywordIds);
        $this->assertSame(2, $a[0]->primaryKeywordId);
        $this->assertNotEmpty($a[0]->evidence['primary_reason']);
    }

    public function test_persian_forms_and_zwnj_share_features_without_mutating_input(): void
    {
        $rows = [$this->row(1, 'خرید كيف چرمي'), $this->row(2, "قیمت کیف\u{200C}چرمی")];
        $result = app(GenerateKeywordClusterCandidates::class)->generate($rows);
        $this->assertCount(1, $result);
        $this->assertSame(['کیف', 'چرمی'], $result[0]->evidence['shared_tokens']);
        $this->assertSame('خرید كيف چرمي', $rows[0]['text']);
    }

    public function test_containment_has_explainable_score_and_requires_review(): void
    {
        $rows = [$this->row(1, 'خرید لپ تاپ'), $this->row(2, 'خرید لپ تاپ گیمینگ')];
        $candidate = app(GenerateKeywordClusterCandidates::class)->generate($rows)[0];
        $this->assertSame(IntentConfidence::Medium, $candidate->confidence);
        $this->assertSame('prefix_containment', $candidate->evidence['relation']);
        $this->assertSame(75, $candidate->score);
        $this->assertSame($candidate->score, array_sum($candidate->evidence['score_components']));
    }

    public function test_stop_words_and_one_generic_token_do_not_group_unrelated_keywords(): void
    {
        $rows = [$this->row(1, 'برای خرید در'), $this->row(2, 'از به برای'),
            $this->row(3, 'خرید کفش ورزشی'), $this->row(4, 'خرید بیمه خودرو')];
        $this->assertSame([], app(GenerateKeywordClusterCandidates::class)->generate($rows));
    }

    public function test_conflicting_and_unknown_intents_never_receive_high_confidence(): void
    {
        foreach ([KeywordIntent::Informational, KeywordIntent::Unknown] as $intent) {
            $candidate = app(GenerateKeywordClusterCandidates::class)->generate([
                $this->row(1, 'خرید لپ تاپ'), $this->row(2, 'آموزش لپ تاپ', $intent),
            ])[0];
            $this->assertSame(IntentConfidence::Medium, $candidate->confidence);
            $this->assertSame(0, $candidate->evidence['score_components']['intent']);
        }
    }

    public function test_exact_features_with_compatible_intent_can_be_high(): void
    {
        $candidate = app(GenerateKeywordClusterCandidates::class)->generate([
            $this->row(1, 'خرید لپ تاپ'), $this->row(2, 'قیمت لپ تاپ'),
        ])[0];
        $this->assertSame(IntentConfidence::High, $candidate->confidence);
        $this->assertSame(100, $candidate->score);
    }

    public function test_large_unrelated_corpus_does_not_use_all_pairs(): void
    {
        $rows = (function () {
            for ($i = 1; $i <= 10000; $i++) {
                yield $this->row($i, "موضوع{$i} محصول{$i}");
            }
        })();
        $start = microtime(true);
        $this->assertSame([], app(GenerateKeywordClusterCandidates::class)->generate($rows));
        $this->assertLessThan(5, microtime(true) - $start, 'Gross quadratic regression on a 10k corpus.');
    }

    private function row(int $id, string $text, KeywordIntent $intent = KeywordIntent::Transactional): array
    {
        return ['id' => $id, 'project_id' => 1, 'text' => $text, 'intent' => $intent];
    }
}
