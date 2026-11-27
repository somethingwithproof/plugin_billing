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

namespace Somethingwithproof\Billing;

/**
 * Typed immutable settings value object persisted in Cacti's settings table.
 */
final class Settings {
	/**
	 * Supported drop-count rounding strategies.
	 */
	public const DROP_ROUNDINGS = ['ceil', 'floor', 'nearest'];

	/**
	 * Supported missing-interval gap policies.
	 */
	public const GAP_POLICIES = ['exclude', 'zero_fill', 'carry_forward'];

	/**
	 * Canonical Cacti settings keys managed by plugin_billing.
	 */
	public const NAMES = [
		'billing_default_percentile',
		'billing_default_drop_rounding',
		'billing_default_gap_policy',
		'billing_default_currency',
		'billing_spike_multiplier',
		'billing_outbound_timeout',
		'billing_max_response_bytes',
		'billing_secret_keyfile',
		'billing_burst_warning_pct',
		'billing_burst_critical_pct',
		'billing_uninstall_keep_tables',
	];

	/**
	 * Construct a validated immutable billing Settings snapshot.
	 *
	 * @param float $defaultPercentile Default Nth percentile (50.0 to 100.0).
	 * @param string $defaultDropRounding Default drop-count rounding mode (ceil, floor, nearest).
	 * @param string $defaultGapPolicy Default missing-interval policy (exclude, zero_fill, carry_forward).
	 * @param string $defaultCurrency ISO 4217 uppercase 3-letter currency code.
	 * @param float $spikeMultiplier Counter spike sanity cap multiplier relative to port speed.
	 * @param int $outboundTimeout Outbound HTTPS webhook timeout in seconds.
	 * @param int $maxResponseBytes Maximum accepted webhook HTTP response size in bytes.
	 * @param string $secretKeyfile Optional absolute path to the AES-256-GCM external keyfile.
	 * @param int $burstWarningPct Burst slot consumption percentage raising a warning.
	 * @param int $burstCriticalPct Burst slot consumption percentage raising a critical alert.
	 * @param bool $uninstallKeepTables Whether plugin uninstall preserves billing ledger tables.
	 */
	public function __construct(
		public readonly float $defaultPercentile = 95.0,
		public readonly string $defaultDropRounding = 'floor',
		public readonly string $defaultGapPolicy = 'exclude',
		public readonly string $defaultCurrency = 'USD',
		public readonly float $spikeMultiplier = 1.5,
		public readonly int $outboundTimeout = 10,
		public readonly int $maxResponseBytes = 65536,
		public readonly string $secretKeyfile = '',
		public readonly int $burstWarningPct = 70,
		public readonly int $burstCriticalPct = 90,
		public readonly bool $uninstallKeepTables = true,
	) {
	}

	/**
	 * Hydrate a Settings snapshot from a raw Cacti settings row or declarative config map.
	 *
	 * @param array<string, mixed> $row Raw key-value map.
	 * @return self
	 */
	public static function fromRow(array $row): self {
		$percentile = isset($row['billing_default_percentile']) && is_numeric($row['billing_default_percentile'])
			? (float) $row['billing_default_percentile']
			: 95.0;
		$percentile = max(50.0, min(100.0, $percentile));

		$dropRounding = is_string($row['billing_default_drop_rounding'] ?? null)
			? strtolower(trim($row['billing_default_drop_rounding']))
			: 'floor';

		if (!in_array($dropRounding, self::DROP_ROUNDINGS, true)) {
			$dropRounding = 'floor';
		}

		$gapPolicy = is_string($row['billing_default_gap_policy'] ?? null)
			? strtolower(trim($row['billing_default_gap_policy']))
			: 'exclude';

		if (!in_array($gapPolicy, self::GAP_POLICIES, true)) {
			$gapPolicy = 'exclude';
		}

		$currency = is_string($row['billing_default_currency'] ?? null)
			? strtoupper(trim($row['billing_default_currency']))
			: 'USD';

		if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
			$currency = 'USD';
		}

		$spike = isset($row['billing_spike_multiplier']) && is_numeric($row['billing_spike_multiplier'])
			? (float) $row['billing_spike_multiplier']
			: 1.5;
		$spike = max(1.0, min(5.0, $spike));

