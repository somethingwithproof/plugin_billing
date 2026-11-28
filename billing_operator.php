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

chdir('../../');
include('./include/auth.php');
include_once('./plugins/billing/setup.php');

if (!billing_page_start()) {
	exit;
}

$billing_repo     = billing_repository();
$billing_service  = billing_statement_service();
$billing_messages = array();
$billing_errors   = array();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
	$submitted_csrf = isset_request_var(\Somethingwithproof\Billing\Ui\CsrfGuard::FIELD_NAME)
		? (string) get_nfilter_request_var(\Somethingwithproof\Billing\Ui\CsrfGuard::FIELD_NAME)
		: '';

	if (!\Somethingwithproof\Billing\Ui\CsrfGuard::verify($_SESSION, $submitted_csrf)) {
		http_response_code(403);
		$billing_errors[] = __('CSRF verification failed. Reload the page and retry.', 'billing');
	} else {
		$action = isset_request_var('action') ? (string) get_nfilter_request_var('action') : '';

		try {
			if ($action === 'verify') {
				$code   = isset_request_var('statement_code') ? trim((string) get_nfilter_request_var('statement_code')) : '';
				$verify = $billing_service->verifyIntegrityByCode($code);

				if ($verify['valid']) {
					$billing_messages[] = __('Statement "%s" SHA-256 integrity verified.', $code, 'billing');
				} else {
					$billing_errors[] = __('Statement "%s" verification failed: %s', $code, $verify['reason'], 'billing');
				}
			} elseif ($action === 'dry_run') {
				$code     = isset_request_var('contract_code') ? trim((string) get_nfilter_request_var('contract_code')) : '';
				$startRaw = isset_request_var('period_start') ? trim((string) get_nfilter_request_var('period_start')) : '';
				$endRaw   = isset_request_var('period_end') ? trim((string) get_nfilter_request_var('period_end')) : '';
				$startTs  = strtotime($startRaw . ' UTC');
				$endTs    = strtotime($endRaw . ' UTC');

				if ($startTs === false || $endTs === false || $endTs <= $startTs) {
					$billing_errors[] = __('Provide valid UTC start and end dates.', 'billing');
				} else {
					$dryRun = $billing_service->rateDryRun($code, $startTs, $endTs);
					$rating = is_array($dryRun['rating'] ?? null) ? $dryRun['rating'] : array();
					$total  = \Somethingwithproof\Billing\Connector\ConnectorPayloadBuilder::formatDecimalMajor((int) ($rating['total_amount_minor'] ?? 0));
					$curr   = (string) ($rating['currency'] ?? 'USD');
					$billing_messages[] = __('Dry-run rating for %s: %s %s', $code, $curr, $total, 'billing');
				}
			} elseif ($action === 'export_csv') {
				$code = isset_request_var('statement_code') ? trim((string) get_nfilter_request_var('statement_code')) : '';
				$stmt = $billing_repo->findStatementByCode($code);

				if ($stmt === null) {
					$billing_errors[] = __('Statement not found.', 'billing');
				} else {
					$acct = $billing_repo->findAccountById((int) ($stmt['account_id'] ?? 0)) ?? array();
					$csv  = \Somethingwithproof\Billing\Connector\CsvStatementExporter::exportStatements(array(
						array_merge($stmt, array(
							'account_code' => $acct['account_code'] ?? '',
							'account_name' => $acct['name'] ?? '',
						)),
					));
					header('Content-Type: text/csv; charset=utf-8');
					header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $code) . '.csv"');
					print $csv;
					exit;
				}
			} elseif ($action === 'export_focus') {
				$code = isset_request_var('statement_code') ? trim((string) get_nfilter_request_var('statement_code')) : '';
				$stmt = $billing_repo->findStatementByCode($code);

				if ($stmt === null) {
					$billing_errors[] = __('Statement not found.', 'billing');
				} else {
					$acct    = $billing_repo->findAccountById((int) ($stmt['account_id'] ?? 0)) ?? array();
					$builder = new \Somethingwithproof\Billing\Connector\ConnectorPayloadBuilder();
					$focus   = $builder->buildFocusExport($acct, $stmt);
					header('Content-Type: application/json; charset=utf-8');
					header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $code) . '-focus.json"');
					print json_encode(array('records' => $focus), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
					exit;
				}
			} else {
				$billing_errors[] = __('Unsupported operator action.', 'billing');
			}
		} catch (\Somethingwithproof\Billing\Ledger\BillingException $error) {
			$billing_errors[] = $error->getMessage();
		}
	}
}

$billing_csrf       = \Somethingwithproof\Billing\Ui\CsrfGuard::hiddenField($_SESSION);
$billing_statements = $billing_repo->listStatements(null, 100);
$billing_contracts  = $billing_repo->listContracts(null, 'active');

top_header();
html_start_box(__('Billing Statement Audit, Proof Verification & Exports (Operator)', 'billing'), '100%', false, 3, 'center', '');
print '<tr><td>' . \Somethingwithproof\Billing\Ui\PagesRenderer::renderOperatorConsole($billing_csrf, $billing_statements, $billing_contracts, $billing_messages, $billing_errors) . '</td></tr>';
html_end_box();
bottom_footer();
