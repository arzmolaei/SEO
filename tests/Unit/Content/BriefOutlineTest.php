<?php

namespace Tests\Unit\Content;

use App\Domain\Content\BriefOutline;
use PHPUnit\Framework\TestCase;

class BriefOutlineTest extends TestCase
{
    public function test_secondary_keywords_become_sections_and_questions_an_faq_block(): void
    {
        $this->assertSame([
            'کفش ورزشی مردانه',
            'قیمت کفش ورزشی',
            BriefOutline::FAQ_HEADING,
            'بهترین کفش ورزشی کدام است؟',
            'کفش ورزشی چیست؟',
        ], BriefOutline::headings('کفش ورزشی', [
            'کفش ورزشی مردانه',
            'بهترین کفش ورزشی کدام است',
            'كفش ورزشي', // the primary again, in Arabic characters
            'قیمت کفش ورزشی',
            'کفش ورزشی چیست?',
            'کفش  ورزشی مردانه', // duplicate after normalization
        ]));
    }

    public function test_sections_and_questions_are_bounded(): void
    {
        $many = array_map(fn (int $n): string => 'بخش '.$n, range(1, 20));
        $questions = array_map(fn (int $n): string => 'چرا '.$n, range(1, 20));

        $headings = BriefOutline::headings('اصلی', [...$many, ...$questions]);

        $this->assertCount(BriefOutline::MAX_SECTIONS + 1 + BriefOutline::MAX_QUESTIONS, $headings);
    }

    public function test_question_detection_uses_whole_words(): void
    {
        $this->assertTrue(BriefOutline::isQuestion('سئو چیست'));
        $this->assertTrue(BriefOutline::isQuestion('How to start SEO'));
        $this->assertFalse(BriefOutline::isQuestion('کیف چرمی'), 'a word that merely starts like a question word is not a question');
        $this->assertSame([], BriefOutline::headings('تنها', []));
    }
}
