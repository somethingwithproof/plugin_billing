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

/*
 * PHP 8.0-parseable Cacti Script Server entry point emitting operational
 * billing telemetry in Cacti multi-field output format. Returns 'U' on error
 * so RRDtool graphs record an unknown gap instead of false zeros.
 */

$billingScriptFilename = isset($_SERVER['SCRIPT_FILENAME']) && is_string($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '';

if (!isset($called_by_script_server) && PHP_SAPI === 'cli' && $billingScriptFilename !== '' && realpath($billingScriptFilename) === __FILE__) {
	$cactiRoot = dirname(__DIR__, 3);

	if (is_file($cactiRoot . '/include/cli_check.php')) {
		chdir($cactiRoot);
		require_once($cactiRoot . '/include/cli_check.php');
	}

	require_once(dirname(__DIR__) . '/setup.php');
	print ss_billing_stats() . PHP_EOL;
}

/**
 * Emit space-separated Cacti Script Server billing telemetry fields, or 'U' on failure.
 *
 * @return string Space-separated key:value pairs or 'U'.
 */
function ss_billing_stats(): string {
	if (!function_exists('billing_boot')) {
		require_once(dirname(__DIR__) . '/setup.php');
	}

	if (!billing_boot()) {
		return 'U';
	}

	try {
		$repo       = billing_repository();
		$forecaster = billing_forecaster();
		$counts     = $repo->operationalCounts();
		$now        = time();
		$monthStart = (int) gmmktime(0, 0, 0, (int) gmdate('n', $now), 1, (int) gmdate('Y', $now));
		$monthEnd   = (int) gmmktime(0, 0, 0, (int) gmdate('n', $now) + 1, 1, (int) gmdate('Y', $now));

		$overCommitAccounts = array();
		$burstWarnings      = 0;

		foreach ($repo->listContracts(null, 'active') as $contract) {
			$contractId = is_numeric($contract['id'] ?? null) ? (int) $contract['id'] : 0;
			$accountId  = is_numeric($contract['account_id'] ?? null) ? (int) $contract['account_id'] : 0;
			$circuits   = $repo->listCircuitsForContract($contractId);

			if ($circuits === array()) {
				continue;
			}

			$ids = array();

			foreach ($circuits as $circuit) {
				if (is_numeric($circuit['id'] ?? null)) {
					$ids[] = (int) $circuit['id'];
				}
			}

			$samples  = $repo->fetchSamplesByCircuit($ids, $monthStart, $monthEnd);
			$forecast = $forecaster->forecast($contract, $samples, $monthStart, $monthEnd, $now);

			if ($forecast['over_commit']) {
				$overCommitAccounts[$accountId] = true;
			}

			if ($forecast['severity'] !== 'ok') {
				$burstWarnings++;
			}
		}

		return sprintf(
			'accounts_active:%d contracts_active:%d circuits_total:%d statements_finalized:%d accounts_over_commit:%d burst_warning_count:%d exports_failed:%d samples_24h:%d',
			$counts['accounts_active'],
			$counts['contracts_active'],
			$counts['circuits_total'],
			$counts['statements_finalized'],
			count($overCommitAccounts),
			$burstWarnings,
			$counts['exports_failed'],
			$counts['samples_24h']
		);
	} catch (\Throwable $error) {
		return 'U';
	}
}
