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
			if ($action === 'save_account') {
				$webhookUrl = isset_request_var('webhook_url') ? trim((string) get_nfilter_request_var('webhook_url')) : '';

				if ($webhookUrl !== '') {
					(new \Somethingwithproof\Billing\Security\OutboundGuard())->validateUrl($webhookUrl);
				}

				$billing_repo->upsertAccount(array(
					'account_code'   => isset_request_var('account_code') ? trim((string) get_nfilter_request_var('account_code')) : '',
					'name'           => isset_request_var('name') ? trim((string) get_nfilter_request_var('name')) : '',
					'currency'       => isset_request_var('currency') ? strtoupper(trim((string) get_nfilter_request_var('currency'))) : 'USD',
					'connector_type' => isset_request_var('connector_type') ? strtolower(trim((string) get_nfilter_request_var('connector_type'))) : 'webhook',
					'webhook_url'    => $webhookUrl,
					'status'         => 'active',
				));
				$billing_messages[] = __('Saved billing account.', 'billing');
			} elseif ($action === 'save_config') {
				$current   = billing_settings();
				$candidate = array(
					'billing_default_percentile'    => isset_request_var('billing_default_percentile') ? (float) get_nfilter_request_var('billing_default_percentile') : $current->defaultPercentile,
					'billing_default_drop_rounding' => isset_request_var('billing_default_drop_rounding') ? trim((string) get_nfilter_request_var('billing_default_drop_rounding')) : $current->defaultDropRounding,
					'billing_default_gap_policy'    => isset_request_var('billing_default_gap_policy') ? trim((string) get_nfilter_request_var('billing_default_gap_policy')) : $current->defaultGapPolicy,
					'billing_default_currency'      => isset_request_var('billing_default_currency') ? strtoupper(trim((string) get_nfilter_request_var('billing_default_currency'))) : $current->defaultCurrency,
					'billing_spike_multiplier'      => isset_request_var('billing_spike_multiplier') ? (float) get_nfilter_request_var('billing_spike_multiplier') : $current->spikeMultiplier,
					'billing_outbound_timeout'      => isset_request_var('billing_outbound_timeout') ? (int) get_nfilter_request_var('billing_outbound_timeout') : $current->outboundTimeout,
					'billing_max_response_bytes'    => $current->maxResponseBytes,
					'billing_secret_keyfile'        => $current->secretKeyfile,
					'billing_burst_warning_pct'     => $current->burstWarningPct,
					'billing_burst_critical_pct'    => $current->burstCriticalPct,
					'billing_uninstall_keep_tables' => $current->uninstallKeepTables,
				);

				$errors = \Somethingwithproof\Billing\Config\ConfigSchema::validate($candidate);

				if ($errors !== array()) {
					$billing_errors = $errors;
				} elseif (billing_save_settings(\Somethingwithproof\Billing\Settings::fromRow($candidate))) {
					$billing_messages[] = __('Saved plugin_billing configuration.', 'billing');
				} else {
					$billing_errors[] = __('Unable to save plugin_billing configuration.', 'billing');
				}
			} else {
				$billing_errors[] = __('Unsupported administrator action.', 'billing');
			}
		} catch (\Somethingwithproof\Billing\Ledger\BillingException $error) {
			$billing_errors[] = $error->getMessage();
		}
	}
}

$billing_csrf      = \Somethingwithproof\Billing\Ui\CsrfGuard::hiddenField($_SESSION);
$billing_settings  = billing_settings();
$billing_accounts  = $billing_repo->listAccounts();
$billing_contracts = $billing_repo->listContracts();

top_header();
html_start_box(__('Billing Accounts, Contracts, Tariffs & ERP Connectors (Administrator)', 'billing'), '100%', false, 3, 'center', '');
print '<tr><td>' . \Somethingwithproof\Billing\Ui\PagesRenderer::renderAdminConsole($billing_csrf, $billing_settings, $billing_accounts, $billing_contracts, $billing_messages, $billing_errors) . '</td></tr>';
html_end_box();
bottom_footer();
