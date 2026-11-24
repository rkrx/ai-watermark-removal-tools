# AI Watermark Removal Tools

A PHP library for removing invisible Unicode markers from UTF-8 text. The cleaner preserves tabs, line breaks and combining accents. It uses surrounding characters to preserve emoji sequences, script shaping, ideographic variants and script-specific word breaks.

## Installation

Requires PHP 8.2 or later with PCRE2 10.40 or later (included in standard PHP 8.2 builds).

```sh
composer require rkr/ai-watermark-removal-tools:^1.0
```

## Clean text

```php
use Rkr\WatermarkRemoval\TextCleaner;

require __DIR__ . '/vendor/autoload.php';

echo TextCleaner::text("Hal\u{200B}lo 👩‍💻"); // Hallo 👩‍💻
echo TextCleaner::text("10\u{202F}kg", normalizeSpaces: true); // 10 kg
```

By default a nonbreaking or typographic space keeps its layout. Set `normalizeSpaces` to turn each Unicode space separator into one ordinary space. Spacing runs, tabs and newlines keep their length.

The cleaner removes embedded byte-order marks, soft hyphens, Unicode noncharacters, most C0/C1 controls and out-of-context invisible markers. Hangul fillers, Khmer inherent vowels and Mongolian selectors are retained next to their script. Directional marks are retained in text containing right-to-left letters. Private-use characters may represent custom glyphs and are preserved. The cleaner uses character context as a heuristic. An intentional mark in a plausible formatting context can survive.

## Structured text

- `TextCleaner::values($value)` cleans nested JSON string values, preserves property names and returns new arrays and objects. Use it for application data where all string values may be edited.
- `TextCleaner::schema($schema)` cleans titles and descriptions in JSON Schema arrays and standard objects. Property names, patterns, enum values and defaults retain their exact values.

Supply valid UTF-8. Invalid input raises `JsonException`. The text API handles character-based marks. Statistical watermarks encoded in word choice and image data are outside the scope of this release.

## Development

Run `composer install` and `composer test`. The checks use PHP and the package's Composer autoloader.

The cleaner originated in [dv-team/php-mcp-server](https://github.com/dv-team/php-mcp-server). Additional Unicode cases were compared with the [watermarks-remover text implementation](https://github.com/guillaumemeyer/watermarks-remover/blob/5a62be6338d2812372b1da440e55c82922f97d53/service/scripts/text_unicode.py).

## License

[MIT](LICENSE)
