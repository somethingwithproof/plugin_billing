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

use Somethingwithproof\Billing\Ledger\BillingException;

/**
 * Builds structured ERP billing payloads for WHMCS, HostBill, Blesta,
 * UISP/UCRM, Kill Bill, Stripe Billing, generic signed webhooks, and
 * FOCUS 1.0-aligned JSON usage exports.
 */
final class ConnectorPayloadBuilder {
	/**
	 * Supported ERP connector adapter identifiers.
	 */
	public const CONNECTORS = ['webhook', 'whmcs', 'hostbill', 'blesta', 'uisp', 'killbill', 'stripe'];

	/**
	 * Build a connector-specific payload for a finalized billing statement.
	 *
	 * @param string $connectorType Adapter slug (whmcs, hostbill, blesta, uisp, killbill, stripe, webhook).
	 * @param array<string, mixed> $account Billing account row.
	 * @param array<string, mixed> $statement Finalized statement row.
	 * @return array<string, mixed>
	 */
	public function build(string $connectorType, array $account, array $statement): array {
		$type = strtolower(trim($connectorType));

		if (!in_array($type, self::CONNECTORS, true)) {
			throw new BillingException('Unsupported ERP connector type: ' . $connectorType);
		}

		$snapshot  = self::decodeSnapshot($statement);
		$lineItems = is_array($snapshot['line_items'] ?? null) ? $snapshot['line_items'] : [];
		$currency  = strtoupper(self::asString($statement['currency'] ?? 'USD'));
		$totalMin  = self::asInt($statement['total_amount_minor'] ?? 0);
		$extRef    = self::asString($account['external_ref'] ?? $account['account_code'] ?? '');
		$stmtCode  = self::asString($statement['statement_code'] ?? '');
		$proof     = self::asString($statement['proof_sha256'] ?? '');
		$startIso  = gmdate('Y-m-d\TH:i:s\Z', self::asInt($statement['period_start'] ?? 0));
		$endIso    = gmdate('Y-m-d\TH:i:s\Z', self::asInt($statement['period_end'] ?? 0));

		return match ($type) {
			'whmcs' => [
				'action'       => 'CreateInvoice',
				'userid'       => $extRef,
				'currency'     => $currency,
				'date'         => gmdate('Y-m-d', self::asInt($statement['finalized_at'] ?? time())),
				'notes'        => sprintf('Statement %s | Proof SHA-256: %s', $stmtCode, $proof),
				'items'        => array_map(
					static fn (array $item): array => [
						'description' => self::asString($item['description'] ?? 'Transit usage'),
						'amount'      => self::formatDecimalMajor(self::asInt($item['amount_minor'] ?? 0)),
						'taxed'       => false,
					],
					self::filterArrayItems($lineItems)
				),
				'total_minor'  => $totalMin,
				'proof_sha256' => $proof,
			],
			'hostbill' => [
				'call'         => 'addInvoice',
				'client_id'    => $extRef,
				'currency'     => $currency,
				'external_id'  => $stmtCode,
				'period_start' => $startIso,
				'period_end'   => $endIso,
				'items'        => array_map(
					static fn (array $item): array => [
						'description' => self::asString($item['description'] ?? 'Metered usage'),
						'amount'      => self::formatDecimalMajor(self::asInt($item['amount_minor'] ?? 0)),
						'qty'         => 1,
					],
					self::filterArrayItems($lineItems)
				),
				'proof_sha256' => $proof,
			],
			'blesta' => [
				'model'        => 'Invoices',
				'method'       => 'add',
				'vars'         => [
					'client_id' => $extRef,
					'currency'  => $currency,
					'note_public' => sprintf('Statement %s (%s to %s)', $stmtCode, $startIso, $endIso),
					'lines'     => array_map(
						static fn (array $item): array => [
							'description' => self::asString($item['description'] ?? 'Usage charge'),
							'qty'         => 1,
							'amount'      => self::formatDecimalMajor(self::asInt($item['amount_minor'] ?? 0)),
						],
						self::filterArrayItems($lineItems)
					),
				],
				'proof_sha256' => $proof,
			],
			'uisp' => [
				'clientId'     => $extRef,
				'currencyCode' => $currency,
				'invoiceNumber' => $stmtCode,
				'items'        => array_map(
					static fn (array $item): array => [
						'label'    => self::asString($item['description'] ?? 'Burstable transit'),
						'quantity' => 1,
						'price'    => self::formatDecimalMajor(self::asInt($item['amount_minor'] ?? 0)),
						'unit'     => 'period',
					],
					self::filterArrayItems($lineItems)
				),
				'attributes'   => [
					'proof_sha256' => $proof,
					'period_start' => $startIso,
					'period_end'   => $endIso,
				],
			],
			'killbill' => [
				'accountExternalKey' => $extRef,
				'currency'           => $currency,
				'trackingId'         => $stmtCode,
				'unitUsageRecords'   => [
					[
						'unitType'     => 'mbps_95th',
						'usageRecords' => [
							[
								'recordDate' => gmdate('Y-m-d', self::asInt($statement['period_end'] ?? time())),
								'amount'     => self::asInt($statement['billable_mbps_x1000'] ?? 0),
							],
						],
					],
				],
				'charges' => array_map(
					static fn (array $item): array => [
						'description'  => self::asString($item['description'] ?? 'Usage charge'),
						'amount_minor' => self::asInt($item['amount_minor'] ?? 0),
						'currency'     => $currency,
					],
					self::filterArrayItems($lineItems)
				),
				'proof_sha256' => $proof,
			],
			'stripe' => [
				'object'       => 'billing.invoice_bundle',
				'customer'     => $extRef,
				'currency'     => strtolower($currency),
				'idempotency'  => $stmtCode,
				'invoice_items' => array_map(
					static fn (array $item): array => [
						'amount'      => self::asInt($item['amount_minor'] ?? 0),
						'currency'    => strtolower($currency),
						'description' => self::asString($item['description'] ?? 'Usage charge'),
						'metadata'    => [
							'statement_code' => $stmtCode,
							'item_code'      => self::asString($item['code'] ?? 'usage'),
						],
					],
					self::filterArrayItems($lineItems)
				),
				'metadata' => [
					'statement_code' => $stmtCode,
					'period_start'   => $startIso,
					'period_end'     => $endIso,
					'proof_sha256'   => $proof,
				],
			],
			default => [
				'event'        => 'billing.statement.finalized',
				'statement_code' => $stmtCode,
				'account_code' => self::asString($account['account_code'] ?? ''),
				'external_ref' => $extRef,
				'currency'     => $currency,
				'period_start' => $startIso,
				'period_end'   => $endIso,
				'billable_units' => self::asInt($statement['billable_units'] ?? 0),
				'billable_mbps_x1000' => self::asInt($statement['billable_mbps_x1000'] ?? 0),
				'total_amount_minor' => $totalMin,
				'line_items'   => self::filterArrayItems($lineItems),
				'proof_sha256' => $proof,
			],
		};
	}

