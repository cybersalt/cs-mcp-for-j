<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Helper\MediaHelper;
use Joomla\Registry\Registry;

/**
 * What happens to a photo on upload, from Options → Media uploads (2.14.0).
 *
 * A phone photo arrives large, sideways (the camera stores "rotate me" in
 * EXIF instead of turning the pixels, and not every browser or Joomla's own
 * thumbnails honour it) and carrying the camera, the time and often the GPS
 * position of where it was taken, which a town hall must not publish.
 *
 *   1. Orientation: the EXIF Orientation tag is read from the JPEG (our own
 *      small parser, so PHP's exif extension isn't needed) and the pixels are
 *      turned to match.
 *   2. Resize: the longest side is brought down to the maximum, never up.
 *   3. WebP: JPEG and PNG are saved as WebP when switched on and when GD can
 *      write WebP and Media allows the .webp extension; otherwise kept.
 *   4. Metadata: any re-encode drops all of it (EXIF, GPS, XMP, IPTC, and the
 *      colour profile). When nothing else needs the pixels touched, JPEG and
 *      PNG are stripped WITHOUT re-encoding (the metadata segments / chunks
 *      are cut out, the image data is copied as is, so no quality is lost).
 *      The JPEG colour profile (APP2 ICC) and Adobe marker (APP14, needed
 *      for CMYK) are kept on that path.
 *
 * GD is required for 1–3. Without it, or without enough memory for a very
 * large image, the file is stored with only the lossless strip, and the notes
 * say what was skipped. Animated GIFs are never re-encoded (GD would keep the
 * first frame only).
 */
final class ImageProcessor
{
	private const MAX_PIXELS = 64_000_000;

	private const MAX_MEMORY = 768 * 1048576;

