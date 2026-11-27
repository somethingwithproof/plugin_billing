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

namespace Somethingwithproof\Billing\Tests\Security;

use PHPUnit\Framework\TestCase;
use Somethingwithproof\Billing\Config\ConfigSchema;
use Somethingwithproof\Billing\Connector\CsvStatementExporter;
use Somethingwithproof\Billing\Ledger\BillingException;
use Somethingwithproof\Billing\Security\OutboundGuard;
use Somethingwithproof\Billing\Security\SecretVault;

final class SecurityBoundaryTest extends TestCase {
	public function testOutboundGuardBlocksSsrfSchemesCredentialsAndPrivateIps(): void {
		$guard = new OutboundGuard(10, 65536, static fn (string $host): array => match ($host) {
			'public.example.net'   => ['93.184.216.34'],
			'metadata.example.net' => ['169.254.169.254'],
			'private.example.net'  => ['10.20.30.40'],
			'cgnat.example.net'    => ['100.64.1.2'],
			'v6local.example.net'  => ['fe80::1'],
			'v6mapped.example.net' => ['::ffff:127.0.0.1'],
			'ula.example.net'      => ['fd12:3456:789a::1'],
			default                => [],
		});

		$valid = $guard->validateUrl('https://public.example.net/hooks/billing');
		$this->assertSame('public.example.net', $valid['host']);
		$this->assertSame(443, $valid['port']);

		$curlOpts = $guard->buildCurlOptions($valid, ['Content-Type: application/json'], '{}');
		$this->assertFalse($curlOpts[CURLOPT_FOLLOWLOCATION]);
		$this->assertSame(0, $curlOpts[CURLOPT_MAXREDIRS]);
		$this->assertTrue($curlOpts[CURLOPT_SSL_VERIFYPEER]);

		$blockedUrls = [
			'http://public.example.net/hook',
			'file:///etc/passwd',
			'https://user:pass@public.example.net/hook',
			'https://public.example.net:8080/hook',
			'https://127.0.0.1/hook',
			'https://[::1]/hook',
			'https://metadata.example.net/latest/meta-data/',
			'https://private.example.net/hook',
			'https://cgnat.example.net/hook',
			'https://v6local.example.net/hook',
			'https://v6mapped.example.net/hook',
			'https://ula.example.net/hook',
			'https://192.168.1.1/hook',
			'https://172.16.0.1/hook',
		];

		foreach ($blockedUrls as $url) {
			try {
				$guard->validateUrl($url);
				$this->fail('Expected OutboundGuard to reject URL: ' . $url);
			} catch (BillingException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testSecretVaultAes256GcmAadBindingAndExternalKeyfile(): void {
		$rawKey  = random_bytes(32);
		$keyfile = tempnam(sys_get_temp_dir(), 'billing_key_');
		$this->assertIsString($keyfile);
		file_put_contents($keyfile, bin2hex($rawKey) . "\n");

		try {
			$vault = SecretVault::fromKeyfile($keyfile);
			$enc   = $vault->encrypt('sk_live_erp_token_xyz', 'ACME-01');
			$this->assertStringStartsWith('v1:', $enc);
			$this->assertSame('sk_live_erp_token_xyz', $vault->decrypt($enc, 'ACME-01'));

			// Transplanting ciphertext to another account's AAD must fail GCM authentication.
			try {
				$vault->decrypt($enc, 'OTHER-ACCT');
				$this->fail('Expected AAD mismatch to fail GCM authentication.');
			} catch (BillingException) {
				$this->addToAssertionCount(1);
			}

			// Bit-flipping ciphertext must fail GCM authentication.
			$raw = base64_decode(substr($enc, 3), true);
			$this->assertIsString($raw);
			$raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0x01);
			$tampered              = 'v1:' . base64_encode($raw);

			try {
				$vault->decrypt($tampered, 'ACME-01');
				$this->fail('Expected tampered ciphertext to fail GCM authentication.');
			} catch (BillingException) {
				$this->addToAssertionCount(1);
			}
		} finally {
			@unlink($keyfile);
		}
	}

	public function testCsvFormulaNeutralizationAndCascStateSplit(): void {
		$vectors = [
			'=CMD()|A0',
			'+2+3',
			'-2+3',
			'@SUM(1,2)',
			"\t=HYPERLINK(\"http://evil\")",
			"  =1+1",
		];

		foreach ($vectors as $v) {
			$safe = CsvStatementExporter::neutralizeCell($v);
			$this->assertStringStartsWith("'", $safe, 'Formula vector was not neutralized: ' . $v);
		}

		// Plain numbers and negative decimals remain unquoted.
		$this->assertSame('-12.34', CsvStatementExporter::neutralizeCell('-12.34'));
		$this->assertSame('1600.00', CsvStatementExporter::neutralizeCell('1600.00'));

		// ConfigSchema rejects State tables and raw secrets in declarative CasC config.
		$errors = ConfigSchema::validate([
			'plugin_billing_sample'    => [],
			'plugin_billing_statement' => [],
			'secret_ciphertext'        => 'v1:abc',
			'billing_secret_keyfile'   => '/etc/../etc/shadow',
			'billing_burst_warning_pct'  => 90,
			'billing_burst_critical_pct' => 70,
		]);
		$this->assertGreaterThanOrEqual(5, count($errors));
	}
}
