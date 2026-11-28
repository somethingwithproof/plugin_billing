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

namespace Somethingwithproof\Billing\Connector;

/**
 * RFC 4180 CSV statement exporter with spreadsheet formula-injection neutralization
 * defending finance teams against CSV injection (=, +, -, @, tab, CR prefixes).
 */
final class CsvStatementExporter {
	/**
	 * CSV header columns emitted by exportStatements().
	 */
	public const COLUMNS = [
		'statement_code',
		'account_code',
		'account_name',
		'period_start_utc',
		'period_end_utc',
		'currency',
		'billable_units',
		'billable_mbps',
		'base_amount_minor',
		'overage_amount_minor',
		'transfer_amount_minor',
		'total_amount_minor',
		'total_amount_major',
		'sample_count',
		'dropped_count',
		'status',
		'proof_sha256',
	];

	/**
	 * Export a list of statement records (with account context) to RFC 4180 CRLF CSV.
	 *
	 * @param list<array<string, mixed>> $rows Statement rows enriched with account_code and account_name.
	 * @return string RFC 4180 CSV payload with CRLF line endings.
	 */
	public static function exportStatements(array $rows): string {
		$lines   = [self::encodeRow(self::COLUMNS)];

		foreach ($rows as $row) {
			$totalMinor = is_numeric($row['total_amount_minor'] ?? null) ? (int) $row['total_amount_minor'] : 0;
			$mbpsX1000  = is_numeric($row['billable_mbps_x1000'] ?? null) ? (int) $row['billable_mbps_x1000'] : 0;

			$fields = [
				self::asString($row['statement_code'] ?? ''),
				self::asString($row['account_code'] ?? ''),
				self::asString($row['account_name'] ?? ''),
				gmdate('Y-m-d\TH:i:s\Z', self::asInt($row['period_start'] ?? 0)),
				gmdate('Y-m-d\TH:i:s\Z', self::asInt($row['period_end'] ?? 0)),
				strtoupper(self::asString($row['currency'] ?? 'USD')),
				(string) self::asInt($row['billable_units'] ?? 0),
				sprintf('%d.%03d', intdiv($mbpsX1000, 1000), $mbpsX1000 % 1000),
				(string) self::asInt($row['base_amount_minor'] ?? 0),
				(string) self::asInt($row['overage_amount_minor'] ?? 0),
				(string) self::asInt($row['transfer_amount_minor'] ?? 0),
				(string) $totalMinor,
				ConnectorPayloadBuilder::formatDecimalMajor($totalMinor),
				(string) self::asInt($row['sample_count'] ?? 0),
				(string) self::asInt($row['dropped_count'] ?? 0),
				self::asString($row['status'] ?? 'finalized'),
				self::asString($row['proof_sha256'] ?? ''),
			];

			$lines[] = self::encodeRow($fields);
		}

		return implode("\r\n", $lines) . "\r\n";
	}

	/**
	 * Neutralize spreadsheet formula triggers before RFC 4180 quoting.
	 *
	 * Prefixes a leading single quote when the cell (or its first non-space/non-tab
	 * character) begins with =, +, -, @, tab, or carriage return, while preserving
	 * plain negative integers and decimals.
	 *
	 * @param string $value Raw cell text.
	 * @return string Neutralized cell text.
	 */
	public static function neutralizeCell(string $value): string {
		if ($value === '') {
			return '';
		}

		// Plain numeric strings (including negative decimal numbers like -12.34) are safe.
		if (preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $value) === 1) {
			return $value;
		}

		$first   = $value[0];
		$trimmed = ltrim($value, " \t\r\n");
		$lead    = $trimmed !== '' ? $trimmed[0] : '';

		if (
			in_array($first, ['=', '+', '-', '@', "\t", "\r"], true)
			|| in_array($lead, ['=', '+', '-', '@'], true)
		) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Encode one CSV row per RFC 4180 (double-quote fields containing comma, quote, CR, or LF).
	 *
	 * @param list<string> $fields Row cell strings.
	 * @return string
	 */
	public static function encodeRow(array $fields): string {
		$encoded = [];

		foreach ($fields as $field) {
			$safe = self::neutralizeCell($field);

			if (strpbrk($safe, ",\"\r\n") !== false) {
				$encoded[] = '"' . str_replace('"', '""', $safe) . '"';
			} else {
				$encoded[] = $safe;
			}
		}

		return implode(',', $encoded);
	}

	/**
	 * Coerce mixed value to integer.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private static function asInt(mixed $value): int {
		return is_numeric($value) ? (int) $value : 0;
	}

	/**
	 * Coerce mixed value to string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function asString(mixed $value): string {
		return is_scalar($value) ? (string) $value : '';
	}
}