	/**
	 * Build a FinOps FOCUS 1.0-aligned JSON usage export record for a finalized statement.
	 *
	 * @param array<string, mixed> $account Billing account row.
	 * @param array<string, mixed> $statement Finalized statement row.
	 * @return list<array<string, mixed>> FOCUS 1.0 line records.
	 */
	public function buildFocusExport(array $account, array $statement): array {
		$snapshot  = self::decodeSnapshot($statement);
		$contract  = is_array($snapshot['contract'] ?? null) ? $snapshot['contract'] : [];
		$lineItems = self::filterArrayItems(is_array($snapshot['line_items'] ?? null) ? $snapshot['line_items'] : []);

		$accountCode = self::asString($account['account_code'] ?? '');
		$accountName = self::asString($account['name'] ?? $accountCode);
		$currency    = strtoupper(self::asString($statement['currency'] ?? 'USD'));
		$startIso    = gmdate('Y-m-d\TH:i:s\Z', self::asInt($statement['period_start'] ?? 0));
		$endIso      = gmdate('Y-m-d\TH:i:s\Z', self::asInt($statement['period_end'] ?? 0));
		$metricType  = self::asString($contract['metric_type'] ?? 'bandwidth');
		$contractCd  = self::asString($contract['contract_code'] ?? '');
		$stmtCode    = self::asString($statement['statement_code'] ?? '');
		$proof       = self::asString($statement['proof_sha256'] ?? '');

		$serviceCategory = match ($metricType) {
			'power'   => 'Colocation Power',
			'storage' => 'Storage',
			'gpu'     => 'AI and Machine Learning',
			default   => 'Networking',
		};

		$consumedUnit = match ($metricType) {
			'power'   => 'kWh',
			'storage' => 'TiB-Hours',
			'gpu'     => 'GPU-Hours',
			default   => 'Mbps-95th',
		};

		$records = [];

		foreach ($lineItems as $item) {
			$minor   = self::asInt($item['amount_minor'] ?? 0);
			$qtyRaw  = self::asInt($item['quantity_units'] ?? 0);
			$divisor = max(1, self::asInt($item['unit_divisor'] ?? 1000000));

			$records[] = [
				'FocusVersion'       => '1.0',
				'BillingAccountId'   => $accountCode,
				'BillingAccountName' => $accountName,
				'BillingCurrency'    => $currency,
				'BillingPeriodStart' => $startIso,
				'BillingPeriodEnd'   => $endIso,
				'ChargeCategory'     => 'Usage',
				'ChargeDescription'  => self::asString($item['description'] ?? 'Usage charge'),
				'ConsumedQuantity'   => round($qtyRaw / $divisor, 6),
				'ConsumedUnit'       => self::asString($item['code'] ?? '') === 'transfer_overage' ? 'TB' : $consumedUnit,
				'BilledCost'         => self::formatDecimalMajor($minor),
				'ListCost'           => self::formatDecimalMajor($minor),
				'ResourceId'         => $contractCd,
				'ServiceCategory'    => $serviceCategory,
				'ServiceName'        => 'Cacti Metered Billing',
				'x_StatementCode'    => $stmtCode,
				'x_BilledCostMinor'  => $minor,
				'x_ProofSha256'      => $proof,
			];
		}

		return $records;
	}

