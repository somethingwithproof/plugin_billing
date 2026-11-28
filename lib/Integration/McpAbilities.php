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
use Somethingwithproof\Billing\Pages;
use Somethingwithproof\Billing\Rating\BurstForecaster;
use Somethingwithproof\Billing\Statement\StatementService;

/**
 * Registers read-only billing abilities via the abilities_register hook:
 * billing-list-accounts, billing-get-forecast, and billing-verify-statement.
 */
final class McpAbilities {
	/**
	 * Safety annotations applied to all read-only billing abilities.
	 */
	private const ANNOTATIONS = [
		'readOnly'    => true,
		'destructive' => false,
		'idempotent'  => true,
	];

	/**
	 * Register billing-list-accounts, billing-get-forecast, and billing-verify-statement.
	 *
	 * @param object $registrar Duck-typed registrar exposing register(string, array).
	 * @param callable(): BillingRepository $repoFactory Lazy repository provider.
	 * @param callable(): BurstForecaster $forecasterFactory Lazy burst forecaster provider.
	 * @param callable(): StatementService $statementFactory Lazy statement service provider.
	 * @param callable(int): bool $permissionCheck Cacti user ID permission callback.
	 * @return void
	 */
	public static function register(
		object $registrar,
		callable $repoFactory,
		callable $forecasterFactory,
		callable $statementFactory,
		callable $permissionCheck,
	): void {
		if (!method_exists($registrar, 'register')) {
			return;
		}

		if (method_exists($registrar, 'registerCategory')) {
			try {
				$registrar->registerCategory('billing', 'Billing');
			} catch (\LogicException) {
				// Category may already have been registered by another plugin.
			}
		}

		$registrar->register('billing-list-accounts', [
			'label'        => 'List billing accounts',
			'description'  => 'Return configured billing accounts (codes, names, currencies, connector types, statuses) without secrets or webhook URLs.',
			'category'     => 'billing',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'status' => ['type' => 'string', 'maxLength' => 16],
				],
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'accounts' => [
						'type'     => 'array',
						'maxItems' => 1000,
						'items'    => [
							'type'       => 'object',
							'properties' => [
								'account_code'   => ['type' => 'string', 'maxLength' => 64],
								'name'           => ['type' => 'string', 'maxLength' => 191],
								'currency'       => ['type' => 'string', 'maxLength' => 3],
								'connector_type' => ['type' => 'string', 'maxLength' => 32],
								'status'         => ['type' => 'string', 'maxLength' => 16],
							],
							'required'             => ['account_code', 'name', 'currency', 'connector_type', 'status'],
							'additionalProperties' => false,
						],
					],
				],
				'required'             => ['accounts'],
				'additionalProperties' => false,
			],
			'annotations' => self::ANNOTATIONS,
			'permission'  => $permissionCheck,
			'execute'     => static function (int $userId, \stdClass $args) use ($repoFactory): array {
				$status = isset($args->status) && is_string($args->status) && trim($args->status) !== ''
					? trim($args->status)
					: null;
				$rows = array_slice($repoFactory()->listAccounts($status), 0, 1000);
				$out  = [];

				foreach ($rows as $row) {
					$out[] = [
						'account_code'   => is_scalar($row['account_code'] ?? null) ? (string) $row['account_code'] : '',
						'name'           => is_scalar($row['name'] ?? null) ? (string) $row['name'] : '',
						'currency'       => is_scalar($row['currency'] ?? null) ? (string) $row['currency'] : 'USD',
						'connector_type' => is_scalar($row['connector_type'] ?? null) ? (string) $row['connector_type'] : 'webhook',
						'status'         => is_scalar($row['status'] ?? null) ? (string) $row['status'] : 'active',
					];
				}

				return ['accounts' => $out];
			},
		]);

		$registrar->register('billing-get-forecast', [
			'label'        => 'Get mid-cycle burst forecast',
			'description'  => 'Project consumed vs. remaining 95th-percentile burst slots and projected overage for a billing contract.',
			'category'     => 'billing',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'contract_code' => ['type' => 'string', 'maxLength' => 64],
					'period_start'  => ['type' => 'integer', 'minimum' => 0],
					'period_end'    => ['type' => 'integer', 'minimum' => 1],
					'as_of'         => ['type' => 'integer', 'minimum' => 0],
				],
				'required'             => ['contract_code', 'period_start', 'period_end'],
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'found'                    => ['type' => 'boolean'],
					'contract_code'            => ['type' => 'string', 'maxLength' => 64],
					'currency'                 => ['type' => 'string', 'maxLength' => 3],
					'commit_units'             => ['type' => 'integer', 'minimum' => 0],
					'total_period_slots'       => ['type' => 'integer', 'minimum' => 0],
					'burst_budget_slots'       => ['type' => 'integer', 'minimum' => 0],
					'burst_budget_minutes'     => ['type' => 'integer', 'minimum' => 0],
					'consumed_burst_slots'     => ['type' => 'integer', 'minimum' => 0],
					'remaining_burst_slots'    => ['type' => 'integer', 'minimum' => 0],
					'remaining_burst_minutes'  => ['type' => 'integer', 'minimum' => 0],
					'budget_consumed_pct'      => ['type' => 'number', 'minimum' => 0],
					'over_commit'              => ['type' => 'boolean'],
					'current_percentile_units' => ['type' => 'integer', 'minimum' => 0],
					'projected_overage_minor'  => ['type' => 'integer', 'minimum' => 0],
					'severity'                 => ['type' => 'string', 'maxLength' => 16],
				],
				'required'             => ['found', 'contract_code'],
				'additionalProperties' => false,
			],
			'annotations' => self::ANNOTATIONS,
			'permission'  => $permissionCheck,
			'execute'     => static function (int $userId, \stdClass $args) use ($repoFactory, $forecasterFactory): array {
				$code     = isset($args->contract_code) && is_string($args->contract_code) ? trim($args->contract_code) : '';
				$start    = isset($args->period_start) && is_numeric($args->period_start) ? (int) $args->period_start : 0;
				$end      = isset($args->period_end) && is_numeric($args->period_end) ? (int) $args->period_end : 0;
				$asOf     = isset($args->as_of) && is_numeric($args->as_of) ? (int) $args->as_of : min($end, time());
				$repo     = $repoFactory();
				$contract = $repo->findContractByCode($code);

				if ($contract === null) {
					return ['found' => false, 'contract_code' => $code];
				}

				$contractId = is_numeric($contract['id'] ?? null) ? (int) $contract['id'] : 0;
				$circuits   = $repo->listCircuitsForContract($contractId);
				$circuitIds = array_map(static fn (array $c): int => is_numeric($c['id'] ?? null) ? (int) $c['id'] : 0, $circuits);
				$samples    = $repo->fetchSamplesByCircuit($circuitIds, $start, $end);
				$forecast   = $forecasterFactory()->forecast($contract, $samples, $start, $end, $asOf);

				return array_merge(['found' => true], $forecast);
			},
		]);

		$registrar->register('billing-verify-statement', [
			'label'        => 'Verify statement SHA-256 integrity',
			'description'  => 'Recompute and verify the canonical SHA-256 tamper-evident proof digest for a finalized billing statement.',
			'category'     => 'billing',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'statement_code' => ['type' => 'string', 'maxLength' => 96],
				],
				'required'             => ['statement_code'],
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'valid'           => ['type' => 'boolean'],
					'statement_code'  => ['type' => 'string', 'maxLength' => 96],
					'expected_digest' => ['type' => 'string', 'maxLength' => 64],
					'stored_digest'   => ['type' => 'string', 'maxLength' => 64],
					'reason'          => ['type' => 'string', 'maxLength' => 256],
				],
				'required'             => ['valid', 'statement_code', 'expected_digest', 'stored_digest', 'reason'],
				'additionalProperties' => false,
			],
			'annotations' => self::ANNOTATIONS,
			'permission'  => $permissionCheck,
			'execute'     => static function (int $userId, \stdClass $args) use ($statementFactory): array {
				$code = isset($args->statement_code) && is_string($args->statement_code) ? trim($args->statement_code) : '';

				return $statementFactory()->verifyIntegrityByCode($code);
			},
		]);
	}

	/**
	 * Database-backed Cacti realm permission check for billing abilities (Billing View or higher).
	 * Queries user_auth_realm and user_auth_group_realm for $userId without touching $_SESSION.
	 *
	 * @param int $userId Cacti user_auth.id.
	 * @return bool
	 */
	public static function userHasViewRealm(int $userId): bool {
		if ($userId <= 0 || !function_exists('db_fetch_cell_prepared')) {
			return false;
		}

		$realmIds = [];

		foreach ([Pages::VIEW, Pages::OPERATOR, Pages::ADMIN] as $file) {
			/** @var mixed $realmId */
			$realmId = db_fetch_cell_prepared(
				'SELECT id FROM plugin_realms WHERE plugin = ? AND file = ?',
				['billing', $file]
			);

			if (is_numeric($realmId) && (int) $realmId > 0) {
				$realmIds[] = (int) $realmId + 100;
			}
		}

		if ($realmIds === []) {
			return false;
		}

		$placeholders = implode(', ', array_fill(0, count($realmIds), '?'));
		$direct       = db_fetch_cell_prepared(
			"SELECT 1 FROM user_auth_realm WHERE user_id = ? AND realm_id IN ($placeholders) LIMIT 1",
			array_merge([$userId], $realmIds)
		);

		if (is_numeric($direct) && (int) $direct === 1) {
			return true;
		}

		$group = db_fetch_cell_prepared(
			"SELECT 1 FROM user_auth_group_realm AS uagr
			 INNER JOIN user_auth_group_members AS uagm ON uagm.group_id = uagr.group_id
			 INNER JOIN user_auth_group AS uag ON uag.id = uagr.group_id
			 WHERE uagm.user_id = ? AND uag.enabled = 'on' AND uagr.realm_id IN ($placeholders)
			 LIMIT 1",
			array_merge([$userId], $realmIds)
		);

		return is_numeric($group) && (int) $group === 1;
	}
}
