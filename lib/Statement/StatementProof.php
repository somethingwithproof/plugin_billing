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

namespace Somethingwithproof\Billing\Statement;

use Somethingwithproof\Billing\Ledger\BillingException;

/**
 * Canonical streaming SHA-256 tamper-evident proof generator and verifier
 * for finalized billing statements. Streams sections directly into hash_init()
 * so large dropped-peak interval lists do not duplicate memory buffers.
 */
final class StatementProof {
	/**
	 * Proof envelope version prefix embedded in every statement hash input.
	 */
	public const PROOF_VERSION = 'cacti-billing-proof-v1';

	/**
	 * Compute the canonical streaming SHA-256 hex digest for a statement header and snapshot.
	 *
	 * @param array<string, mixed> $header Statement scalar fields.
	 * @param array<string, mixed> $snapshot Contract tariff snapshot, line items, and dropped peak list.
	 * @return string 64-character lowercase SHA-256 hex digest.
	 */
	public static function computeDigest(array $header, array $snapshot): string {
		$ctx = hash_init('sha256');
		hash_update($ctx, self::PROOF_VERSION . "\n");

		$canonicalHeader = [
			'statement_code'        => self::asString($header['statement_code'] ?? ''),
			'account_id'            => self::asInt($header['account_id'] ?? 0),
			'contract_id'           => self::asInt($header['contract_id'] ?? 0),
			'period_start'          => self::asInt($header['period_start'] ?? 0),
			'period_end'            => self::asInt($header['period_end'] ?? 0),
			'currency'              => strtoupper(self::asString($header['currency'] ?? 'USD')),
			'billable_units'        => self::asInt($header['billable_units'] ?? 0),
			'billable_mbps_x1000'   => self::asInt($header['billable_mbps_x1000'] ?? 0),
			'total_transfer_bytes'  => self::asInt($header['total_transfer_bytes'] ?? 0),
			'base_amount_minor'     => self::asInt($header['base_amount_minor'] ?? 0),
			'overage_amount_minor'  => self::asInt($header['overage_amount_minor'] ?? 0),
			'transfer_amount_minor' => self::asInt($header['transfer_amount_minor'] ?? 0),
			'total_amount_minor'    => self::asInt($header['total_amount_minor'] ?? 0),
			'sample_count'          => self::asInt($header['sample_count'] ?? 0),
			'dropped_count'         => self::asInt($header['dropped_count'] ?? 0),
		];

		hash_update($ctx, self::encodeCanonical($canonicalHeader) . "\n");

		// Stream contract snapshot and line items separately from dropped peaks to bound peak memory.
		$contractSnap = is_array($snapshot['contract'] ?? null) ? $snapshot['contract'] : [];
		$lineItems    = is_array($snapshot['line_items'] ?? null) ? $snapshot['line_items'] : [];
		$droppedPeaks = is_array($snapshot['dropped_peaks'] ?? null) ? $snapshot['dropped_peaks'] : [];

		hash_update($ctx, self::encodeCanonical($contractSnap) . "\n");
		hash_update($ctx, self::encodeCanonical($lineItems) . "\n");
		hash_update($ctx, "peaks:" . count($droppedPeaks) . "\n");

		foreach ($droppedPeaks as $peak) {
			if (!is_array($peak)) {
				continue;
			}

			$ts  = self::asInt($peak['bucket_ts'] ?? 0);
			$val = self::asInt($peak['value'] ?? 0);
			hash_update($ctx, $ts . ':' . $val . "\n");
		}

		return hash_final($ctx);
	}

	/**
	 * Verify a persisted statement row's stored SHA-256 digest in constant time.
	 *
	 * @param array<string, mixed> $statementRow Row fetched from plugin_billing_statement.
	 * @return array{valid: bool, expected_digest: string, stored_digest: string, reason: string}
	 */
	public static function verify(array $statementRow): array {
		$stored = strtolower(trim(self::asString($statementRow['proof_sha256'] ?? '')));

		if (strlen($stored) !== 64 || !ctype_xdigit($stored)) {
			return [
				'valid'           => false,
				'expected_digest' => '',
				'stored_digest'   => $stored,
				'reason'          => 'Stored proof_sha256 is missing or malformed.',
			];
		}

		$snapshotJson = self::asString($statementRow['snapshot_json'] ?? '');
		$snapshot     = json_decode($snapshotJson, true);

		if (!is_array($snapshot)) {
			return [
				'valid'           => false,
				'expected_digest' => '',
				'stored_digest'   => $stored,
				'reason'          => 'Stored snapshot_json could not be decoded.',
			];
		}

		$normalized = [];

		foreach ($snapshot as $k => $v) {
			$normalized[(string) $k] = $v;
		}

		$expected = self::computeDigest($statementRow, $normalized);
		$valid    = hash_equals($expected, $stored);

		return [
			'valid'           => $valid,
			'expected_digest' => $expected,
			'stored_digest'   => $stored,
			'reason'          => $valid ? 'SHA-256 proof digest verified.' : 'SHA-256 proof mismatch: statement or snapshot was altered.',
		];
	}

	/**
	 * Recursively sort associative array keys and encode deterministic JSON.
	 *
	 * @param mixed $data Arbitrary JSON-serializable structure.
	 * @return string
	 */
	public static function encodeCanonical(mixed $data): string {
		$normalized = self::sortRecursive($data);
		$json       = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

		if (!is_string($json)) {
			throw new BillingException('Unable to serialize canonical statement proof payload.');
		}

		return $json;
	}

	/**
	 * Recursively sort associative arrays by key while preserving list ordering.
	 *
	 * @param mixed $value Arbitrary value.
	 * @return mixed
	 */
	private static function sortRecursive(mixed $value): mixed {
		if (!is_array($value)) {
			return $value;
		}

		if (array_is_list($value)) {
			return array_map(static fn (mixed $item): mixed => self::sortRecursive($item), $value);
		}

		ksort($value, SORT_STRING);

		foreach ($value as $k => $v) {
			$value[$k] = self::sortRecursive($v);
		}

		return $value;
	}

	/**
	 * Safely coerce mixed scalar to integer.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private static function asInt(mixed $value): int {
		return is_numeric($value) ? (int) $value : 0;
	}

	/**
	 * Safely coerce mixed scalar to string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function asString(mixed $value): string {
		return is_scalar($value) ? (string) $value : '';
	}
}
