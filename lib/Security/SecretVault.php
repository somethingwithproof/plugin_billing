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
 * Authenticated AES-256-GCM secret vault backed by an external 256-bit keyfile
 * for encrypting ERP connector API keys and webhook signing secrets at rest.
 * Key material and decrypted secrets are never exported or replicated.
 */
final class SecretVault {
	/**
	 * Cipher algorithm identifier.
	 */
	private const CIPHER = 'aes-256-gcm';

	/**
	 * GCM nonce length in bytes (96 bits).
	 */
	private const IV_BYTES = 12;

	/**
	 * GCM authentication tag length in bytes (128 bits).
	 */
	private const TAG_BYTES = 16;

	/**
	 * Envelope version prefix embedded in serialized ciphertexts.
	 */
	private const VERSION = 'v1';

	/**
	 * Raw 32-byte symmetric encryption key.
	 */
	private readonly string $key;

	/**
	 * Construct a SecretVault from a raw 32-byte binary key.
	 *
	 * @param string $rawKey 32-byte symmetric encryption key.
	 */
	public function __construct(string $rawKey) {
		if (strlen($rawKey) !== 32) {
			throw new BillingException('SecretVault key must be exactly 32 bytes (256 bits).');
		}

		$this->key = $rawKey;
	}

	/**
	 * Load a SecretVault from an external keyfile containing 32 raw bytes or 64 hex chars.
	 *
	 * @param string $keyfilePath Absolute path to keyfile.
	 * @return self
	 */
	public static function fromKeyfile(string $keyfilePath): self {
		$path = trim($keyfilePath);

		if ($path === '' || !str_starts_with($path, '/') || !is_file($path) || !is_readable($path)) {
			throw new BillingException('SecretVault keyfile is missing or unreadable.');
		}

		$raw = file_get_contents($path);

		if (!is_string($raw) || $raw === '') {
			throw new BillingException('SecretVault keyfile is empty.');
		}

		$trimmed = trim($raw);

		if (strlen($trimmed) === 64 && ctype_xdigit($trimmed)) {
			$decoded = hex2bin($trimmed);

			if (is_string($decoded) && strlen($decoded) === 32) {
				return new self($decoded);
			}
		}

		if (strlen($raw) === 32) {
			return new self($raw);
		}

		throw new BillingException('SecretVault keyfile must contain 32 raw bytes or 64 hexadecimal characters.');
	}

	/**
	 * Encrypt a plaintext secret using AES-256-GCM bound to optional AAD context.
	 *
	 * @param string $plaintext Secret value to protect.
	 * @param string $aad Associated authenticated data (for example, account_code).
	 * @return string Encoded ciphertext envelope (v1:<base64(iv|tag|ciphertext)>).
	 */
	public function encrypt(string $plaintext, string $aad = ''): string {
		$iv         = random_bytes(self::IV_BYTES);
		$tag        = '';
		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$this->key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			$aad,
			self::TAG_BYTES
		);

		if (!is_string($ciphertext) || strlen($tag) !== self::TAG_BYTES) {
			throw new BillingException('AES-256-GCM encryption failed.');
		}

		return self::VERSION . ':' . base64_encode($iv . $tag . $ciphertext);
	}

	/**
	 * Decrypt and authenticate an AES-256-GCM ciphertext envelope.
	 *
	 * @param string $envelope Encoded ciphertext envelope.
	 * @param string $aad Expected associated authenticated data.
	 * @return string Decrypted plaintext secret.
	 */
	public function decrypt(string $envelope, string $aad = ''): string {
		$prefix = self::VERSION . ':';

		if (!str_starts_with($envelope, $prefix)) {
			throw new BillingException('Unsupported SecretVault ciphertext envelope version.');
		}

		$raw = base64_decode(substr($envelope, strlen($prefix)), true);

		if (!is_string($raw) || strlen($raw) < self::IV_BYTES + self::TAG_BYTES) {
			throw new BillingException('Malformed SecretVault ciphertext payload.');
		}

		$iv         = substr($raw, 0, self::IV_BYTES);
		$tag        = substr($raw, self::IV_BYTES, self::TAG_BYTES);
		$ciphertext = substr($raw, self::IV_BYTES + self::TAG_BYTES);

		$plaintext = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			$this->key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			$aad
		);

		if (!is_string($plaintext)) {
			throw new BillingException('SecretVault authentication failed (wrong key, AAD, or tampered ciphertext).');
		}

		return $plaintext;
	}
}