	/**
	 * @param array $info getimagesizefromstring() result
	 *
	 * @return array{0: string, 1: string, 2: array<string, mixed>} bytes, file name, report
	 */
	public static function process(string $bytes, string $filename, array $info, Registry $params): array
	{
		$report = ['processed' => false, 'notes' => [], 'original_size' => strlen($bytes)];
		$width  = (int) $info[0];
		$height = (int) $info[1];
		$mime   = (string) $info['mime'];

		$report['width']  = $width;
		$report['height'] = $height;

		if (!$params->get('media_process_images', 1)) {
			$report['notes'][] = 'Image processing is switched off in Options; the file was stored as sent.';

			return [$bytes, $filename, $report];
		}

		$strip       = (bool) $params->get('media_strip_metadata', 1);
		$maxSide     = max(0, (int) $params->get('media_max_dimension', 2560));
		$orientation = ($mime === 'image/jpeg' && $params->get('media_fix_orientation', 1)) ? self::jpegOrientation($bytes) : 1;
		$wantWebp    = $params->get('media_convert_webp', 0) && in_array($mime, ['image/jpeg', 'image/png'], true);

		// Width and height as they will look once turned upright.
		[$uprightW, $uprightH] = $orientation >= 5 ? [$height, $width] : [$width, $height];
		$needsResize = $maxSide > 0 && max($uprightW, $uprightH) > $maxSide;

		if ($wantWebp && (!function_exists('imagewebp') || !MediaHelper::checkFileExtension('webp'))) {
			$wantWebp = false;
			$report['notes'][] = !function_exists('imagewebp')
				? 'WebP is switched on but this server\'s GD cannot write WebP; kept as ' . $mime . '.'
				: 'WebP is switched on but Media → Options does not allow the .webp extension; kept as ' . $mime . '.';
		}

		$animated = $mime === 'image/gif' && self::isAnimatedGif($bytes);
		$reencode = !$animated && ($orientation !== 1 || $needsResize || $wantWebp
			|| ($strip && $mime === 'image/webp'));

		if ($reencode && !function_exists('imagecreatefromstring')) {
			$reencode = false;
			$report['notes'][] = 'This server has no GD image library, so the image could not be turned, resized or converted.';
		}

		if ($reencode && !self::enoughMemory($width, $height, max($uprightW, $uprightH) > $maxSide && $maxSide > 0 ? $maxSide : 0)) {
			// Storing it anyway would skip the size limit the site asked for,
			// and an image this large can freeze a visitor's browser.
			if ($needsResize) {
				throw new \DomainException('The image is ' . $width . '×' . $height . ' pixels, too large for this server to scale down '
					. 'to ' . $maxSide . ' px. Nothing was uploaded. Scale it down first (most phones and photo apps can share a smaller copy).');
			}

			$reencode = false;
			$report['notes'][] = 'The image (' . $width . '×' . $height . ') is too large for the PHP memory limit to process; stored without turning or resizing.';
		}

		if ($reencode) {
			$out = self::reencode($bytes, $mime, $orientation, $needsResize ? $maxSide : 0, $wantWebp, $params);

			if ($out !== null) {
				[$bytes, $outMime, $w, $h] = $out;

				if ($orientation !== 1) {
					$report['notes'][] = 'Turned upright (the camera had stored EXIF orientation ' . $orientation . ').';
				}
				if ($needsResize) {
					$report['notes'][] = 'Resized from ' . $uprightW . '×' . $uprightH . ' to ' . $w . '×' . $h . ' (longest side ' . $maxSide . ').';
				}
				if ($outMime !== $mime) {
					$filename = preg_replace('/\.[^.]+$/', '', $filename) . '.webp';
					$report['notes'][] = 'Converted to WebP.';
				}

				$report['notes'][]  = 'Metadata (EXIF, GPS position, camera details) removed by re-encoding.';
				$report['processed'] = true;
				$report['width']     = $w;
				$report['height']    = $h;
				$report['size']      = strlen($bytes);

				return [$bytes, $filename, $report];
			}

			$report['notes'][] = 'GD could not decode the image, so it was not turned, resized or converted.';
		}

		if ($strip) {
			$stripped = match ($mime) {
				'image/jpeg' => self::stripJpeg($bytes),
				'image/png'  => self::stripPng($bytes),
				default      => null,
			};

			if ($stripped !== null && $stripped !== $bytes) {
				$bytes = $stripped;
				$report['processed'] = true;
				$report['notes'][]   = 'Metadata (EXIF, GPS position, camera details) removed without re-encoding.';
			} elseif ($stripped === null && $mime === 'image/gif') {
				$report['notes'][] = 'GIF files carry no EXIF; stored as sent.';
			} elseif ($stripped === null && in_array($mime, ['image/jpeg', 'image/png'], true)) {
				$report['notes'][] = 'The file\'s structure could not be read, so its metadata (which may include a GPS position) could NOT be removed. Check the photo before publishing it.';
			} elseif ($stripped === null) {
				$report['notes'][] = 'Metadata could not be removed from this ' . $mime . ' file without GD.';
			}
		}

		$report['size'] = strlen($bytes);

		return [$bytes, $filename, $report];
	}

	/** @return array{0: string, 1: string, 2: int, 3: int}|null bytes, mime, width, height */
	private static function reencode(string $bytes, string $mime, int $orientation, int $maxSide, bool $toWebp, Registry $params): ?array
	{
		$img = @imagecreatefromstring($bytes);

		if ($img === false) {
			return null;
		}

		$hasAlpha = in_array($mime, ['image/png', 'image/webp', 'image/gif'], true);

		$img = self::orient($img, $orientation);

		if ($maxSide > 0) {
			$w = imagesx($img);
			$h = imagesy($img);

			if (max($w, $h) > $maxSide) {
				$scale = $maxSide / max($w, $h);
				$nw    = max(1, (int) round($w * $scale));
				$nh    = max(1, (int) round($h * $scale));
				$dst   = imagecreatetruecolor($nw, $nh);

				if ($hasAlpha) {
					imagealphablending($dst, false);
					imagesavealpha($dst, true);
					imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
				}

				imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
				imagedestroy($img);
				$img = $dst;
			}
		}

		if ($hasAlpha) {
			imagealphablending($img, false);
			imagesavealpha($img, true);
		}

		$outMime = $toWebp ? 'image/webp' : $mime;

		ob_start();
		$ok = match ($outMime) {
			'image/jpeg' => imagejpeg($img, null, max(40, min(100, (int) $params->get('media_jpeg_quality', 85)))),
			'image/png'  => imagepng($img, null, 6),
			'image/webp' => imagewebp($img, null, max(40, min(100, (int) $params->get('media_webp_quality', 82)))),
			'image/gif'  => imagegif($img),
			default      => false,
		};
		$out = (string) ob_get_clean();

		$w = imagesx($img);
		$h = imagesy($img);
		imagedestroy($img);

		return ($ok && $out !== '') ? [$out, $outMime, $w, $h] : null;
	}

