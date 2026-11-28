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

namespace Somethingwithproof\Billing\Ui;

use Somethingwithproof\Billing\Connector\ConnectorPayloadBuilder;
use Somethingwithproof\Billing\Settings;

/**
 * Escaped HTML renderer for the three Cacti Console views:
 * View (usage & burst forecasts), Operator (audit, proof verification, exports),
 * and Admin (accounts, contracts, circuits, connectors, settings).
 */
final class PagesRenderer {
	/**
	 * Render the Billing View dashboard showing customer usage and mid-cycle burst forecasts.
	 *
	 * @param list<array<string, mixed>> $forecasts Burst forecast records.
	 * @param array<string, int> $counts Operational counters.
	 * @return string Safe HTML markup.
	 */
	public static function renderViewDashboard(array $forecasts, array $counts): string {
		$html = '<div class="billing-dashboard">';
		$html .= '<p><strong>Active Accounts:</strong> ' . (int) ($counts['accounts_active'] ?? 0)
			. ' | <strong>Active Contracts:</strong> ' . (int) ($counts['contracts_active'] ?? 0)
			. ' | <strong>Circuits:</strong> ' . (int) ($counts['circuits_total'] ?? 0)
			. ' | <strong>Finalized Statements:</strong> ' . (int) ($counts['statements_finalized'] ?? 0)
			. ' | <strong>Samples (24h):</strong> ' . (int) ($counts['samples_24h'] ?? 0) . '</p>';

		$html .= '<table class="cactiTable"><thead><tr>'
			. '<th>Contract</th><th>Commit Units</th><th>Current Pth</th>'
			. '<th>Burst Slots Used / Budget</th><th>Remaining (min)</th>'
			. '<th>Projected Overage</th><th>Severity</th>'
			. '</tr></thead><tbody>';

		if ($forecasts === []) {
			$html .= '<tr><td colspan="7">No active billing contracts found.</td></tr>';
		}

		foreach ($forecasts as $row) {
			$overage = ConnectorPayloadBuilder::formatDecimalMajor(self::asInt($row['projected_overage_minor'] ?? 0));
			$html   .= '<tr>'
				. '<td>' . html_escape(self::asString($row['contract_code'] ?? '')) . '</td>'
				. '<td>' . self::asInt($row['commit_units'] ?? 0) . '</td>'
				. '<td>' . self::asInt($row['current_percentile_units'] ?? 0) . '</td>'
				. '<td>' . self::asInt($row['consumed_burst_slots'] ?? 0) . ' / ' . self::asInt($row['burst_budget_slots'] ?? 0) . '</td>'
				. '<td>' . self::asInt($row['remaining_burst_minutes'] ?? 0) . '</td>'
				. '<td>' . html_escape(self::asString($row['currency'] ?? 'USD')) . ' ' . html_escape($overage) . '</td>'
				. '<td>' . html_escape(strtoupper(self::asString($row['severity'] ?? 'ok'))) . '</td>'
				. '</tr>';
		}

		$html .= '</tbody></table></div>';

		return $html;
	}

