<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class ProductDescription
{
    public const MAX_PLAIN_LENGTH = 5000;

    public const MAX_HTML_LENGTH = 20000;

    public const ALLOWED_ELEMENTS = ['p', 'br', 'strong', 'em', 'b', 'i', 'ul', 'ol', 'li'];

    private const UNWRAPPED_ELEMENTS = [
        'a', 'abbr', 'code', 'del', 'font', 'mark', 'q', 's', 'small', 'span', 'strike', 'sub', 'sup', 'u',
    ];

    private const BLOCK_PATTERN = '#<\s*(/?)\s*(?:h[1-6]|div|blockquote|pre|section|article)\b[^>]*>#i';

    private const TAG_PATTERN = '#</?(?:p|br|strong|em|b|i|ul|ol|li)(?:\s[^<>]*)?/?>#i';

    private static ?HtmlSanitizer $sanitizer = null;

    public static function sanitize(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        $html = self::isHtml($html)
            ? (preg_replace(self::BLOCK_PATTERN, '<$1p>', $html) ?? $html)
            : self::paragraphsFrom($html);

        $clean = trim(self::sanitizer()->sanitize($html));
        $clean = preg_replace('#<p>(?:\s|&nbsp;|<br\s*/?>)*</p>#i', '', $clean) ?? $clean;

        return self::plainText($clean) === '' ? '' : $clean;
    }

    public static function plainText(string $stored): string
    {
        if (! self::isHtml($stored)) {
            return self::trim($stored);
        }

        $text = preg_replace(
            ['#<li\b[^>]*>\s*<p\b[^>]*>#i', '#</p>\s*</li>#i', '#<br\s*/?>#i', '#</p>#i', '#</(li|ul|ol)>#i'],
            ['<li>', '</li>', "\n", "\n\n", "\n"],
            $stored,
        ) ?? $stored;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace(["#[ \t]+\n#u", "#\n{3,}#"], ["\n", "\n\n"], $text) ?? $text;

        return self::trim($text);
    }

    public static function length(string $stored): int
    {
        return mb_strlen(str_replace("\n", '', self::plainText($stored)));
    }

    public static function toHtml(string $stored): string
    {
        return self::sanitize($stored);
    }

    private static function isHtml(string $value): bool
    {
        return preg_match(self::TAG_PATTERN, $value) === 1;
    }

    private static function trim(string $text): string
    {
        return preg_replace('#^[\s\x{00A0}]+|[\s\x{00A0}]+$#u', '', $text) ?? trim($text);
    }

    private static function paragraphsFrom(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        $blocks = preg_split("#\n\s*\n#", $text) ?: [$text];

        $paragraphs = array_map(
            fn (string $block): string => '<p>'.str_replace("\n", '<br>', e(trim($block))).'</p>',
            array_filter($blocks, fn (string $block): bool => trim($block) !== ''),
        );

        return implode('', $paragraphs);
    }

    private static function sanitizer(): HtmlSanitizer
    {
        if (self::$sanitizer instanceof HtmlSanitizer) {
            return self::$sanitizer;
        }

        $config = (new HtmlSanitizerConfig)->withMaxInputLength(self::MAX_HTML_LENGTH);

        foreach (self::ALLOWED_ELEMENTS as $element) {
            $config = $config->allowElement($element);
        }

        foreach (self::UNWRAPPED_ELEMENTS as $element) {
            $config = $config->blockElement($element);
        }

        return self::$sanitizer = new HtmlSanitizer($config);
    }
}