	/** EXIF orientation 2–8 → upright pixels. */
	private static function orient(\GdImage $img, int $orientation): \GdImage
	{
		if (in_array($orientation, [2, 4, 5, 7], true)) {
			imageflip($img, IMG_FLIP_HORIZONTAL);
		}

		// imagerotate() turns anticlockwise, so 270 = 90° clockwise. After the
		// mirror above: 5 (transpose) = mirror + 90° anticlockwise, 7
		// (transverse) = mirror + 90° clockwise. Same table as Pillow's
		// ImageOps.exif_transpose().
		$angle = match ($orientation) {
			3, 4    => 180,
			6, 7    => 270,
			5, 8    => 90,
			default => 0,
		};

		if ($angle !== 0) {
			$rotated = imagerotate($img, $angle, 0);

			if ($rotated !== false) {
				imagedestroy($img);
				$img = $rotated;
			}
		}

		return $img;
	}

	/**
	 * The EXIF Orientation tag (1–8) of a JPEG, or 1. Walks the markers to
	 * APP1 "Exif", then IFD0 for tag 0x0112, in either byte order.
	 */
	public static function jpegOrientation(string $jpeg): int
	{
		$len = strlen($jpeg);
		$pos = 2;

		if ($len < 4 || substr($jpeg, 0, 2) !== "\xFF\xD8") {
			return 1;
		}

		while ($pos + 4 <= $len) {
			if ($jpeg[$pos] !== "\xFF") {
				return 1;
			}

			$marker = ord($jpeg[$pos + 1]);

			if ($marker === 0xDA || $marker === 0xD9) {
				return 1;
			}

			$size = unpack('n', substr($jpeg, $pos + 2, 2))[1];

			if ($marker === 0xE1 && substr($jpeg, $pos + 4, 6) === "Exif\0\0") {
				$tiff = substr($jpeg, $pos + 10, $size - 8);

				return self::tiffOrientation($tiff);
			}

			$pos += 2 + $size;
		}

		return 1;
	}

	private static function tiffOrientation(string $tiff): int
	{
		if (strlen($tiff) < 8) {
			return 1;
		}

		$le = substr($tiff, 0, 2) === 'II';

		if (!$le && substr($tiff, 0, 2) !== 'MM') {
			return 1;
		}

		$u16 = static fn (int $o): int => unpack($le ? 'v' : 'n', substr($tiff, $o, 2))[1];
		$u32 = static fn (int $o): int => unpack($le ? 'V' : 'N', substr($tiff, $o, 4))[1];

		$ifd = $u32(4);

		if ($ifd + 2 > strlen($tiff)) {
			return 1;
		}

		$count = $u16($ifd);

		for ($i = 0; $i < $count; $i++) {
			$entry = $ifd + 2 + $i * 12;

			if ($entry + 12 > strlen($tiff)) {
				break;
			}

			if ($u16($entry) === 0x0112) {
				$value = $u16($entry + 8);

				return ($value >= 1 && $value <= 8) ? $value : 1;
			}
		}

		return 1;
	}

