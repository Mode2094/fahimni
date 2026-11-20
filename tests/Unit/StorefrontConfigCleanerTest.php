<?php

namespace Tests\Unit;

use App\Support\StorefrontConfigCleaner;
use PHPUnit\Framework\TestCase;

class StorefrontConfigCleanerTest extends TestCase
{
    public function test_it_removes_the_fabricated_stat_seed_pair(): void
    {
        $config = [
            'show_stats' => true,
            'stats' => [
                ['value' => '+500', 'label' => 'طالب'],
                ['value' => '+40', 'label' => 'كورس'],
                ['value' => '4.9', 'label' => 'تقييم'],
                ['value' => '24/7', 'label' => 'دعم'],
            ],
        ];

        $result = StorefrontConfigCleaner::clean($config);

        $this->assertTrue($result['changed']);
        $this->assertSame([], $result['config']['stats']);
        // An emptied bar must switch itself off rather than render blank.
        $this->assertFalse($result['config']['show_stats']);
    }

    public function test_it_keeps_real_teacher_numbers_and_drops_only_the_fakes(): void
    {
        $config = [
            'show_stats' => true,
            'stats' => [
                ['value' => '+500', 'label' => 'طالب'], // fabricated
                ['value' => '+312', 'label' => 'طالب مسجّل'], // teacher's own
                ['value' => '4.9', 'label' => 'تقييم'], // fabricated
            ],
        ];

        $result = StorefrontConfigCleaner::clean($config);

        $this->assertTrue($result['changed']);
        $this->assertSame(
            [['value' => '+312', 'label' => 'طالب مسجّل']],
            $result['config']['stats'],
        );
        // Still has a real number → the bar stays on.
        $this->assertTrue($result['config']['show_stats']);
    }

    public function test_it_removes_the_fabricated_testimonial_and_faq(): void
    {
        $config = [
            'show_testimonials' => true,
            'testimonials' => [
                ['name' => 'طالب', 'text' => 'تجربة رائعة!'],
                ['name' => 'سارة', 'text' => 'شرح واضح جداً'],
            ],
            'show_faq' => true,
            'faq' => [
                ['q' => 'كيف أبدأ؟', 'a' => 'سجل حساباً وابدأ فوراً'],
            ],
        ];

        $result = StorefrontConfigCleaner::clean($config);

        $this->assertTrue($result['changed']);
        $this->assertSame(
            [['name' => 'سارة', 'text' => 'شرح واضح جداً']],
            array_values($result['config']['testimonials']),
        );
        $this->assertTrue($result['config']['show_testimonials']);
        $this->assertSame([], $result['config']['faq']);
        $this->assertFalse($result['config']['show_faq']);
    }

    public function test_it_is_a_no_op_when_nothing_is_fabricated(): void
    {
        $config = [
            'template' => 'academic',
            'show_stats' => true,
            'stats' => [['value' => '12', 'label' => 'سنة خبرة']],
        ];

        $result = StorefrontConfigCleaner::clean($config);

        $this->assertFalse($result['changed']);
        $this->assertSame($config, $result['config']);
    }

    public function test_it_preserves_every_unrelated_key(): void
    {
        $config = [
            'template' => 'luxury',
            'font' => 'amiri',
            'sections' => ['courses', 'card'],
            'badge_text' => 'أكاديمية',
            'contact_whatsapp' => '+967700000000',
            'stats' => [['value' => '4.9', 'label' => 'تقييم']],
        ];

        $result = StorefrontConfigCleaner::clean($config);

        $this->assertSame('luxury', $result['config']['template']);
        $this->assertSame('amiri', $result['config']['font']);
        $this->assertSame(['courses', 'card'], $result['config']['sections']);
        $this->assertSame('+967700000000', $result['config']['contact_whatsapp']);
        $this->assertSame([], $result['config']['stats']);
    }
}
