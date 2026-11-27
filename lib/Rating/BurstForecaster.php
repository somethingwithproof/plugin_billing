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

namespace Somethingwithproof\Billing\Rating;

/**
 * Mid-cycle burst budget forecaster projecting 95th-percentile overage risk by
 * tracking consumed vs. remaining allowable peak slots (for example, 432
 * 5-minute intervals = 36 hours in a 30-day billing cycle at 95th percentile).
 */
final class BurstForecaster {
	/**
	 * Construct a BurstForecaster with configurable alert thresholds.
	 *
	 * @param PercentileCalculator $calculator Percentile engine.
	 * @param TariffRater $rater Minor-unit tariff rater.
	 * @param int $warningPct Burst budget consumption percentage triggering warning severity.
	 * @param int $criticalPct Burst budget consumption percentage triggering critical severity.
	 */
	public function __construct(
		private readonly PercentileCalculator $calculator = new PercentileCalculator(),
		private readonly TariffRater $rater = new TariffRater(),
		private readonly int $warningPct = 70,
		private readonly int $criticalPct = 90,
	) {
	}

	/**
	 * Produce a mid-cycle burst budget and overage projection for a contract.
	 *
	 * @param array<string, mixed> $contract Contract row.
	 * @param array<int, list<array{bucket_ts: int, in_bps: int, out_bps: int, resource_units?: int, quality_flag?: string}>> $circuitSamples Samples grouped by circuit ID.
	 * @param int $periodStart Billing cycle start Unix timestamp.
	 * @param int $periodEnd Billing cycle end Unix timestamp.
	 * @param int $asOfTs Evaluation timestamp within the cycle.
	 * @param list<array{start: int, end: int}> $maintWindows Optional Cacti maintenance windows.
	 * @param int $stepSeconds Bucket width in seconds (default 300).
	 * @return array{
	 *   contract_code: string,
	 *   currency: string,
	 *   commit_units: int,
	 *   total_period_slots: int,
	 *   burst_budget_slots: int,
	 *   burst_budget_minutes: int,
	 *   consumed_burst_slots: int,
	 *   remaining_burst_slots: int,
	 *   remaining_burst_minutes: int,
	 *   budget_consumed_pct: float,
	 *   over_commit: bool,
	 *   current_percentile_units: int,
	 *   projected_overage_minor: int,
	 *   severity: string
	 * }
	 */
	public function forecast(
		array $contract,
		array $circuitSamples,
		int $periodStart,
		int $periodEnd,
		int $asOfTs,
		array $maintWindows = [],
		int $stepSeconds = PercentileCalculator::STEP_SECONDS,
	): array {
		$percentile    = is_numeric($contract['percentile'] ?? null) ? (float) $contract['percentile'] : 95.0;
		$dropRounding  = is_string($contract['drop_rounding'] ?? null) ? $contract['drop_rounding'] : 'floor';
		$directionMode = is_string($contract['direction_mode'] ?? null) ? $contract['direction_mode'] : 'max_in_out';
		$metricType    = is_string($contract['metric_type'] ?? null) ? $contract['metric_type'] : 'bandwidth';
		$commitUnits   = is_numeric($contract['commit_units'] ?? null) ? max(0, (int) $contract['commit_units']) : 0;
		$currency      = is_string($contract['currency'] ?? null) ? strtoupper($contract['currency']) : 'USD';
		$contractCode  = is_string($contract['contract_code'] ?? null) ? $contract['contract_code'] : '';

		$totalPeriodSlots = max(1, intdiv(max(0, $periodEnd - $periodStart), $stepSeconds));
		$burstBudgetSlots = PercentileCalculator::calculateDropCount($totalPeriodSlots, $percentile, $dropRounding);

		// Aggregate aligned samples up to $asOfTs to count slots above commit.
		$effectiveEnd = min($periodEnd, max($periodStart + $stepSeconds, $asOfTs));
		$aggregated   = [];

		foreach ($circuitSamples as $samples) {
			foreach ($samples as $sample) {
				$ts = intdiv($sample['bucket_ts'], $stepSeconds) * $stepSeconds;

				if ($ts < $periodStart || $ts >= $effectiveEnd) {
					continue;
				}

				if (!isset($aggregated[$ts])) {
					$aggregated[$ts] = ['in' => 0, 'out' => 0, 'res' => 0];
				}

				$aggregated[$ts]['in']  += max(0, $sample['in_bps']);
				$aggregated[$ts]['out'] += max(0, $sample['out_bps']);
				$aggregated[$ts]['res'] += max(0, $sample['resource_units'] ?? 0);
			}
		}

		$consumedBurstSlots = 0;

		foreach ($aggregated as $bucket) {
			$val = $metricType === 'bandwidth'
				? match ($directionMode) {
					'sum_in_out' => $bucket['in'] + $bucket['out'],
					'in_only'    => $bucket['in'],
					'out_only'   => $bucket['out'],
					default      => max($bucket['in'], $bucket['out']),
				}
				: $bucket['res'];

			if ($val > $commitUnits) {
				$consumedBurstSlots++;
			}
		}

		$remainingSlots = max(0, $burstBudgetSlots - $consumedBurstSlots);
		$consumedPct    = $burstBudgetSlots > 0
			? round(($consumedBurstSlots / $burstBudgetSlots) * 100.0, 2)
			: ($consumedBurstSlots > 0 ? 100.0 : 0.0);

		// Evaluate current Nth-percentile against the full billing period (zero-filling future slots)
		// so projected_overage reflects guaranteed overage already locked in once burst budget is exceeded.
		$currentResult = $this->calculator->compute(
			$circuitSamples,
			$periodStart,
			$periodEnd,
			$percentile,
			$directionMode,
			$dropRounding,
			'zero_fill',
			$maintWindows,
			[],
			0,
			0,
			$metricType,
			$stepSeconds
		);

		$rating      = $this->rater->rate($contract, $currentResult, $periodStart, $periodEnd);
		$overCommit  = $consumedBurstSlots > $burstBudgetSlots || $currentResult->billableValue > $commitUnits;
		$slotMinutes = intdiv($stepSeconds, 60);

		$severity = 'ok';

		if ($overCommit) {
			$severity = 'breached';
		} elseif ($consumedPct >= (float) $this->criticalPct) {
			$severity = 'critical';
		} elseif ($consumedPct >= (float) $this->warningPct) {
			$severity = 'warning';
		}

		return [
			'contract_code'            => $contractCode,
			'currency'                 => $currency,
			'commit_units'             => $commitUnits,
			'total_period_slots'       => $totalPeriodSlots,
			'burst_budget_slots'       => $burstBudgetSlots,
			'burst_budget_minutes'     => $burstBudgetSlots * $slotMinutes,
			'consumed_burst_slots'     => $consumedBurstSlots,
			'remaining_burst_slots'    => $remainingSlots,
			'remaining_burst_minutes'  => $remainingSlots * $slotMinutes,
			'budget_consumed_pct'      => $consumedPct,
			'over_commit'              => $overCommit,
			'current_percentile_units' => $currentResult->billableValue,
			'projected_overage_minor'  => $rating['overage_amount_minor'],
			'severity'                 => $severity,
		];
	}
}