	/**
	 * A JPEG without its metadata segments, image data untouched. Drops APP1
	 * (EXIF, XMP), APP13 (IPTC / Photoshop), COM and the other APPn; keeps
	 * APP0 (JFIF), APP2 ICC_PROFILE and APP14 (Adobe). Returns null when the
	 * file can't be walked, so the caller keeps the original.
	 */
	public static function stripJpeg(string $jpeg): ?string
	{
		$len = strlen($jpeg);

		if ($len < 4 || substr($jpeg, 0, 2) !== "\xFF\xD8") {
			return null;
		}

		$out = "\xFF\xD8";
		$pos = 2;

		while ($pos + 4 <= $len) {
			if ($jpeg[$pos] !== "\xFF") {
				return null;
			}

			$marker = ord($jpeg[$pos + 1]);

			// Fill bytes.
			if ($marker === 0xFF) {
				$pos++;
				continue;
			}

			// Start of scan: the rest is image data.
			if ($marker === 0xDA) {
				return $out . substr($jpeg, $pos);
			}

			$size = unpack('n', substr($jpeg, $pos + 2, 2))[1];

			if ($size < 2 || $pos + 2 + $size > $len) {
				return null;
			}

			$segment = substr($jpeg, $pos, 2 + $size);
			$keep    = true;

			if ($marker === 0xFE || ($marker >= 0xE1 && $marker <= 0xEF)) {
				$keep = ($marker === 0xE2 && substr($jpeg, $pos + 4, 12) === "ICC_PROFILE\0") || $marker === 0xEE;
			}

			if ($keep) {
				$out .= $segment;
			}

			$pos += 2 + $size;
		}

		return null;
	}

	/** A PNG without its text and EXIF chunks (tEXt, zTXt, iTXt, eXIf, tIME). */
	public static function stripPng(string $png): ?string
	{
		if (substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") {
			return null;
		}

		$out = substr($png, 0, 8);
		$pos = 8;
		$len = strlen($png);

		while ($pos + 12 <= $len) {
			$size = unpack('N', substr($png, $pos, 4))[1];
			$type = substr($png, $pos + 4, 4);

			if ($pos + 12 + $size > $len) {
				return null;
			}

			if (!in_array($type, ['tEXt', 'zTXt', 'iTXt', 'eXIf', 'tIME'], true)) {
				$out .= substr($png, $pos, 12 + $size);
			}

			$pos += 12 + $size;

			if ($type === 'IEND') {
				return $out;
			}
		}

		return null;
	}

	private static function isAnimatedGif(string $gif): bool
	{
		return preg_match_all('/\x00\x21\xF9\x04.{4}\x00[\x2C\x21]/s', $gif) > 1;
	}

	/**
	 * Rough check that GD can hold the image (and the resized copy) in memory:
	 * about 5 bytes a pixel plus headroom. Raises memory_limit if allowed.
	 */
	private static function enoughMemory(int $w, int $h, int $target): bool
	{
		// A few KB of PNG can claim 30000×30000 pixels (a decompression bomb):
		// never decode more than 64 megapixels, never raise memory past 768 MB.
		if ($w * $h > self::MAX_PIXELS) {
			return false;
		}

		$need = (int) ($w * $h * 5 * 1.8) + ($target > 0 ? $target * $target * 5 : 0) + 8 * 1048576;

		if ($need > self::MAX_MEMORY) {
			return false;
		}

		$limit = self::bytes((string) ini_get('memory_limit'));

		if ($limit <= 0) {
			return true;
		}

		if (memory_get_usage() + $need <= $limit) {
			return true;
		}

		$wanted = memory_get_usage() + $need + 16 * 1048576;

		if ($wanted > self::MAX_MEMORY) {
			return false;
		}

		@ini_set('memory_limit', (string) $wanted);

		return self::bytes((string) ini_get('memory_limit')) >= $wanted;
	}

	private static function bytes(string $value): int
	{
		$value = trim($value);

		if ($value === '' || $value === '-1') {
			return -1;
		}

		$n = (int) $value;

		return match (strtolower(substr($value, -1))) {
			'g'     => $n * 1073741824,
			'm'     => $n * 1048576,
			'k'     => $n * 1024,
			default => $n,
		};
	}
}
