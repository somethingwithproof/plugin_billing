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

namespace Somethingwithproof\Billing\Integration;

use Somethingwithproof\Billing\Ledger\BillingRepository;
use Somethingwithproof\Billing\Settings;

/**
 * Plain-list pre-evaluated diagnostic checks and redacted debug info sections
 * exposed to doctor_register_checks and doctor_register_debug_info hooks
 * without importing any external diagnostic plugin classes.
 */
final class DoctorChecks {
	/**
	 * Evaluate and return a plain list of diagnostic check descriptors.
	 *
	 * @param callable(): BillingRepository $repoFactory Lazy repository provider.
	 * @param callable(): Settings $settingsProvider Lazy settings provider.
	 * @param string $mode Execution mode ('run' or 'list').
	 * @return list<array{
	 *   id: string,
	 *   plugin: string,
	 *   category: string,
	 *   title: string,
	 *   status: string,
	 *   message: string,
	 *   details: array<string, mixed>,
	 *   remediation: string
	 * }>
	 */
	public static function buildChecks(callable $repoFactory, callable $settingsProvider, string $mode = 'run'): array {
		if ($mode === 'list') {
			return [
				[
					'id'          => 'billing.ledger_health',
					'plugin'      => 'billing',
					'category'    => 'billing',
					'title'       => 'Billing Immutable Ledger & Catalog Readiness',
					'status'      => 'info',
					'message'     => 'Checks billing schema availability, active accounts, contracts, and 24-hour sample ingestion.',
					'details'     => [],
					'remediation' => 'Verify plugin_billing tables exist and poller ingestion is running.',
				],
				[
					'id'          => 'billing.erp_export_failures',
					'plugin'      => 'billing',
					'category'    => 'billing',
					'title'       => 'Billing ERP Webhook Delivery Health',
					'status'      => 'info',
					'message'     => 'Checks plugin_billing_export_log for failed ERP webhook deliveries.',
					'details'     => [],
					'remediation' => 'Inspect export logs, verify HTTPS connectivity and signing secrets, and retry failed statements.',
				],
				[
					'id'          => 'billing.secret_vault_keyfile',
					'plugin'      => 'billing',
					'category'    => 'security',
					'title'       => 'Billing SecretVault External Keyfile Check',
					'status'      => 'info',
					'message'     => 'Verifies that any configured AES-256-GCM external keyfile is present and readable.',
					'details'     => [],
					'remediation' => 'Ensure the configured keyfile exists with restricted permissions readable by the Cacti runtime user.',
				],
			];
		}

		$checks = [];

		try {
			$counts   = $repoFactory()->operationalCounts();
			$checks[] = [
				'id'          => 'billing.ledger_health',
				'plugin'      => 'billing',
				'category'    => 'billing',
				'title'       => 'Billing Immutable Ledger & Catalog Readiness',
				'status'      => 'ok',
				'message'     => sprintf(
					'Billing ledger online (%d active accounts, %d active contracts, %d samples in last 24h).',
					$counts['accounts_active'],
					$counts['contracts_active'],
					$counts['samples_24h']
				),
				'details'     => $counts,
				'remediation' => '',
			];

			$failed   = $counts['exports_failed'];
			$checks[] = [
				'id'          => 'billing.erp_export_failures',
				'plugin'      => 'billing',
				'category'    => 'billing',
				'title'       => 'Billing ERP Webhook Delivery Health',
				'status'      => $failed > 0 ? 'warn' : 'ok',
				'message'     => $failed > 0
					? sprintf('%d ERP webhook export delivery failure(s) recorded.', $failed)
					: 'Zero failed ERP webhook deliveries.',
				'details'     => ['exports_failed' => $failed],
				'remediation' => $failed > 0 ? 'Review plugin_billing_export_log and verify ERP endpoint TLS and signing keys.' : '',
			];
		} catch (\Throwable $error) {
			$checks[] = [
				'id'          => 'billing.ledger_health',
				'plugin'      => 'billing',
				'category'    => 'billing',
				'title'       => 'Billing Immutable Ledger & Catalog Readiness',
				'status'      => 'fail',
				'message'     => 'Billing ledger probe failed: ' . $error->getMessage(),
				'details'     => ['exception' => $error::class],
				'remediation' => 'Run plugin_billing_upgrade() and verify MariaDB connectivity.',
			];
			$checks[] = [
				'id'          => 'billing.erp_export_failures',
				'plugin'      => 'billing',
				'category'    => 'billing',
				'title'       => 'Billing ERP Webhook Delivery Health',
				'status'      => 'fail',
				'message'     => 'Unable to inspect export logs because the billing repository failed.',
				'details'     => ['exception' => $error::class],
				'remediation' => 'Resolve database connectivity and re-run diagnostics.',
			];
		}

		$keyfile = $settingsProvider()->secretKeyfile;

		if ($keyfile === '') {
			$checks[] = [
				'id'          => 'billing.secret_vault_keyfile',
				'plugin'      => 'billing',
				'category'    => 'security',
				'title'       => 'Billing SecretVault External Keyfile Check',
				'status'      => 'ok',
				'message'     => 'No external SecretVault keyfile configured.',
				'details'     => ['keyfile_configured' => false],
				'remediation' => '',
			];
		} else {
			$readable = is_file($keyfile) && is_readable($keyfile);
			$checks[] = [
				'id'          => 'billing.secret_vault_keyfile',
				'plugin'      => 'billing',
				'category'    => 'security',
				'title'       => 'Billing SecretVault External Keyfile Check',
				'status'      => $readable ? 'ok' : 'fail',
				'message'     => $readable
					? 'SecretVault external keyfile is present and readable.'
					: 'Configured SecretVault keyfile is missing or unreadable.',
				'details'     => ['keyfile_configured' => true, 'readable' => $readable],
				'remediation' => $readable ? '' : 'Create a 32-byte or 64-hex-char keyfile with mode 0600 readable by Cacti.',
			];
		}

		return $checks;
	}

