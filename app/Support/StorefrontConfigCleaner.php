<?php

namespace App\Support;

/**
 * Strips the platform's own fabricated marketing defaults out of a stored
 * teacher storefront config.
 *
 * Older onboarding/editor flows persisted the read-time placeholder stats,
 * testimonial and FAQ (the "+500 طالب / 4.9 تقييم" numbers and the
 * "تجربة رائعة!" quote) straight into `tenant_settings.metadata.site_config`.
 * Those are invented data, so the storefront must not show them — but we only
 * remove entries that EXACTLY match a known seed fingerprint. Anything a
 * teacher actually typed is left untouched, and unrelated config keys are
 * preserved verbatim.
 */
class StorefrontConfigCleaner
{
    /** [value, label] pairs that were only ever the platform's invented seeds. */
    private const FAKE_STATS = [
        ['+500', 'طالب'],
        ['+40', 'كورس'],
        ['4.9', 'تقييم'],
        ['24/7', 'دعم'],
    ];

    private const FAKE_TESTIMONIAL = ['طالب', 'تجربة رائعة!'];

    private const FAKE_FAQ = ['كيف أبدأ؟', 'سجل حساباً وابدأ فوراً'];

    /**
     * Return the cleaned config plus whether anything actually changed.
     *
     * @param  array<string, mixed>  $config
     * @return array{config: array<string, mixed>, changed: bool}
     */
    public static function clean(array $config): array
    {
        $changed = false;

        if (isset($config['stats']) && is_array($config['stats'])) {
            $kept = array_values(array_filter($config['stats'], fn ($s) => ! self::isFakeStat($s)));
            if (count($kept) !== count($config['stats'])) {
                $config['stats'] = $kept;
                // Empty trust bar must not stay "on" and render a blank block.
                if ($kept === [] && ($config['show_stats'] ?? false)) {
                    $config['show_stats'] = false;
                }
                $changed = true;
            }
        }

        if (isset($config['testimonials']) && is_array($config['testimonials'])) {
            $kept = array_values(array_filter($config['testimonials'], fn ($t) => ! self::isFakeTestimonial($t)));
            if (count($kept) !== count($config['testimonials'])) {
                $config['testimonials'] = $kept;
                if ($kept === [] && ($config['show_testimonials'] ?? false)) {
                    $config['show_testimonials'] = false;
                }
                $changed = true;
            }
        }

        if (isset($config['faq']) && is_array($config['faq'])) {
            $kept = array_values(array_filter($config['faq'], fn ($f) => ! self::isFakeFaq($f)));
            if (count($kept) !== count($config['faq'])) {
                $config['faq'] = $kept;
                if ($kept === [] && ($config['show_faq'] ?? false)) {
                    $config['show_faq'] = false;
                }
                $changed = true;
            }
        }

        return ['config' => $config, 'changed' => $changed];
    }

    private static function isFakeStat(mixed $stat): bool
    {
        if (! is_array($stat)) {
            return false;
        }

        $pair = [(string) ($stat['value'] ?? ''), (string) ($stat['label'] ?? '')];

        return in_array($pair, self::FAKE_STATS, true);
    }

    private static function isFakeTestimonial(mixed $item): bool
    {
        if (! is_array($item)) {
            return false;
        }

        return (string) ($item['name'] ?? '') === self::FAKE_TESTIMONIAL[0]
            && (string) ($item['text'] ?? '') === self::FAKE_TESTIMONIAL[1];
    }

    private static function isFakeFaq(mixed $item): bool
    {
        if (! is_array($item)) {
            return false;
        }

        return (string) ($item['q'] ?? '') === self::FAKE_FAQ[0]
            && (string) ($item['a'] ?? '') === self::FAKE_FAQ[1];
    }
}
