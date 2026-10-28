<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Domain\Catalog\ProductDescription;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class MailBody
{
    public const MAX_HTML_LENGTH = 100000;

    public const MAX_QUOTE_LENGTH = 20000;

    private const UNWRAPPED_ELEMENTS = ['a', 'span', 'div', 'u', 's', 'code', 'small', 'font', 'mark', 'sub', 'sup', 'h1', 'h2', 'h3', 'h4'];

    private static ?HtmlSanitizer $sanitizer = null;

    public static function sanitize(string $html): string
    {
        $clean = trim(self::sanitizer()->sanitize(trim($html)));
        $clean = preg_replace('#<p>(?:\s|&nbsp;|<br\s*/?>)*</p>#i', '', $clean) ?? $clean;

        return self::plainText($clean) === '' ? '' : $clean;
    }

    public static function plainText(string $html): string
    {
        return ProductDescription::plainText($html);
    }

    public static function textOf(?string $text, ?string $html): string
    {
        if ($text !== null && trim($text) !== '') {
            return trim($text);
        }

        if ($html === null || trim($html) === '') {
            return '';
        }

        $withoutBlocks = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $withBreaks = preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6])\b[^>]*>#i', "\n", $withoutBlocks) ?? $withoutBlocks;
        $decoded = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace(["#[ \t]+\n#u", "#\n{3,}#"], ["\n", "\n\n"], $decoded) ?? $decoded);
    }

    public static function quoteHtml(string $intro, string $quoted): string
    {
        $quoted = mb_substr($quoted, 0, self::MAX_QUOTE_LENGTH);

        return sprintf(
            '<p>%s</p><blockquote style="margin:0 0 0 .8ex;border-left:2px solid #ccc;padding-left:1ex">%s</blockquote>',
            e($intro),
            nl2br(e($quoted), false),
        );
    }

    public static function quoteText(string $intro, string $quoted): string
    {
        $lines = preg_split('/\R/', mb_substr($quoted, 0, self::MAX_QUOTE_LENGTH)) ?: [];

        return $intro."\n".implode("\n", array_map(fn (string $line): string => '> '.$line, $lines));
    }

    private static function sanitizer(): HtmlSanitizer
    {
        if (self::$sanitizer instanceof HtmlSanitizer) {
            return self::$sanitizer;
        }

        $config = (new HtmlSanitizerConfig)->withMaxInputLength(self::MAX_HTML_LENGTH);

        foreach (ProductDescription::ALLOWED_ELEMENTS as $element) {
            $config = $config->allowElement($element);
        }

        foreach (self::UNWRAPPED_ELEMENTS as $element) {
            $config = $config->blockElement($element);
        }

        return self::$sanitizer = new HtmlSanitizer($config);
    }
}