	/**
	 * Build a plain list of redacted debug info sections (zero secrets, scalar/null field values only).
	 *
	 * @param callable(): BillingRepository $repoFactory Lazy repository provider.
	 * @param callable(): Settings $settingsProvider Lazy settings provider.
	 * @return list<array{
	 *   id: string,
	 *   plugin: string,
	 *   label: string,
	 *   fields: array<string, array{label: string, value: bool|float|int|string|null}>
	 * }>
	 */
	public static function buildDebugInfo(callable $repoFactory, callable $settingsProvider): array {
		$settings = $settingsProvider();
		$counts   = $repoFactory()->operationalCounts();

		return [
			[
				'id'     => 'billing.overview',
				'plugin' => 'billing',
				'label'  => 'Burstable Transit & Usage Billing (plugin_billing)',
				'fields' => [
					'default_percentile'        => ['label' => 'Default Percentile', 'value' => $settings->defaultPercentile],
					'default_drop_rounding'     => ['label' => 'Default Drop Rounding', 'value' => $settings->defaultDropRounding],
					'default_gap_policy'        => ['label' => 'Default Gap Policy', 'value' => $settings->defaultGapPolicy],
					'default_currency'          => ['label' => 'Default Currency', 'value' => $settings->defaultCurrency],
					'spike_multiplier'          => ['label' => 'Spike Sanity Cap Multiplier', 'value' => $settings->spikeMultiplier],
					'outbound_timeout'          => ['label' => 'Outbound HTTPS Timeout (s)', 'value' => $settings->outboundTimeout],
					'secret_keyfile_configured' => ['label' => 'SecretVault Keyfile Configured', 'value' => $settings->secretKeyfile !== ''],
					'secret_keyfile_value'      => ['label' => 'SecretVault Keyfile Material', 'value' => '[REDACTED]'],
					'accounts_active'           => ['label' => 'Active Billing Accounts', 'value' => $counts['accounts_active']],
					'contracts_active'          => ['label' => 'Active Billing Contracts', 'value' => $counts['contracts_active']],
					'circuits_total'            => ['label' => 'Metered Circuits', 'value' => $counts['circuits_total']],
					'statements_finalized'      => ['label' => 'Finalized Statements', 'value' => $counts['statements_finalized']],
					'exports_failed'            => ['label' => 'Failed ERP Exports', 'value' => $counts['exports_failed']],
					'samples_24h'               => ['label' => 'Ingested Samples (24h)', 'value' => $counts['samples_24h']],
				],
			],
		];
	}
}