	/**
	 * Format integer minor units (100 minor units = 1 major unit) as an exact decimal string
	 * without floating-point arithmetic.
	 *
	 * @param int $minor Minor-unit integer amount.
	 * @return string Exact decimal string (for example, 12345 -> "123.45").
	 */
	public static function formatDecimalMajor(int $minor): string {
		$sign  = $minor < 0 ? '-' : '';
		$abs   = abs($minor);
		$whole = intdiv($abs, 100);
		$cents = $abs % 100;

		return sprintf('%s%d.%02d', $sign, $whole, $cents);
	}

	/**
	 * Decode a statement's snapshot_json safely.
	 *
	 * @param array<string, mixed> $statement Statement row.
	 * @return array<string, mixed>
	 */
	private static function decodeSnapshot(array $statement): array {
		$json = self::asString($statement['snapshot_json'] ?? '');
		$data = json_decode($json, true);

		return is_array($data) ? $data : [];
	}

	/**
	 * Filter raw list items down to associative arrays.
	 *
	 * @param array<mixed> $items Raw list.
	 * @return list<array<string, mixed>>
	 */
	private static function filterArrayItems(array $items): array {
		$out = [];

		foreach ($items as $item) {
			if (!is_array($item)) {
				continue;
			}

			$norm = [];

			foreach ($item as $k => $v) {
				$norm[(string) $k] = $v;
			}

			$out[] = $norm;
		}

		return $out;
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
