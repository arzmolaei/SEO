<?php

namespace Tests\Unit\Keywords;

use App\Domain\Keywords\IntentConfidence;
use App\Domain\Keywords\KeywordIntent;
use App\Services\Keywords\ClassifyKeywordIntent;
use Tests\TestCase;

class ClassifyKeywordIntentTest extends TestCase
{
    public function test_it_classifies_clear_persian_intent_signals(): void
    {
        foreach ($this->clearIntentCorpus() as $label => [$text, $expectedIntent, $expectedConfidence, $expectedRuleId]) {
            $classification = app(ClassifyKeywordIntent::class)->classify($text);

            $this->assertSame($expectedIntent, $classification->intent, $label);
            $this->assertSame($expectedConfidence, $classification->confidence, $label);
            $this->assertContains($expectedRuleId, $classification->matchedRuleIds, $label);
            $this->assertNotEmpty($classification->evidence, $label);
        }
    }

    public function test_conflicting_strong_signals_are_mixed_instead_of_false_certainty(): void
    {
        $classification = app(ClassifyKeywordIntent::class)->classify('بهترین قیمت لپ تاپ');

        $this->assertSame(KeywordIntent::Mixed, $classification->intent);
        $this->assertSame(IntentConfidence::Medium, $classification->confidence);
        $this->assertContains('commercial.best', $classification->matchedRuleIds);
        $this->assertContains('transactional.price', $classification->matchedRuleIds);
    }

    public function test_generic_phrase_without_signal_remains_unknown_and_low_confidence(): void
    {
        $classification = app(ClassifyKeywordIntent::class)->classify('لپ تاپ');

        $this->assertSame(KeywordIntent::Unknown, $classification->intent);
        $this->assertSame(IntentConfidence::Low, $classification->confidence);
        $this->assertSame([], $classification->matchedRuleIds);
    }

    public function test_it_normalizes_for_classification_without_mutating_original_text(): void
    {
        $original = 'خريد لپ تاپ';
        $classification = app(ClassifyKeywordIntent::class)->classify($original);

        $this->assertSame($original, $classification->originalText);
        $this->assertSame('خرید لپ تاپ', $classification->normalizedText);
        $this->assertSame(KeywordIntent::Transactional, $classification->intent);
    }

    public function test_repeated_classification_is_deterministic(): void
    {
        $service = app(ClassifyKeywordIntent::class);

        $first = $service->classify('تعمیر لپ تاپ نزدیک من');
        $second = $service->classify('تعمیر لپ تاپ نزدیک من');

        $this->assertSame($first->toArray(), $second->toArray());
    }

    /**
     * @return array<string, array{string, KeywordIntent, IntentConfidence, string}>
     */
    private function clearIntentCorpus(): array
    {
        return [
            'transactional buy' => [
                'خرید لپ تاپ',
                KeywordIntent::Transactional,
                IntentConfidence::High,
                'transactional.buy',
            ],
            'commercial best' => [
                'بهترین لپ تاپ گیمینگ',
                KeywordIntent::Commercial,
                IntentConfidence::High,
                'commercial.best',
            ],
            'informational tutorial' => [
                'آموزش سئو تکنیکال',
                KeywordIntent::Informational,
                IntentConfidence::High,
                'informational.tutorial',
            ],
            'navigational login' => [
                'ورود به دیجی کالا',
                KeywordIntent::Navigational,
                IntentConfidence::High,
                'navigational.login',
            ],
            'local near me' => [
                'تعمیر لپ تاپ نزدیک من',
                KeywordIntent::Local,
                IntentConfidence::High,
                'local.near_me',
            ],
        ];
    }
}
