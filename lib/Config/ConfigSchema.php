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

namespace Somethingwithproof\Billing\Config;

use Somethingwithproof\Billing\Settings;

/**
 * Declarative Config vs. State validator and deterministic plan hash generator
 * aligned with schema/billing.schema.yaml. Rejects State tables and raw secrets.
 */
final class ConfigSchema {
	/**
	 * Declared plugin_billing CasC schema version.
	 */
	public const SCHEMA_VERSION = '1.0';

	/**
	 * Disallowed State or secret keys that must never appear in declarative Config.
	 */
	private const FORBIDDEN_KEYS = [
		'plugin_billing_sample',
		'plugin_billing_statement',
		'plugin_billing_export_log',
		'samples',
		'statements',
		'export_logs',
		'secret_ciphertext',
		'signing_secret',
		'api_key',
	];

	/**
	 * Validate a declarative plugin_billing configuration array.
	 *
	 * @param array<string, mixed> $config Candidate configuration map.
	 * @return list<string> Validation errors (empty list means valid).
	 */
	public static function validate(array $config): array {
		$errors = [];

		foreach (array_keys($config) as $key) {
			$name = (string) $key;

			if (in_array($name, self::FORBIDDEN_KEYS, true)) {
				$errors[] = sprintf('Key "%s" is immutable State or secret material and is forbidden in declarative Config.', $name);

				continue;
			}

			if (!in_array($name, Settings::NAMES, true)) {
				$errors[] = sprintf('Unknown plugin_billing configuration key "%s".', $name);
			}
		}

		if (array_key_exists('billing_default_percentile', $config)) {
			$val = $config['billing_default_percentile'];

			if ((!is_int($val) && !is_float($val)) || (float) $val < 50.0 || (float) $val > 100.0) {
				$errors[] = 'billing_default_percentile must be a number between 50.0 and 100.0.';
			}
		}

		if (array_key_exists('billing_default_drop_rounding', $config)) {
			$val = $config['billing_default_drop_rounding'];

			if (!is_string($val) || !in_array($val, Settings::DROP_ROUNDINGS, true)) {
				$errors[] = 'billing_default_drop_rounding must be one of: ' . implode(', ', Settings::DROP_ROUNDINGS) . '.';
			}
		}

		if (array_key_exists('billing_default_gap_policy', $config)) {
			$val = $config['billing_default_gap_policy'];

			if (!is_string($val) || !in_array($val, Settings::GAP_POLICIES, true)) {
				$errors[] = 'billing_default_gap_policy must be one of: ' . implode(', ', Settings::GAP_POLICIES) . '.';
			}
		}

		if (array_key_exists('billing_default_currency', $config)) {
			$val = $config['billing_default_currency'];

			if (!is_string($val) || preg_match('/^[A-Z]{3}$/', $val) !== 1) {
				$errors[] = 'billing_default_currency must be a 3-letter uppercase ISO 4217 code.';
			}
		}

		if (array_key_exists('billing_spike_multiplier', $config)) {
			$val = $config['billing_spike_multiplier'];

			if ((!is_int($val) && !is_float($val)) || (float) $val < 1.0 || (float) $val > 5.0) {
				$errors[] = 'billing_spike_multiplier must be between 1.0 and 5.0.';
			}
		}

		if (array_key_exists('billing_outbound_timeout', $config)) {
			$val = $config['billing_outbound_timeout'];

			if (!is_int($val) || $val < 1 || $val > 60) {
				$errors[] = 'billing_outbound_timeout must be an integer between 1 and 60.';
			}
		}

		if (array_key_exists('billing_max_response_bytes', $config)) {
			$val = $config['billing_max_response_bytes'];

			if (!is_int($val) || $val < 1024 || $val > 1048576) {
				$errors[] = 'billing_max_response_bytes must be an integer between 1024 and 1048576.';
			}
		}

		if (array_key_exists('billing_secret_keyfile', $config)) {
			$val = $config['billing_secret_keyfile'];

			if (!is_string($val) || ($val !== '' && preg_match('#^/[A-Za-z0-9._/-]{1,240}$#', $val) !== 1) || str_contains($val, '..')) {
				$errors[] = 'billing_secret_keyfile must be empty or a clean absolute path without traversal.';
			}
		}

		if (array_key_exists('billing_burst_warning_pct', $config)) {
			$val = $config['billing_burst_warning_pct'];

			if (!is_int($val) || $val < 10 || $val > 99) {
				$errors[] = 'billing_burst_warning_pct must be an integer between 10 and 99.';
			}
		}

		if (array_key_exists('billing_burst_critical_pct', $config)) {
			$val = $config['billing_burst_critical_pct'];

			if (!is_int($val) || $val < 20 || $val > 100) {
				$errors[] = 'billing_burst_critical_pct must be an integer between 20 and 100.';
			}
		}

		if (
			isset($config['billing_burst_warning_pct'], $config['billing_burst_critical_pct'])
			&& is_int($config['billing_burst_warning_pct'])
			&& is_int($config['billing_burst_critical_pct'])
			&& $config['billing_burst_warning_pct'] >= $config['billing_burst_critical_pct']
		) {
			$errors[] = 'billing_burst_warning_pct must be strictly less than billing_burst_critical_pct.';
		}

		if (array_key_exists('billing_uninstall_keep_tables', $config) && !is_bool($config['billing_uninstall_keep_tables'])) {
			$errors[] = 'billing_uninstall_keep_tables must be a boolean.';
		}

		return $errors;
	}

	/**
	 * Compute a deterministic plan diff and SHA-256 plan confirmation hash.
	 *
	 * @param array<string, bool|float|int|string> $current Live configuration array.
	 * @param array<string, mixed> $desired Target configuration array.
	 * @return array{plan_hash: string, actions: list<array{operation: string, key: string, before: mixed, after: mixed}>}
	 */
	public static function plan(array $current, array $desired): array {
		ksort($desired, SORT_STRING);
		$actions = [];

		foreach ($desired as $field => $targetValue) {
			$liveValue = $current[$field] ?? null;

			if ($liveValue !== $targetValue) {
				$actions[] = [
					'operation' => 'update',
					'key'       => 'billing.' . $field,
					'before'    => $liveValue,
					'after'     => $targetValue,
				];
			}
		}

		$encoded  = json_encode($actions, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) ?: '[]';
		$planHash = hash('sha256', self::SCHEMA_VERSION . ':' . $encoded);

		return [
			'plan_hash' => $planHash,
			'actions'   => $actions,
		];
	}
}