		$timeout = isset($row['billing_outbound_timeout']) && is_numeric($row['billing_outbound_timeout'])
			? (int) $row['billing_outbound_timeout']
			: 10;
		$timeout = max(1, min(60, $timeout));

		$maxResp = isset($row['billing_max_response_bytes']) && is_numeric($row['billing_max_response_bytes'])
			? (int) $row['billing_max_response_bytes']
			: 65536;
		$maxResp = max(1024, min(1048576, $maxResp));

		$keyfile = is_string($row['billing_secret_keyfile'] ?? null)
			? trim($row['billing_secret_keyfile'])
			: '';

		$warnPct = isset($row['billing_burst_warning_pct']) && is_numeric($row['billing_burst_warning_pct'])
			? (int) $row['billing_burst_warning_pct']
			: 70;
		$warnPct = max(10, min(99, $warnPct));

		$critPct = isset($row['billing_burst_critical_pct']) && is_numeric($row['billing_burst_critical_pct'])
			? (int) $row['billing_burst_critical_pct']
			: 90;
		$critPct = max($warnPct + 1 <= 100 ? $warnPct + 1 : 100, min(100, $critPct));

		$keepRaw    = $row['billing_uninstall_keep_tables'] ?? 'on';
		$keepTables = $keepRaw === '' ? true : self::parseBool($keepRaw);

