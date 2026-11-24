<?php

declare(strict_types=1);

use Rkr\WatermarkRemoval\TextCleaner;

require __DIR__ . '/../vendor/autoload.php';

function expectSame(mixed $expected, mixed $actual): void {
	if($actual !== $expected) {
		throw new RuntimeException('Unexpected result: ' . json_encode([$expected, $actual], JSON_THROW_ON_ERROR));
	}
}

foreach([
	["B\u{200B}O\u{FEFF}M", 'BOM'],
	["x\u{FDD0}\u{FFFF}\u{1FFFE}\u{10FFFF}y", 'xy'],
	["x\u{3164}\u{3164}\u{180F}\u{17B4}y", 'xy'],
	["a\u{200D}b\u{E0100}\u{E0067}", 'ab'],
] as [$source, $expected]) {
	expectSame($expected, TextCleaner::text($source));
}

foreach(["👩🏽\u{200D}💻", "🏳\u{FE0F}\u{200D}🌈", "🏴\u{E0067}\u{E0062}\u{E0073}\u{E0063}\u{E0074}\u{E007F}",
	"می\u{200C}روم", "क्\u{200D}ष", "葛\u{E0100}", "ไทย\u{200B}ไทย", "abc \u{2067}שלום\u{2069}",
	"\u{115F}\u{1161}", "ᠠ\u{180B}", "ក\u{17B4}", "e\u{0301}\r\n\t", "\u{E000}", "a\u{00A0}b",
] as $source) {
	expectSame($source, TextCleaner::text($source));
}
expectSame("a  b\t\n", TextCleaner::text("a\u{202F}\u{3000}b\t\n", normalizeSpaces: true));

$key = "k\u{200B}";
$original = (object) [$key => ["a\u{2060}b", 0, null]];
expectSame(['ab', 0, null], TextCleaner::values($original)->{$key});
expectSame(["a\u{2060}b", 0, null], $original->{$key});
$schema = ['properties' => [$key => ['title' => $key, 'enum' => [$key]]]];
expectSame(['properties' => [$key => ['title' => 'k', 'enum' => [$key]]]], TextCleaner::schema($schema));
try {
	TextCleaner::text("\xFF");
	throw new RuntimeException('Expected invalid UTF-8 to fail');
} catch(JsonException) {
}
echo "Text checks passed\n";
