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

namespace Somethingwithproof\Billing\Statement;

use Somethingwithproof\Billing\Ledger\BillingException;
use Somethingwithproof\Billing\Ledger\BillingRepository;
use Somethingwithproof\Billing\Rating\PercentileCalculator;
use Somethingwithproof\Billing\Rating\TariffRater;

/**
 * Orchestrates dry-run contract rating, immutable statement finalization with
 * SHA-256 tamper-evident proof digests, and statement integrity verification.
 */
final class StatementService {
	/**
	 * Construct a StatementService.
	 *
	 * @param BillingRepository $repository Billing storage repository.
	 * @param PercentileCalculator $calculator Nth-percentile calculator.
	 * @param TariffRater $rater Integer minor-unit tariff rater.
	 */
	public function __construct(
		private readonly BillingRepository $repository,
		private readonly PercentileCalculator $calculator = new PercentileCalculator(),
		private readonly TariffRater $rater = new TariffRater(),
	) {
	}

	/**
	 * Perform a non-mutating dry-run rating for a contract over [periodStart, periodEnd).
	 *
	 * @param string $contractCode Unique contract code.
	 * @param int $periodStart Inclusive period start Unix timestamp.
	 * @param int $periodEnd Exclusive period end Unix timestamp.
	 * @param list<array{start: int, end: int}> $maintWindows Optional Cacti maintenance windows.
	 * @return array<string, mixed>
	 */
	public function rateDryRun(
		string $contractCode,
		int $periodStart,
		int $periodEnd,
		array $maintWindows = [],
	): array {
		$contract = $this->repository->findContractByCode($contractCode);

		if ($contract === null) {
			throw new BillingException('Unknown billing contract: ' . $contractCode);
		}

		$contractId = is_numeric($contract['id'] ?? null) ? (int) $contract['id'] : 0;
		$circuits   = $this->repository->listCircuitsForContract($contractId);

		$circuitIds     = [];
		$circuitWindows = [];

		foreach ($circuits as $circuit) {
			$cid              = is_numeric($circuit['id'] ?? null) ? (int) $circuit['id'] : 0;
			$circuitIds[]     = $cid;
			$circuitWindows[$cid] = [
				'active_from'  => is_numeric($circuit['active_from'] ?? null) ? (int) $circuit['active_from'] : 0,
				'active_until' => is_numeric($circuit['active_until'] ?? null) ? (int) $circuit['active_until'] : 0,
			];
		}

		$samplesByCircuit = $this->repository->fetchSamplesByCircuit($circuitIds, $periodStart, $periodEnd);
		$percentile       = is_numeric($contract['percentile'] ?? null) ? (float) $contract['percentile'] : 95.0;
		$directionMode    = is_string($contract['direction_mode'] ?? null) ? $contract['direction_mode'] : 'max_in_out';
		$dropRounding     = is_string($contract['drop_rounding'] ?? null) ? $contract['drop_rounding'] : 'floor';
		$gapPolicy        = is_string($contract['gap_policy'] ?? null) ? $contract['gap_policy'] : 'exclude';
		$metricType       = is_string($contract['metric_type'] ?? null) ? $contract['metric_type'] : 'bandwidth';
		$contractStart    = is_numeric($contract['starts_at'] ?? null) ? (int) $contract['starts_at'] : 0;
		$contractEnd      = is_numeric($contract['ends_at'] ?? null) ? (int) $contract['ends_at'] : 0;

		$pctResult = $this->calculator->compute(
			$samplesByCircuit,
			$periodStart,
			$periodEnd,
			$percentile,
			$directionMode,
			$dropRounding,
			$gapPolicy,
			$maintWindows,
			$circuitWindows,
			$contractStart,
			$contractEnd,
			$metricType
		);

		$rating = $this->rater->rate($contract, $pctResult, $periodStart, $periodEnd);

		return [
			'contract'          => $contract,
			'circuit_count'     => count($circuits),
			'period_start'      => $periodStart,
			'period_end'        => $periodEnd,
			'percentile_result' => $pctResult->toArray(),
			'rating'            => $rating,
		];
	}