		return new self(
			$percentile,
			$dropRounding,
			$gapPolicy,
			$currency,
			$spike,
			$timeout,
			$maxResp,
			$keyfile,
			$warnPct,
			$critPct,
			$keepTables,
		);
	}

	/**
	 * Convert to a declarative Config array suitable for CasC export (never includes secret values).
	 *
	 * @return array<string, bool|float|int|string>
	 */
	public function toConfigArray(): array {
		return [
			'billing_default_percentile'    => $this->defaultPercentile,
			'billing_default_drop_rounding' => $this->defaultDropRounding,
			'billing_default_gap_policy'    => $this->defaultGapPolicy,
			'billing_default_currency'      => $this->defaultCurrency,
			'billing_spike_multiplier'      => $this->spikeMultiplier,
			'billing_outbound_timeout'      => $this->outboundTimeout,
			'billing_max_response_bytes'    => $this->maxResponseBytes,
			'billing_secret_keyfile'        => $this->secretKeyfile,
			'billing_burst_warning_pct'     => $this->burstWarningPct,
			'billing_burst_critical_pct'    => $this->burstCriticalPct,
			'billing_uninstall_keep_tables' => $this->uninstallKeepTables,
		];
	}

	/**
	 * Convert to Cacti settings table string representations ('on' / '' for booleans).
	 *
	 * @return array<string, string>
	 */
	public function toCactiSettingsMap(): array {
		return [
			'billing_default_percentile'    => (string) $this->defaultPercentile,
			'billing_default_drop_rounding' => $this->defaultDropRounding,
			'billing_default_gap_policy'    => $this->defaultGapPolicy,
			'billing_default_currency'      => $this->defaultCurrency,
			'billing_spike_multiplier'      => (string) $this->spikeMultiplier,
			'billing_outbound_timeout'      => (string) $this->outboundTimeout,
			'billing_max_response_bytes'    => (string) $this->maxResponseBytes,
			'billing_secret_keyfile'        => $this->secretKeyfile,
			'billing_burst_warning_pct'     => (string) $this->burstWarningPct,
			'billing_burst_critical_pct'    => (string) $this->burstCriticalPct,
			'billing_uninstall_keep_tables' => $this->uninstallKeepTables ? 'on' : '',
		];
	}

	/**
	 * Return Cacti Settings tab form metadata for Console -> Configuration -> Settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function form(): array {
		return [
			'billing_header_rating' => [
				'friendly_name' => __('Rating & 95th-Percentile Defaults', 'billing'),
				'method'        => 'spacer',
			],
			'billing_default_percentile' => [
				'friendly_name' => __('Default Percentile', 'billing'),
				'description'   => __('Default Nth percentile applied to new burstable contracts (50.0 to 100.0).', 'billing'),
				'method'        => 'textbox',
				'default'       => '95.0',
				'max_length'    => 6,
				'size'          => 8,
			],
			'billing_default_drop_rounding' => [
				'friendly_name' => __('Default Drop-Count Rounding', 'billing'),
				'description'   => __('Rounding mode for peak intervals dropped when computing the Nth percentile.', 'billing'),
				'method'        => 'drop_array',
				'default'       => 'floor',
				'array'         => [
					'floor'   => __('Floor', 'billing'),
					'ceil'    => __('Ceiling', 'billing'),
					'nearest' => __('Nearest', 'billing'),
				],
			],
			'billing_default_gap_policy' => [
				'friendly_name' => __('Default Missing-Sample Policy', 'billing'),
				'description'   => __('How missing 5-minute intervals are treated during rating.', 'billing'),
				'method'        => 'drop_array',
				'default'       => 'exclude',
				'array'         => [
					'exclude'       => __('Exclude gaps', 'billing'),
					'zero_fill'     => __('Zero-fill missing slots', 'billing'),
					'carry_forward' => __('Carry forward previous sample', 'billing'),
				],
			],
			'billing_default_currency' => [
				'friendly_name' => __('Default ISO 4217 Currency', 'billing'),
				'description'   => __('Three-letter uppercase ISO 4217 currency code for new accounts and contracts.', 'billing'),
				'method'        => 'textbox',
				'default'       => 'USD',
				'max_length'    => 3,
				'size'          => 6,
			],
			'billing_spike_multiplier' => [
				'friendly_name' => __('Counter Spike Cap Multiplier', 'billing'),
				'description'   => __('Maximum bps multiple of interface port speed before clamping counter spikes.', 'billing'),
				'method'        => 'textbox',
				'default'       => '1.5',
				'max_length'    => 5,
				'size'          => 6,
			],
			'billing_header_security' => [
				'friendly_name' => __('Security, ERP Webhooks, and Retention', 'billing'),
				'method'        => 'spacer',
			],
			'billing_outbound_timeout' => [
				'friendly_name' => __('Outbound Webhook Timeout (seconds)', 'billing'),
				'description'   => __('Bounded HTTPS timeout for ERP billing connector deliveries (1 to 60s).', 'billing'),
				'method'        => 'textbox',
				'default'       => '10',
				'max_length'    => 3,
				'size'          => 6,
			],
			'billing_max_response_bytes' => [
				'friendly_name' => __('Maximum Webhook Response Bytes', 'billing'),
				'description'   => __('Upper bound on bytes read from ERP webhook responses.', 'billing'),
				'method'        => 'textbox',
				'default'       => '65536',
				'max_length'    => 8,
				'size'          => 10,
			],
			'billing_secret_keyfile' => [
				'friendly_name' => __('AES-256-GCM External Keyfile Path', 'billing'),
				'description'   => __('Absolute filesystem path to the external 256-bit keyfile protecting ERP connector secrets.', 'billing'),
				'method'        => 'textbox',
				'default'       => '',
				'max_length'    => 240,
				'size'          => 50,
			],
			'billing_burst_warning_pct' => [
				'friendly_name' => __('Burst Budget Warning Threshold (%)', 'billing'),
				'description'   => __('Percentage of monthly burst slots consumed before raising a warning forecast.', 'billing'),
				'method'        => 'textbox',
				'default'       => '70',
				'max_length'    => 3,
				'size'          => 6,
			],
			'billing_burst_critical_pct' => [
				'friendly_name' => __('Burst Budget Critical Threshold (%)', 'billing'),
				'description'   => __('Percentage of monthly burst slots consumed before raising a critical forecast.', 'billing'),
				'method'        => 'textbox',
				'default'       => '90',
				'max_length'    => 3,
				'size'          => 6,
			],
			'billing_uninstall_keep_tables' => [
				'friendly_name' => __('Preserve Ledger Tables on Uninstall', 'billing'),
				'description'   => __('Keep plugin_billing_* tables (including immutable samples and statements) when uninstalling.', 'billing'),
				'method'        => 'checkbox',
				'default'       => 'on',
			],
		];
	}

	/**
	 * Parse boolean representations from Cacti settings or YAML/JSON configs.
	 *
	 * @param mixed $value Raw scalar value.
	 * @return bool
	 */
	private static function parseBool(mixed $value): bool {
		if (is_bool($value)) {
			return $value;
		}

		if (is_int($value)) {
			return $value !== 0;
		}

		if (is_string($value)) {
			return in_array(strtolower(trim($value)), ['1', 'on', 'true', 'yes'], true);
		}

		return false;
	}
}
