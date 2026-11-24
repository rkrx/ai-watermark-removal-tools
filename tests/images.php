<?php

declare(strict_types=1);

use Rkr\WatermarkRemoval\ImageMetadataCleaner;

require __DIR__ . '/../vendor/autoload.php';

function checkImage(string $name, string $original, string $marked): void {
	if($original === $marked || ImageMetadataCleaner::clean($marked) !== $original
		|| ImageMetadataCleaner::clean($original) !== $original) {
		throw new RuntimeException("Image round trip failed for $name");
	}
}

function pngChunk(string $type, string $data): string {
	return pack('N', strlen($data)) . $type . $data . hash('crc32b', $type . $data, true);
}

function app11(int $instance, int $sequence, int $boxSize, string $data): string {
	$part = 'JP' . pack('nNN', $instance, $sequence, $boxSize) . 'jumb' . $data;
	return "\xff\xeb" . pack('n', strlen($part) + 2) . $part;
}

$uuid = hex2bin('6332706100110010800000aa00389b71');
$description = pack('N', 30) . 'jumd' . $uuid . "\x03c2pa\0";
$box = pack('N', 38) . 'jumb' . $description;
$png = file_get_contents(__DIR__ . '/fixtures/sample.png');
$png = substr_replace($png, pngChunk('tEXt', "Comment\0c2pa is a word here"), 33, 0);
checkImage('PNG', $png, substr_replace($png, pngChunk('caBX', $box), 33, 0));

$webp = file_get_contents(__DIR__ . '/fixtures/sample.webp');
$riff = substr($webp, 8) . 'C2PA' . pack('V', strlen($box)) . $box;
checkImage('WebP', $webp, 'RIFF' . pack('V', strlen($riff)) . $riff);

$jpeg = file_get_contents(__DIR__ . '/fixtures/sample.jpg');
$other = app11(2, 1, 38, str_replace($uuid, str_repeat('X', 16), $description));
$jpeg = substr_replace($jpeg, $other, 2, 0);
$first = app11(1, 1, 46, $description);
$second = app11(1, 2, 46, pack('N', 8) . 'free');
checkImage('fragmented JPEG', $jpeg, substr_replace($jpeg, $first . $second, 2, 0));
$scan = strpos($jpeg, "\xff\xda", strpos($jpeg, "\xff\xda") + 2);
if($scan === false) {
	throw new RuntimeException('Expected a progressive JPEG fixture');
}
checkImage('between scans', $jpeg, substr_replace($jpeg, $first . $second, $scan, 0));

foreach([substr($png, 0, -1), substr($webp, 0, -1), substr($jpeg, 0, -1),
	substr_replace($jpeg, $first, 2, 0), substr_replace($jpeg, $first . $second, 2, 0) . $jpeg,
] as $truncated) {
	try {
		ImageMetadataCleaner::clean($truncated);
		throw new RuntimeException('Expected ambiguous or truncated image to fail');
	} catch(UnexpectedValueException) {
	}
}
try {
	ImageMetadataCleaner::clean('unknown');
	throw new RuntimeException('Expected unsupported format to fail');
} catch(InvalidArgumentException) {
}
echo "Image checks passed\n";