	/**
	 * Finalize an immutable billing statement with tariff snapshot and SHA-256 dispute proof.
	 *
	 * @param string $contractCode Unique contract code.
	 * @param int $periodStart Inclusive period start Unix timestamp.
	 * @param int $periodEnd Exclusive period end Unix timestamp.
	 * @param string $finalizedBy Operator username or CLI actor label.
	 * @param list<array{start: int, end: int}> $maintWindows Optional Cacti maintenance windows.
	 * @return array<string, mixed> Persisted statement row.
	 */
	public function finalize(
		string $contractCode,
		int $periodStart,
		int $periodEnd,
		string $finalizedBy = 'system',
		array $maintWindows = [],
	): array {
		$dryRun   = $this->rateDryRun($contractCode, $periodStart, $periodEnd, $maintWindows);
		$contract = is_array($dryRun['contract'] ?? null) ? $dryRun['contract'] : [];
		$pct      = is_array($dryRun['percentile_result'] ?? null) ? $dryRun['percentile_result'] : [];
		$rating   = is_array($dryRun['rating'] ?? null) ? $dryRun['rating'] : [];

		$accountId     = is_numeric($contract['account_id'] ?? null) ? (int) $contract['account_id'] : 0;
		$contractId    = is_numeric($contract['id'] ?? null) ? (int) $contract['id'] : 0;
		$billableUnits = is_numeric($pct['billable_value'] ?? null) ? (int) $pct['billable_value'] : 0;
		$unitDivisor   = is_numeric($contract['unit_divisor'] ?? null) ? max(1, (int) $contract['unit_divisor']) : 1000000;
		$mbpsX1000     = intdiv($billableUnits * 1000 + intdiv($unitDivisor, 2), $unitDivisor);
		$statementCode = sprintf('STMT-%s-%s', strtoupper($contractCode), gmdate('Ym', $periodStart));

		$contractSnapshot = [
			'contract_code'         => $contract['contract_code'] ?? $contractCode,
			'name'                  => $contract['name'] ?? '',
			'metric_type'           => $contract['metric_type'] ?? 'bandwidth',
			'direction_mode'        => $contract['direction_mode'] ?? 'max_in_out',
			'percentile'            => is_numeric($contract['percentile'] ?? null) ? (float) $contract['percentile'] : 95.0,
			'drop_rounding'         => $contract['drop_rounding'] ?? 'floor',
			'gap_policy'            => $contract['gap_policy'] ?? 'exclude',
			'tariff_model'          => $contract['tariff_model'] ?? 'flat',
			'commit_units'          => is_numeric($contract['commit_units'] ?? null) ? (int) $contract['commit_units'] : 0,
			'unit_divisor'          => $unitDivisor,
			'base_fee_minor'        => is_numeric($contract['base_fee_minor'] ?? null) ? (int) $contract['base_fee_minor'] : 0,
			'overage_rate_minor'    => is_numeric($contract['overage_rate_minor'] ?? null) ? (int) $contract['overage_rate_minor'] : 0,
			'tiers_json'            => $contract['tiers_json'] ?? '[]',
			'tb_cap_bytes'          => is_numeric($contract['tb_cap_bytes'] ?? null) ? (int) $contract['tb_cap_bytes'] : 0,
			'tb_overage_rate_minor' => is_numeric($contract['tb_overage_rate_minor'] ?? null) ? (int) $contract['tb_overage_rate_minor'] : 0,
			'currency'              => $rating['currency'] ?? 'USD',
		];

		$snapshot = [
			'contract'      => $contractSnapshot,
			'line_items'    => is_array($rating['line_items'] ?? null) ? $rating['line_items'] : [],
			'dropped_peaks' => is_array($pct['dropped_peaks'] ?? null) ? $pct['dropped_peaks'] : [],
		];

		$header = [
			'statement_code'        => $statementCode,
			'account_id'            => $accountId,
			'contract_id'           => $contractId,
			'period_start'          => $periodStart,
			'period_end'            => $periodEnd,
			'currency'              => is_string($rating['currency'] ?? null) ? $rating['currency'] : 'USD',
			'billable_units'        => $billableUnits,
			'billable_mbps_x1000'   => $mbpsX1000,
			'total_transfer_bytes'  => is_numeric($pct['total_transfer_bytes'] ?? null) ? (int) $pct['total_transfer_bytes'] : 0,
			'base_amount_minor'     => is_numeric($rating['base_amount_minor'] ?? null) ? (int) $rating['base_amount_minor'] : 0,
			'overage_amount_minor'  => is_numeric($rating['overage_amount_minor'] ?? null) ? (int) $rating['overage_amount_minor'] : 0,
			'transfer_amount_minor' => is_numeric($rating['transfer_amount_minor'] ?? null) ? (int) $rating['transfer_amount_minor'] : 0,
			'total_amount_minor'    => is_numeric($rating['total_amount_minor'] ?? null) ? (int) $rating['total_amount_minor'] : 0,
			'sample_count'          => is_numeric($pct['effective_samples'] ?? null) ? (int) $pct['effective_samples'] : 0,
			'dropped_count'         => is_numeric($pct['dropped_count'] ?? null) ? (int) $pct['dropped_count'] : 0,
			'snapshot_json'         => StatementProof::encodeCanonical($snapshot),
			'status'                => 'finalized',
			'finalized_at'          => time(),
			'finalized_by'          => trim($finalizedBy) !== '' ? trim($finalizedBy) : 'system',
		];

		$header['proof_sha256'] = StatementProof::computeDigest($header, $snapshot);
		$id                     = $this->repository->insertStatement($header);
		$stored                 = $this->repository->findStatementById($id);

		return $stored ?? array_merge(['id' => $id], $header);
	}

	/**
	 * Verify a finalized statement's cryptographic SHA-256 integrity by statement code.
	 *
	 * @param string $statementCode Statement code.
	 * @return array{valid: bool, statement_code: string, expected_digest: string, stored_digest: string, reason: string}
	 */
	public function verifyIntegrityByCode(string $statementCode): array {
		$row = $this->repository->findStatementByCode($statementCode);

		if ($row === null) {
			return [
				'valid'           => false,
				'statement_code'  => $statementCode,
				'expected_digest' => '',
				'stored_digest'   => '',
				'reason'          => 'Statement not found: ' . $statementCode,
			];
		}

		return $this->verifyIntegrity($row);
	}

	/**
	 * Verify a finalized statement row's cryptographic SHA-256 tamper-evident proof.
	 *
	 * @param array<string, mixed> $statementRow Statement row.
	 * @return array{valid: bool, statement_code: string, expected_digest: string, stored_digest: string, reason: string}
	 */
	public function verifyIntegrity(array $statementRow): array {
		$result = StatementProof::verify($statementRow);

		return [
			'valid'           => $result['valid'],
			'statement_code'  => is_string($statementRow['statement_code'] ?? null) ? $statementRow['statement_code'] : '',
			'expected_digest' => $result['expected_digest'],
			'stored_digest'   => $result['stored_digest'],
			'reason'          => $result['reason'],
		];
	}
}