	/**
	 * Render the Billing Operator console for statement audit, SHA-256 verification, dry-run rating, and export.
	 *
	 * @param string $csrfField Hidden CSRF input element.
	 * @param list<array<string, mixed>> $statements Finalized statements.
	 * @param list<array<string, mixed>> $contracts Active contracts for dry-run rating.
	 * @param list<string> $messages Informational feedback messages.
	 * @param list<string> $errors Error messages.
	 * @return string Safe HTML markup.
	 */
	public static function renderOperatorConsole(
		string $csrfField,
		array $statements,
		array $contracts,
		array $messages,
		array $errors,
	): string {
		$html = self::renderBanners($messages, $errors);

		$html .= '<form method="post" action="billing_operator.php">' . $csrfField
			. '<input type="hidden" name="action" value="dry_run">'
			. '<h3>Dry-Run Rating &amp; Statement Finalization</h3>'
			. '<label>Contract: <select name="contract_code">';

		foreach ($contracts as $contract) {
			$code  = self::asString($contract['contract_code'] ?? '');
			$html .= '<option value="' . html_escape($code) . '">' . html_escape($code) . '</option>';
		}

		$html .= '</select></label> '
			. '<label>Period Start (UTC YYYY-MM-DD): <input type="text" name="period_start" value="' . html_escape(gmdate('Y-m-01')) . '"></label> '
			. '<label>Period End (UTC YYYY-MM-DD): <input type="text" name="period_end" value="' . html_escape(gmdate('Y-m-d')) . '"></label> '
			. '<button type="submit">Dry-Run Rate</button>'
			. '</form>';

		$html .= '<h3>Finalized Statements &amp; SHA-256 Proof Verification</h3>'
			. '<table class="cactiTable"><thead><tr>'
			. '<th>Statement</th><th>Period (UTC)</th><th>Total</th><th>Samples / Dropped</th><th>SHA-256 Proof</th><th>Actions</th>'
			. '</tr></thead><tbody>';

		if ($statements === []) {
			$html .= '<tr><td colspan="6">No finalized statements recorded yet.</td></tr>';
		}

		foreach ($statements as $stmt) {
			$code    = self::asString($stmt['statement_code'] ?? '');
			$total   = ConnectorPayloadBuilder::formatDecimalMajor(self::asInt($stmt['total_amount_minor'] ?? 0));
			$proof   = self::asString($stmt['proof_sha256'] ?? '');
			$period  = gmdate('Y-m-d', self::asInt($stmt['period_start'] ?? 0)) . ' to ' . gmdate('Y-m-d', self::asInt($stmt['period_end'] ?? 0));

			$html .= '<tr>'
				. '<td>' . html_escape($code) . '</td>'
				. '<td>' . html_escape($period) . '</td>'
				. '<td>' . html_escape(self::asString($stmt['currency'] ?? 'USD')) . ' ' . html_escape($total) . '</td>'
				. '<td>' . self::asInt($stmt['sample_count'] ?? 0) . ' / ' . self::asInt($stmt['dropped_count'] ?? 0) . '</td>'
				. '<td><code>' . html_escape(substr($proof, 0, 16)) . '...</code></td>'
				. '<td>'
				. '<form method="post" action="billing_operator.php" style="display:inline">' . $csrfField
				. '<input type="hidden" name="action" value="verify">'
				. '<input type="hidden" name="statement_code" value="' . html_escape($code) . '">'
				. '<button type="submit">Verify Proof</button></form> '
				. '<form method="post" action="billing_operator.php" style="display:inline">' . $csrfField
				. '<input type="hidden" name="action" value="export_csv">'
				. '<input type="hidden" name="statement_code" value="' . html_escape($code) . '">'
				. '<button type="submit">CSV</button></form> '
				. '<form method="post" action="billing_operator.php" style="display:inline">' . $csrfField
				. '<input type="hidden" name="action" value="export_focus">'
				. '<input type="hidden" name="statement_code" value="' . html_escape($code) . '">'
				. '<button type="submit">FOCUS JSON</button></form>'
				. '</td></tr>';
		}

		$html .= '</tbody></table>';

		return $html;
	}

