# AI Watermark Removal Tools

A PHP library for cleaning UTF-8 text and removing embedded C2PA manifests from images. The cleaner preserves tabs, line breaks and combining accents. It uses surrounding characters to preserve emoji sequences, script shaping, ideographic variants and script-specific word breaks.

## Installation

Requires PHP 8.2 or later with PCRE2 10.40 or later (included in standard PHP 8.2 builds).

```sh
composer require rkr/ai-watermark-removal-tools:^1.1
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

Supply valid UTF-8. Invalid input raises `JsonException`. The text API handles character-based marks. Statistical watermarks encoded in word choice are outside the scope of the text API.

## Clean embedded image provenance

```php
use Rkr\WatermarkRemoval\ImageMetadataCleaner;

$original = file_get_contents('input.jpg');
if ($original === false) {
    throw new RuntimeException('Could not read the image');
}
$clean = ImageMetadataCleaner::clean($original);
file_put_contents('output.jpg', $clean);
```

`clean()` accepts image bytes and returns image bytes. It recognizes PNG `caBX`, WebP `C2PA` and ordinary JPEG APP11 JUMBF manifest stores, including sequential fragments. It copies the encoded image data, ICC profiles, EXIF orientation and unrelated metadata byte for byte. A clean supported image is returned unchanged. The file API belongs to the caller.

This operation removes the embedded manifest and its verifiable signature from the output. XMP links to remote manifests, other EXIF/IPTC/XMP fields, generator annotations, visible overlays and pixel-encoded marks remain outside its scope. Unsupported formats raise `InvalidArgumentException`. Truncated containers or ambiguous JPEG fragments raise `UnexpectedValueException`. JPEGs with appended data such as a second image are rejected when removing a manifest could invalidate offsets.

## Development

Run `composer install` and `composer test`. The checks use PHP and the package's Composer autoloader.

The cleaner originated in [dv-team/php-mcp-server](https://github.com/dv-team/php-mcp-server). Additional Unicode cases were compared with the [watermarks-remover text implementation](https://github.com/guillaumemeyer/watermarks-remover/blob/5a62be6338d2812372b1da440e55c82922f97d53/service/scripts/text_unicode.py).

## License

[MIT](LICENSE)
