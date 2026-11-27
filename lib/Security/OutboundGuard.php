<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_billing, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_billing
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

declare(strict_types=1);

namespace Somethingwithproof\Billing\Security;

use Somethingwithproof\Billing\Ledger\BillingException;

/**
 * Outbound HTTPS security boundary enforcing mandatory TLS, SSRF IP blocking
 * (IPv4/IPv6 loopback, RFC1918, link-local, cloud metadata, CGNAT, ULA,
 * IPv4-mapped IPv6), zero redirects, and bounded timeouts/response sizes.
 */
final class OutboundGuard {
	/**
	 * Default allowed outbound HTTPS ports.
	 */
	private const ALLOWED_PORTS = [443, 8443];

	/**
	 * Optional DNS resolver callback returning a list of IPv4/IPv6 strings for a host.
	 *
	 * @var (callable(string): list<string>)|null
	 */
	private $resolver;

	/**
	 * Construct an OutboundGuard.
	 *
	 * @param int $timeoutSeconds Maximum connect + transfer timeout in seconds.
	 * @param int $maxResponseBytes Maximum accepted HTTP response body size in bytes.
	 * @param (callable(string): list<string>)|null $resolver Optional DNS resolver override for deterministic tests.
	 */
	public function __construct(
		public readonly int $timeoutSeconds = 10,
		public readonly int $maxResponseBytes = 65536,
		?callable $resolver = null,
	) {
		$this->resolver = $resolver;
	}

	/**
	 * Validate a webhook URL and return resolved public IPs pinned for connection.
	 *
	 * @param string $url Target webhook URL.
	 * @return array{url: string, host: string, port: int, ips: list<string>}
	 */
	public function validateUrl(string $url): array {
		$trimmed = trim($url);

		if ($trimmed === '' || strlen($trimmed) > 512) {
			throw new BillingException('Webhook URL must be non-empty and at most 512 characters.');
		}

		$parts = parse_url($trimmed);

		if (!is_array($parts)) {
			throw new BillingException('Malformed webhook URL.');
		}

		$scheme = strtolower((string) ($parts['scheme'] ?? ''));

		if ($scheme !== 'https') {
			throw new BillingException('Webhook URL must use the https:// scheme.');
		}

		if (isset($parts['user']) || isset($parts['pass'])) {
			throw new BillingException('Webhook URL must not embed credentials.');
		}

		$host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

		if ($host === '' || str_contains($host, ' ') || str_contains($host, '@')) {
			throw new BillingException('Webhook URL contains an invalid host.');
		}

		$port = isset($parts['port']) ? (int) $parts['port'] : 443;

		if (!in_array($port, self::ALLOWED_PORTS, true)) {
			throw new BillingException('Webhook URL port must be 443 or 8443.');
		}

		$ips = $this->resolveHost($host);

		if ($ips === []) {
			throw new BillingException('Unable to resolve webhook host: ' . $host);
		}

		foreach ($ips as $ip) {
			if (self::isBlockedIp($ip)) {
				throw new BillingException('Outbound webhook target resolved to a blocked private or reserved IP address.');
			}
		}

		return [
			'url'  => $trimmed,
			'host' => $host,
			'port' => $port,
			'ips'  => $ips,
		];
	}

