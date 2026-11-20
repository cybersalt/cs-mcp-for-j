<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\Helper;

\defined('_JEXEC') or die;

/**
 * Download a file the AI app points at (a ChatGPT attachment's download URL,
 * or any public https address), for upload_media (2.14.0).
 *
 * The address comes from the AI app, which reads untrusted text, so this is
 * guarded against server-side request forgery the same way OAuthServer's
 * published-identity fetch is: https on port 443 only, every address the host
 * resolves to must be public (no private, loopback, link-local, reserved or
 * IPv4-in-IPv6 ranges), curl is pinned to the checked address so DNS can't
 * change in between, no proxy, and size and time are capped. Redirects are
 * followed by hand, at most 3, and each hop is checked again (attachment URLs
 * often redirect to a storage host).
 */
final class SafeFetch
{
	private const MAX_REDIRECTS = 3;

	/**
	 * @return array{body: string, content_type: string, filename: string, url: string}
	 *
	 * @throws \RuntimeException with a message fit for the AI app
	 */
	public static function get(string $url, int $maxBytes): array
	{
		if (!function_exists('curl_init')) {
			throw new \RuntimeException('this site\'s PHP has no curl extension, so it cannot download files from an address. Send the file as content_base64 instead.');
		}

		for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
			$result = self::fetchOnce($url, $maxBytes);

			if ($result['location'] === '') {
				return [
					'body'         => $result['body'],
					'content_type' => $result['content_type'],
					'filename'     => $result['filename'],
					'url'          => $url,
				];
			}

			$url = self::absolute($result['location'], $url);
		}

		throw new \RuntimeException('the address redirected more than ' . self::MAX_REDIRECTS . ' times.');
	}

	/** @return array{body: string, content_type: string, filename: string, location: string} */
	private static function fetchOnce(string $url, int $maxBytes): array
	{
		$parts = strlen($url) <= 4000 ? parse_url($url) : false;

		if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
			throw new \RuntimeException('only https addresses can be downloaded.');
		}
		if (isset($parts['user']) || isset($parts['pass'])) {
			throw new \RuntimeException('the address contains a user name or password.');
		}
		if (isset($parts['port']) && (int) $parts['port'] !== 443) {
			throw new \RuntimeException('the address uses a port other than 443.');
		}

		$host = strtolower((string) $parts['host']);
		$bare = trim($host, '[]');
		$ips  = filter_var($bare, FILTER_VALIDATE_IP) ? [$bare] : (@gethostbynamel($host) ?: []);

		if (!filter_var($bare, FILTER_VALIDATE_IP)) {
			foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
				if (!empty($record['ipv6'])) {
					$ips[] = (string) $record['ipv6'];
				}
			}
		}

		if ($ips === []) {
			throw new \RuntimeException('the host name ' . $host . ' does not resolve.');
		}

		foreach ($ips as $ip) {
			if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false || self::isTranslatedAddress($ip)) {
				throw new \RuntimeException('the host name resolves to a private or reserved address, which this site will not download from.');
			}
		}

		$pin      = str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0];
		$body     = '';
		$headers  = [];
		$tooLarge = false;
		$ch       = curl_init($url);

		curl_setopt_array($ch, [
			CURLOPT_RESOLVE        => [$bare . ':443:' . $pin],
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_PROXY          => '',
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_USERAGENT      => 'MCP for J upload (Joomla)',
			CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
				$pos = strpos($line, ':');
				if ($pos !== false) {
					$headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
				}

				return strlen($line);
			},
			CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
				$body .= $chunk;

				if (strlen($body) > $maxBytes) {
					$tooLarge = true;

					return 0;
				}

				return strlen($chunk);
			},
		]);

		if (class_exists(\Composer\CaBundle\CaBundle::class)) {
			$ca = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
			curl_setopt($ch, is_dir($ca) ? CURLOPT_CAPATH : CURLOPT_CAINFO, $ca);
		}

		$ok     = curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$error  = curl_error($ch);
		curl_close($ch);

		if ($tooLarge) {
			throw new \RuntimeException('the file is larger than the ' . round($maxBytes / 1048576, 1) . ' MB upload limit.');
		}

		if (in_array($status, [301, 302, 303, 307, 308], true) && !empty($headers['location'])) {
			return ['body' => '', 'content_type' => '', 'filename' => '', 'location' => $headers['location']];
		}

		if ($ok === false || $status !== 200) {
			throw new \RuntimeException($ok === false
				? 'downloading it failed (' . mb_substr($error, 0, 150) . ').'
				: 'downloading it returned HTTP ' . $status . ' (the link may have expired; attach the file again).');
		}

		return [
			'body'         => $body,
			'content_type' => strtolower(trim(explode(';', (string) ($headers['content-type'] ?? ''))[0])),
			'filename'     => self::dispositionName((string) ($headers['content-disposition'] ?? '')),
			'location'     => '',
		];
	}

	private static function absolute(string $location, string $base): string
	{
		if (preg_match('#^https?://#i', $location)) {
			return $location;
		}

		$b = parse_url($base);

		return 'https://' . $b['host'] . (str_starts_with($location, '/') ? $location : '/' . $location);
	}

	/** The file name from a Content-Disposition header, or ''. */
	private static function dispositionName(string $header): string
	{
		if (preg_match("/filename\\*=(?:UTF-8'')?([^;]+)/i", $header, $m)) {
			return basename(rawurldecode(trim($m[1], " \"'")));
		}
		if (preg_match('/filename="?([^";]+)"?/i', $header, $m)) {
			return basename(trim($m[1]));
		}

		return '';
	}

	/** Same check as OAuthServer: NAT64, 6to4 and site-local IPv6 ranges. */
	private static function isTranslatedAddress(string $ip): bool
	{
		$bin = @inet_pton($ip);

		if ($bin === false || strlen($bin) !== 16) {
			return false;
		}

		$hex = bin2hex($bin);

		return str_starts_with($hex, '0064ff9b') || str_starts_with($hex, '2002')
			|| (hexdec(substr($hex, 0, 3)) & 0xffc) === 0xfec;
	}
}