	/**
	 * Render the Billing Admin console for managing accounts, contracts, circuits, connectors, and settings.
	 *
	 * @param string $csrfField Hidden CSRF input element.
	 * @param Settings $settings Active plugin settings.
	 * @param list<array<string, mixed>> $accounts Billing accounts.
	 * @param list<array<string, mixed>> $contracts Billing contracts.
	 * @param list<string> $messages Informational feedback messages.
	 * @param list<string> $errors Error messages.
	 * @return string Safe HTML markup.
	 */
	public static function renderAdminConsole(
		string $csrfField,
		Settings $settings,
		array $accounts,
		array $contracts,
		array $messages,
		array $errors,
	): string {
		$html = self::renderBanners($messages, $errors);

		$html .= '<h3>Create or Update Billing Account</h3>'
			. '<form method="post" action="billing_admin.php">' . $csrfField
			. '<input type="hidden" name="action" value="save_account">'
			. '<label>Account Code: <input type="text" name="account_code" required></label> '
			. '<label>Name: <input type="text" name="name" required></label> '
			. '<label>Currency: <input type="text" name="currency" value="' . html_escape($settings->defaultCurrency) . '" maxlength="3"></label> '
			. '<label>Connector: <select name="connector_type">';

		foreach (ConnectorPayloadBuilder::CONNECTORS as $conn) {
			$html .= '<option value="' . html_escape($conn) . '">' . html_escape(strtoupper($conn)) . '</option>';
		}

		$html .= '</select></label> '
			. '<label>HTTPS Webhook URL: <input type="text" name="webhook_url" placeholder="https://erp.example.net/hook"></label> '
			. '<button type="submit">Save Account</button></form>';

		$html .= '<h3>Configured Accounts (' . count($accounts) . ') &amp; Contracts (' . count($contracts) . ')</h3>'
			. '<table class="cactiTable"><thead><tr><th>Account Code</th><th>Name</th><th>Currency</th><th>Connector</th><th>Status</th></tr></thead><tbody>';

		foreach ($accounts as $acct) {
			$html .= '<tr>'
				. '<td>' . html_escape(self::asString($acct['account_code'] ?? '')) . '</td>'
				. '<td>' . html_escape(self::asString($acct['name'] ?? '')) . '</td>'
				. '<td>' . html_escape(self::asString($acct['currency'] ?? 'USD')) . '</td>'
				. '<td>' . html_escape(self::asString($acct['connector_type'] ?? 'webhook')) . '</td>'
				. '<td>' . html_escape(self::asString($acct['status'] ?? 'active')) . '</td>'
				. '</tr>';
		}

		if ($accounts === []) {
			$html .= '<tr><td colspan="5">No billing accounts configured.</td></tr>';
		}

		$html .= '</tbody></table>';

		$html .= '<h3>Plugin Settings</h3>'
			. '<form method="post" action="billing_admin.php">' . $csrfField
			. '<input type="hidden" name="action" value="save_config">'
			. '<label>Default Percentile: <input type="text" name="billing_default_percentile" value="' . html_escape((string) $settings->defaultPercentile) . '"></label> '
			. '<label>Drop Rounding: <input type="text" name="billing_default_drop_rounding" value="' . html_escape($settings->defaultDropRounding) . '"></label> '
			. '<label>Gap Policy: <input type="text" name="billing_default_gap_policy" value="' . html_escape($settings->defaultGapPolicy) . '"></label> '
			. '<label>Currency: <input type="text" name="billing_default_currency" value="' . html_escape($settings->defaultCurrency) . '"></label> '
			. '<label>Spike Multiplier: <input type="text" name="billing_spike_multiplier" value="' . html_escape((string) $settings->spikeMultiplier) . '"></label> '
			. '<label>Timeout (s): <input type="text" name="billing_outbound_timeout" value="' . html_escape((string) $settings->outboundTimeout) . '"></label> '
			. '<button type="submit">Save Settings</button></form>';

		return $html;
	}

	/**
	 * Render status and error banners with HTML escaping.
	 *
	 * @param list<string> $messages Success messages.
	 * @param list<string> $errors Error messages.
	 * @return string
	 */
	private static function renderBanners(array $messages, array $errors): string {
		$html = '';

		foreach ($messages as $msg) {
			$html .= '<div class="billing-notice">' . html_escape($msg) . '</div>';
		}

		foreach ($errors as $err) {
			$html .= '<div class="billing-error">' . html_escape($err) . '</div>';
		}

		return $html;
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