	/**
	 * Return true if an IPv4 or IPv6 address falls in any SSRF-sensitive range.
	 *
	 * @param string $ip Raw IPv4 or IPv6 address.
	 * @return bool
	 */
	public static function isBlockedIp(string $ip): bool {
		$packed = @inet_pton($ip);

		if ($packed === false) {
			return true;
		}

		// Handle IPv4-mapped IPv6 addresses (::ffff:a.b.c.d) by unwrapping to IPv4.
		if (strlen($packed) === 16 && str_starts_with($packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
			$v4 = inet_ntop(substr($packed, 12, 4));

			return !is_string($v4) || self::isBlockedIpv4($v4);
		}

		if (strlen($packed) === 4) {
			return self::isBlockedIpv4($ip);
		}

		return self::isBlockedIpv6($packed);
	}

	/**
	 * Build hardened cURL option set enforcing TLS, zero redirects, and bounded timeouts.
	 *
	 * @param array{url: string, host: string, port: int, ips: list<string>} $validated Output of validateUrl().
	 * @param list<string> $headers HTTP request headers.
	 * @param string $body Request body string.
	 * @return array<int, mixed>
	 */
	public function buildCurlOptions(array $validated, array $headers, string $body): array {
		$pinnedIp = $validated['ips'][0];
		$resolve  = [sprintf('%s:%d:%s', $validated['host'], $validated['port'], $pinnedIp)];

		return [
			CURLOPT_URL            => $validated['url'],
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_MAXREDIRS      => 0,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => 0,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_CONNECTTIMEOUT => min(5, $this->timeoutSeconds),
			CURLOPT_TIMEOUT        => $this->timeoutSeconds,
			CURLOPT_RESOLVE        => $resolve,
		];
	}

	/**
	 * Resolve a host literal or DNS name into IP strings.
	 *
	 * @param string $host Normalized hostname or IP literal.
	 * @return list<string>
	 */
	private function resolveHost(string $host): array {
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return [$host];
		}

		if ($this->resolver !== null) {
			return ($this->resolver)($host);
		}

		$ips = [];
		$a   = @dns_get_record($host, DNS_A);

		if (is_array($a)) {
			foreach ($a as $rec) {
				if (is_string($rec['ip'] ?? null)) {
					$ips[] = $rec['ip'];
				}
			}
		}

		$aaaa = @dns_get_record($host, DNS_AAAA);

		if (is_array($aaaa)) {
			foreach ($aaaa as $rec) {
				if (is_string($rec['ipv6'] ?? null)) {
					$ips[] = $rec['ipv6'];
				}
			}
		}

		if ($ips === []) {
			$fallback = gethostbynamel($host);

			if (is_array($fallback)) {
				$ips = $fallback;
			}
		}

		return array_values(array_unique($ips));
	}

	/**
	 * Evaluate IPv4 SSRF block ranges (RFC1918, loopback, link-local, CGNAT, reserved).
	 *
	 * @param string $ip Valid IPv4 dotted quad.
	 * @return bool
	 */
	private static function isBlockedIpv4(string $ip): bool {
		$long = ip2long($ip);

		if ($long === false) {
			return true;
		}

		$unsigned = $long & 0xFFFFFFFF;
		$blocked  = [
			[0x00000000, 0xFF000000], // 0.0.0.0/8 (unspecified)
			[0x0A000000, 0xFF000000], // 10.0.0.0/8 (RFC1918)
			[0x64400000, 0xFFC00000], // 100.64.0.0/10 (CGNAT / RFC6598)
			[0x7F000000, 0xFF000000], // 127.0.0.0/8 (loopback)
			[0xA9FE0000, 0xFFFF0000], // 169.254.0.0/16 (link-local & cloud metadata)
			[0xAC100000, 0xFFF00000], // 172.16.0.0/12 (RFC1918)
			[0xC0000000, 0xFFFFFF00], // 192.0.0.0/24 (IETF protocol assignments)
			[0xC0000200, 0xFFFFFF00], // 192.0.2.0/24 (TEST-NET-1)
			[0xC0A80000, 0xFFFF0000], // 192.168.0.0/16 (RFC1918)
			[0xC6120000, 0xFFFE0000], // 198.18.0.0/15 (benchmarking)
			[0xC6336400, 0xFFFFFF00], // 198.51.100.0/24 (TEST-NET-2)
			[0xCB007100, 0xFFFFFF00], // 203.0.113.0/24 (TEST-NET-3)
			[0xE0000000, 0xE0000000], // 224.0.0.0/3 (multicast + reserved + broadcast)
		];

		foreach ($blocked as [$network, $mask]) {
			if (($unsigned & $mask) === $network) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Evaluate IPv6 SSRF block ranges (unspecified, loopback, ULA, link-local, multicast).
	 *
	 * @param string $packed 16-byte binary IPv6 representation from inet_pton().
	 * @return bool
	 */
	private static function isBlockedIpv6(string $packed): bool {
		// :: (unspecified) and ::1 (loopback)
		if ($packed === str_repeat("\x00", 16) || $packed === str_repeat("\x00", 15) . "\x01") {
			return true;
		}

		$b0 = ord($packed[0]);
		$b1 = ord($packed[1]);

		// fc00::/7 (Unique Local Address)
		if (($b0 & 0xFE) === 0xFC) {
			return true;
		}

		// fe80::/10 (Link-local) and fec0::/10 (Site-local)
		if ($b0 === 0xFE && ($b1 & 0xC0) === 0x80) {
			return true;
		}

		if ($b0 === 0xFE && ($b1 & 0xC0) === 0xC0) {
			return true;
		}

		// ff00::/8 (Multicast)
		if ($b0 === 0xFF) {
			return true;
		}

		// 2001:db8::/32 (Documentation)
		if ($b0 === 0x20 && $b1 === 0x01 && ord($packed[2]) === 0x0D && ord($packed[3]) === 0xB8) {
			return true;
		}

		return false;
	}
}
