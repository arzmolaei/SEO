<?php

namespace Tests\Unit\Keywords;

use App\Domain\Keywords\IntentConfidence;
use App\Domain\Keywords\KeywordIntent;
use App\Services\Keywords\ClassifyKeywordIntent;
use Tests\TestCase;

class ClassifyKeywordIntentTest extends TestCase
{
    /**
     * @dataProvider clearIntentCorpus
     */
    public function test_it_classifies_clear_persian_intent_signals(
        string $text,
        KeywordIntent $expectedIntent,
        IntentConfidence $expectedConfidence,
        string $expectedRuleId,
    ): void {
        $classification = app(ClassifyKeywordIntent::class)->classify($text);

        $this->assertSame($expectedIntent, $classification->intent);
        $this->assertSame($expectedConfidence, $classification->confidence);
        $this->assertContains($expectedRuleId, $classification->matchedRuleIds);
        $this->assertNotEmpty($classification->evidence);
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
     * @return iterable<string, array{string, KeywordIntent, IntentConfidence, string}>
     */
    public static function clearIntentCorpus(): iterable
    {
        yield 'transactional buy' => [
            'خرید لپ تاپ',
            KeywordIntent::Transactional,
            IntentConfidence::High,
            'transactional.buy',
        ];

        yield 'commercial best' => [
            'بهترین لپ تاپ گیمینگ',
            KeywordIntent::Commercial,
            IntentConfidence::High,
            'commercial.best',
        ];

        yield 'informational tutorial' => [
            'آموزش سئو تکنیکال',
            KeywordIntent::Informational,
            IntentConfidence::High,
            'informational.tutorial',
        ];

        yield 'navigational login' => [
            'ورود به دیجی کالا',
            KeywordIntent::Navigational,
            IntentConfidence::High,
            'navigational.login',
        ];

        yield 'local near me' => [
            'تعمیر لپ تاپ نزدیک من',
            KeywordIntent::Local,
            IntentConfidence::High,
            'local.near_me',
        ];
    }
}
