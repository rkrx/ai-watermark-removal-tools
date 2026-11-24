<?php

declare(strict_types=1);

namespace Rkr\WatermarkRemoval;

use InvalidArgumentException;
use UnexpectedValueException;

/** Remove embedded C2PA manifest stores without decoding or re-encoding image data. */
final class ImageMetadataCleaner {
	private const PNG = "\x89PNG\r\n\x1a\n";
	private const MANIFEST_UUID = "c2pa\x00\x11\x00\x10\x80\x00\x00\xaa\x00\x38\x9b\x71";

	public static function clean(string $bytes): string {
		return match(true) {
			str_starts_with($bytes, self::PNG) => self::png($bytes),
			str_starts_with($bytes, "\xff\xd8") => self::jpeg($bytes),
			str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => self::webp($bytes),
			default => throw new InvalidArgumentException('Expected PNG, JPEG or WebP image data'),
		};
	}

	private static function png(string $bytes): string {
		$size = strlen($bytes);
		$result = self::PNG;
		$hasImage = false;
		for($p = 8; $p + 12 <= $size; $p += $length + 12) {
			$length = self::unsigned('N', $bytes, $p);
			$type = substr($bytes, $p + 4, 4);
			if($length > $size - $p - 12 || ($p === 8 && ($type !== 'IHDR' || $length !== 13))) {
				throw new UnexpectedValueException('Invalid PNG chunk');
			}
			if(hash('crc32b', substr($bytes, $p + 4, $length + 4), true) !== substr($bytes, $p + 8 + $length, 4)) {
				throw new UnexpectedValueException('Invalid PNG checksum');
			}
			if($type !== 'caBX') {
				$result .= substr($bytes, $p, $length + 12);
			}
			$hasImage = $hasImage || $type === 'IDAT';
			if($type === 'IEND') {
				if($length !== 0 || !$hasImage || $p + 12 !== $size) {
					throw new UnexpectedValueException('Invalid PNG ending');
				}
				return $result;
			}
		}
		throw new UnexpectedValueException('Truncated PNG');
	}

	private static function webp(string $bytes): string {
		$size = strlen($bytes);
		if(self::unsigned('V', $bytes, 4) !== $size - 8) {
			throw new UnexpectedValueException('Invalid WebP container size');
		}
		$body = 'WEBP';
		$hasImage = false;
		for($p = 12; $p < $size; $p += 8 + $padded) {
			if($size - $p < 8) {
				throw new UnexpectedValueException('Truncated WebP chunk header');
			}
			$type = substr($bytes, $p, 4);
			$length = self::unsigned('V', $bytes, $p + 4);
			$padded = $length + ($length % 2);
			if($padded > $size - $p - 8) {
				throw new UnexpectedValueException('Truncated WebP chunk');
			}
			if($type !== 'C2PA') {
				$body .= substr($bytes, $p, 8 + $padded);
			}
			$hasImage = $hasImage || in_array($type, ['VP8 ', 'VP8L', 'ANMF'], true);
		}
		if(!$hasImage) {
			throw new UnexpectedValueException('Missing WebP image data');
		}
		return 'RIFF' . pack('V', strlen($body)) . $body;
	}

	private static function jpeg(string $bytes): string {
		$size = strlen($bytes);
		$scan = false;
		$hasScan = false;
		$groups = [];
		$ranges = [];
		for($p = 2; $p < $size;) {
			if($scan) {
				$p = strpos($bytes, "\xff", $p);
				if($p === false) {
					break;
				}
			}
			$fill = strspn($bytes, "\xff", $p);
			if(!$fill || $p + $fill >= $size) {
				throw new UnexpectedValueException('Invalid JPEG marker');
			}
			$start = $p + $fill - 1;
			$marker = ord($bytes[$start + 1]);
			$p = $start + 2;
			if($scan && ($marker === 0 || ($marker >= 0xd0 && $marker <= 0xd7))) {
				continue;
			}
			if($marker === 0xd9 && $hasScan) {
				if(!$ranges) {
					return $bytes;
				}
				if($p !== $size) {
					throw new UnexpectedValueException('Removing metadata from multi-image or appended JPEGs is unsupported');
				}
				foreach($groups as $group) {
					if($group['remaining'] !== 0) {
						throw new UnexpectedValueException('Incomplete JPEG manifest');
					}
				}
				$result = '';
				$kept = 0;
				foreach($ranges as [$offset, $end]) {
					$result .= substr($bytes, $kept, $offset - $kept);
					$kept = $end;
				}
				return $result . substr($bytes, $kept);
			}
			if($marker === 1) {
				continue;
			}
			if($marker === 0 || ($marker >= 0xd0 && $marker <= 0xd9) || $size - $p < 2) {
				throw new UnexpectedValueException('Unexpected JPEG marker');
			}
			$length = self::unsigned('n', $bytes, $p);
			if($length < 2 || $length > $size - $p) {
				throw new UnexpectedValueException('Truncated JPEG segment');
			}
			if($marker === 0xeb && $length >= 18) {
				$part = substr($bytes, $p + 2, $length - 2);
				// JPEG XT repeats the box length and type in each APP11 fragment.
				if(str_starts_with($part, 'JP') && substr($part, 12, 4) === 'jumb') {
					$id = self::unsigned('n', $part, 2);
					$seq = self::unsigned('N', $part, 4);
					$boxSize = self::unsigned('N', $part, 8);
					if($seq === 1 && self::isManifest($part)) {
						if(isset($groups[$id])) {
							throw new UnexpectedValueException('Duplicate JPEG manifest instance');
						}
						$groups[$id] = ['next' => 1, 'size' => $boxSize, 'remaining' => $boxSize - 8];
					}
					if(isset($groups[$id])) {
						$group = &$groups[$id];
						if($seq !== $group['next'] || $boxSize !== $group['size']) {
							throw new UnexpectedValueException('Inconsistent JPEG manifest fragments');
						}
						++$group['next'];
						$group['remaining'] -= strlen($part) - 16;
						unset($group);
						$ranges[] = [$start, $p + $length];
					}
				}
			}
			$scan = $marker === 0xda || ($scan && $marker === 0xdc);
			$hasScan = $hasScan || $scan;
			$p += $length;
		}
		throw new UnexpectedValueException('Missing JPEG end marker');
	}

	/** @param 'n'|'N'|'V' $format */
	private static function unsigned(string $format, string $bytes, int $offset): int {
		$value = unpack($format, $bytes, $offset)[1] ?? null;
		if(!is_int($value) || $value < 0) {
			throw new UnexpectedValueException('Invalid or unsupported unsigned image field');
		}
		return $value;
	}

	private static function isManifest(string $part): bool {
		// Ordinary JUMBF header, description UUID and labelled manifest store.
		return strlen($part) >= 46 && substr($part, 20, 4) === 'jumd'
			&& substr($part, 24, 16) === self::MANIFEST_UUID && (ord($part[40]) & 2) !== 0
			&& substr($part, 41, 5) === "c2pa\0";
	}
}
