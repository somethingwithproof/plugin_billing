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

use Somethingwithproof\Billing\Ledger\BillingException;

/**
 * Carrier-grade Nth-percentile calculator supporting multi-circuit / LAG
 * timestamp-aligned aggregation, direction modes, drop-count rounding,
 * gap policies, Cacti maintenance window exclusions, and mid-month pro-rata
 * active intervals. Uses flat parallel integer bucket arrays and O(N) 3-way
 * Quickselect for linear-time, low-allocation rating.
 */
final class PercentileCalculator {
	/**
	 * Default billing bucket width in seconds (5 minutes).
	 */
	public const STEP_SECONDS = 300;

	/**
	 * Supported traffic direction modes.
	 */
	public const DIRECTION_MODES = ['max_in_out', 'sum_in_out', 'separate_in_out', 'in_only', 'out_only'];

	/**
	 * Supported drop-count rounding modes.
	 */
	public const DROP_ROUNDINGS = ['ceil', 'floor', 'nearest'];

	/**
	 * Supported missing-bucket gap policies.
	 */
	public const GAP_POLICIES = ['exclude', 'zero_fill', 'carry_forward'];

	/**
	 * Compute exact Nth-percentile using O(N) 3-way Quickselect + O(k log k) peak extraction.
	 *
	 * Multi-circuit / LAG aggregation aligns 5-minute timestamps across all member
	 * circuits and sums aligned buckets BEFORE computing the Nth percentile.
	 *
	 * @param array<int, list<array{bucket_ts: int, in_bps: int, out_bps: int, resource_units?: int, quality_flag?: string}>> $circuitSamples Samples grouped by circuit ID.
	 * @param int $periodStart Inclusive period start Unix timestamp.
	 * @param int $periodEnd Exclusive period end Unix timestamp.
	 * @param float $percentile Target percentile in (0.0, 100.0] (default 95.0).
	 * @param string $directionMode Direction mode (max_in_out, sum_in_out, separate_in_out, in_only, out_only).
	 * @param string $dropRounding Drop-count rounding (floor, ceil, nearest).
	 * @param string $gapPolicy Gap policy (exclude, zero_fill, carry_forward).
	 * @param list<array{start: int, end: int}> $maintWindows Cacti maintenance exclusion intervals [start, end).
	 * @param array<int, array{active_from: int, active_until: int}> $circuitWindows Optional per-circuit pro-rata active windows.
	 * @param int $contractStart Optional contract start timestamp for mid-month pro-rata.
	 * @param int $contractEnd Optional contract end timestamp for mid-month pro-rata (0 = open-ended).
	 * @param string $metricType Metric type (bandwidth, power, storage, gpu).
	 * @param int $stepSeconds Bucket step in seconds (default 300).
	 * @return PercentileResult
	 */
	public function compute(
		array $circuitSamples,
		int $periodStart,
		int $periodEnd,
		float $percentile = 95.0,
		string $directionMode = 'max_in_out',
		string $dropRounding = 'floor',
		string $gapPolicy = 'exclude',
		array $maintWindows = [],
		array $circuitWindows = [],
		int $contractStart = 0,
		int $contractEnd = 0,
		string $metricType = 'bandwidth',
		int $stepSeconds = self::STEP_SECONDS,
	): PercentileResult {
		return $this->evaluate(
			$circuitSamples,
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
			$metricType,
			$stepSeconds,
			false
		);
	}

	/**
	 * Reference O(N log N) full-sort implementation used to cross-check Quickselect
	 * equivalence in tests and performance benchmarks.
	 *
	 * @param array<int, list<array{bucket_ts: int, in_bps: int, out_bps: int, resource_units?: int, quality_flag?: string}>> $circuitSamples Samples grouped by circuit ID.
	 * @param int $periodStart Inclusive period start Unix timestamp.
	 * @param int $periodEnd Exclusive period end Unix timestamp.
	 * @param float $percentile Target percentile in (0.0, 100.0] (default 95.0).
	 * @param string $directionMode Direction mode.
	 * @param string $dropRounding Drop-count rounding.
	 * @param string $gapPolicy Gap policy.
	 * @param list<array{start: int, end: int}> $maintWindows Cacti maintenance exclusion intervals [start, end).
	 * @param array<int, array{active_from: int, active_until: int}> $circuitWindows Optional per-circuit pro-rata active windows.
	 * @param int $contractStart Optional contract start timestamp.
	 * @param int $contractEnd Optional contract end timestamp.
	 * @param string $metricType Metric type.
	 * @param int $stepSeconds Bucket step in seconds.
	 * @return PercentileResult
	 */
	public function computeWithFullSort(
		array $circuitSamples,
		int $periodStart,
		int $periodEnd,
		float $percentile = 95.0,
		string $directionMode = 'max_in_out',
		string $dropRounding = 'floor',
		string $gapPolicy = 'exclude',
		array $maintWindows = [],
		array $circuitWindows = [],
		int $contractStart = 0,
		int $contractEnd = 0,
		string $metricType = 'bandwidth',
		int $stepSeconds = self::STEP_SECONDS,
	): PercentileResult {
		return $this->evaluate(
			$circuitSamples,
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
			$metricType,
			$stepSeconds,
			true
		);
	}

	/**
	 * Calculate the exact integer drop count for $sampleCount samples at $percentile
	 * using basis-point arithmetic to avoid IEEE-754 representation drift.
	 *
	 * @param int $sampleCount Number of effective samples.
	 * @param float $percentile Percentile in (0.0, 100.0].
	 * @param string $dropRounding Rounding mode (floor, ceil, nearest).
	 * @return int Number of top peak intervals to drop.
	 */
	public static function calculateDropCount(int $sampleCount, float $percentile, string $dropRounding): int {
		if ($sampleCount <= 1) {
			return 0;
		}

		$pBps    = (int) round($percentile * 100.0);
		$tailBps = max(0, 10000 - $pBps);
		$num     = $sampleCount * $tailBps;

		$dropped = match ($dropRounding) {
			'ceil'    => intdiv($num + 9999, 10000),
			'nearest' => intdiv($num + 5000, 10000),
			default   => intdiv($num, 10000),
		};

		return min($sampleCount - 1, max(0, $dropped));
	}

	/**
	 * Core evaluation pipeline using flat parallel integer arrays to eliminate
	 * per-bucket HashTable allocations across 8,640+ monthly intervals.
	 *
	 * @param array<int, list<array{bucket_ts: int, in_bps: int, out_bps: int, resource_units?: int, quality_flag?: string}>> $circuitSamples Samples grouped by circuit ID.
	 * @param int $periodStart Inclusive period start timestamp.
	 * @param int $periodEnd Exclusive period end timestamp.
	 * @param float $percentile Target percentile.
	 * @param string $directionMode Direction mode.
	 * @param string $dropRounding Drop-count rounding.
	 * @param string $gapPolicy Gap policy.
	 * @param list<array{start: int, end: int}> $maintWindows Maintenance exclusion windows.
	 * @param array<int, array{active_from: int, active_until: int}> $circuitWindows Per-circuit active windows.
	 * @param int $contractStart Contract active start.
	 * @param int $contractEnd Contract active end.
	 * @param string $metricType Metric type.
	 * @param int $stepSeconds Bucket step in seconds.
	 * @param bool $useFullSort Whether to run the O(N log N) full-sort reference path.
	 * @return PercentileResult
	 */
	private function evaluate(
		array $circuitSamples,
		int $periodStart,
		int $periodEnd,
		float $percentile,
		string $directionMode,
		string $dropRounding,
		string $gapPolicy,
		array $maintWindows,
		array $circuitWindows,
		int $contractStart,
		int $contractEnd,
		string $metricType,
		int $stepSeconds,
		bool $useFullSort,
	): PercentileResult {
		if ($periodEnd <= $periodStart) {
			throw new BillingException('Billing period_end must be strictly greater than period_start.');
		}

		if ($stepSeconds <= 0) {
			throw new BillingException('Bucket step seconds must be positive.');
		}

		if ($percentile <= 0.0 || $percentile > 100.0) {
			throw new BillingException('Percentile must be within (0.0, 100.0].');
		}

		if (!in_array($directionMode, self::DIRECTION_MODES, true)) {
			throw new BillingException('Unsupported direction mode: ' . $directionMode);
		}

		if (!in_array($dropRounding, self::DROP_ROUNDINGS, true)) {
			throw new BillingException('Unsupported drop rounding mode: ' . $dropRounding);
		}

		if (!in_array($gapPolicy, self::GAP_POLICIES, true)) {
			throw new BillingException('Unsupported gap policy: ' . $gapPolicy);
		}

		$effStart = $contractStart > 0 ? max($periodStart, $contractStart) : $periodStart;
		$effEnd   = $contractEnd > 0 ? min($periodEnd, $contractEnd) : $periodEnd;

		if ($effEnd <= $effStart) {
			return new PercentileResult(0, 0, 0, 0, 0, 0, 0, 0, [], $useFullSort ? 'sort' : 'quickselect');
		}

		$gridStart       = intdiv($effStart, $stepSeconds) * $stepSeconds;
		$expectedBuckets = intdiv($effEnd - $gridStart + $stepSeconds - 1, $stepSeconds);
		$mergedMaint     = self::mergeIntervals($maintWindows);
		$hasMaint        = $mergedMaint !== [];
		$isBandwidth     = $metricType === 'bandwidth';

		// Step 1: Align 5-minute timestamps across all member circuits FIRST using flat int arrays.
		/** @var array<int, int> $aggIn */
		$aggIn = [];
		/** @var array<int, int> $aggOut */
		$aggOut = [];
		/** @var array<int, int> $aggRes */
		$aggRes        = [];
		$maintExcluded = 0;

		foreach ($circuitSamples as $circuitId => $samples) {
			$cFrom  = $circuitWindows[$circuitId]['active_from'] ?? 0;
			$cUntil = $circuitWindows[$circuitId]['active_until'] ?? 0;
			$cStart = $cFrom > 0 ? max($effStart, $cFrom) : $effStart;
			$cEnd   = $cUntil > 0 ? min($effEnd, $cUntil) : $effEnd;

			if ($cEnd <= $cStart) {
				continue;
			}

			foreach ($samples as $sample) {
				$rawTs = $sample['bucket_ts'];
				$ts    = ($rawTs % $stepSeconds === 0) ? $rawTs : intdiv($rawTs, $stepSeconds) * $stepSeconds;

				if ($ts < $cStart || $ts >= $cEnd) {
					continue;
				}

				if (($sample['quality_flag'] ?? 'ok') === 'maint' || ($hasMaint && self::inMaintenance($ts, $mergedMaint))) {
					$maintExcluded++;

					continue;
				}

				if ($isBandwidth) {
					$in  = $sample['in_bps'];
					$out = $sample['out_bps'];

					if (isset($aggIn[$ts])) {
						$aggIn[$ts]  += $in > 0 ? $in : 0;
						$aggOut[$ts] += $out > 0 ? $out : 0;
					} else {
						$aggIn[$ts]  = $in > 0 ? $in : 0;
						$aggOut[$ts] = $out > 0 ? $out : 0;
					}
				} else {
					$res         = $sample['resource_units'] ?? 0;
					$aggRes[$ts] = ($aggRes[$ts] ?? 0) + ($res > 0 ? $res : 0);
				}
			}
		}

		// Step 2: Apply missing-bucket gap policy and build dense parallel lists.
		/** @var list<int> $timestamps */
		$timestamps = [];
		/** @var list<int> $inList */
		$inList = [];
		/** @var list<int> $outList */
		$outList = [];
		/** @var list<int> $values */
		$values             = [];
		$totalTransferBytes = 0;

		if ($gapPolicy === 'exclude') {
			if ($isBandwidth) {
				foreach ($aggIn as $ts => $inVal) {
					$outVal              = $aggOut[$ts] ?? 0;
					$timestamps[]        = $ts;
					$totalTransferBytes += intdiv(($inVal + $outVal) * $stepSeconds, 8);

					if ($directionMode === 'separate_in_out') {
						$inList[]  = $inVal;
						$outList[] = $outVal;
					} elseif ($directionMode === 'sum_in_out') {
						$values[] = $inVal + $outVal;
					} elseif ($directionMode === 'in_only') {
						$values[] = $inVal;
					} elseif ($directionMode === 'out_only') {
						$values[] = $outVal;
					} else {
						$values[] = $inVal >= $outVal ? $inVal : $outVal;
					}
				}
			} else {
				foreach ($aggRes as $ts => $resVal) {
					$timestamps[] = $ts;
					$values[]     = $resVal;
				}
			}
		} else {
			$lastIn  = 0;
			$lastOut = 0;
			$lastRes = 0;
			$carry   = $gapPolicy === 'carry_forward';

			for ($ts = $gridStart; $ts < $effEnd; $ts += $stepSeconds) {
				if ($hasMaint && self::inMaintenance($ts, $mergedMaint)) {
					$maintExcluded++;

					continue;
				}

				$timestamps[] = $ts;

				if ($isBandwidth) {
					if (isset($aggIn[$ts])) {
						$inVal   = $aggIn[$ts];
						$outVal  = $aggOut[$ts] ?? 0;
						$lastIn  = $inVal;
						$lastOut = $outVal;
					} else {
						$inVal  = $carry ? $lastIn : 0;
						$outVal = $carry ? $lastOut : 0;
					}

					$totalTransferBytes += intdiv(($inVal + $outVal) * $stepSeconds, 8);

					if ($directionMode === 'separate_in_out') {
						$inList[]  = $inVal;
						$outList[] = $outVal;
					} elseif ($directionMode === 'sum_in_out') {
						$values[] = $inVal + $outVal;
					} elseif ($directionMode === 'in_only') {
						$values[] = $inVal;
					} elseif ($directionMode === 'out_only') {
						$values[] = $outVal;
					} else {
						$values[] = $inVal >= $outVal ? $inVal : $outVal;
					}
				} else {
					if (isset($aggRes[$ts])) {
						$resVal  = $aggRes[$ts];
						$lastRes = $resVal;
					} else {
						$resVal = $carry ? $lastRes : 0;
					}

					$values[] = $resVal;
				}
			}
		}

		$effectiveCount = count($timestamps);

		if ($effectiveCount === 0) {
			return new PercentileResult(0, 0, 0, $expectedBuckets, 0, 0, $maintExcluded, 0, [], $useFullSort ? 'sort' : 'quickselect');
		}

		$droppedCount = self::calculateDropCount($effectiveCount, $percentile, $dropRounding);

		if ($isBandwidth && $directionMode === 'separate_in_out') {
			$inSel    = $this->selectNth($timestamps, $inList, $droppedCount, $useFullSort);
			$outSel   = $this->selectNth($timestamps, $outList, $droppedCount, $useFullSort);
			$useIn    = $inSel['billable'] >= $outSel['billable'];
			$billable = $useIn ? $inSel['billable'] : $outSel['billable'];
			$peaks    = $useIn ? $inSel['peaks'] : $outSel['peaks'];

			return new PercentileResult(
				$billable,
				$inSel['billable'],
				$outSel['billable'],
				$expectedBuckets,
				$effectiveCount,
				$droppedCount,
				$maintExcluded,
				$totalTransferBytes,
				$peaks,
				$useFullSort ? 'sort' : 'quickselect'
			);
		}

		$selection = $this->selectNth($timestamps, $values, $droppedCount, $useFullSort);

		return new PercentileResult(
			$selection['billable'],
			$directionMode === 'in_only' ? $selection['billable'] : 0,
			$directionMode === 'out_only' ? $selection['billable'] : 0,
			$expectedBuckets,
			$effectiveCount,
			$droppedCount,
			$maintExcluded,
			$totalTransferBytes,
			$selection['peaks'],
			$useFullSort ? 'sort' : 'quickselect'
		);
	}

	/**
	 * Select the exact (N - 1 - droppedCount)-th ascending order statistic and extract
	 * the exact top-$droppedCount dropped peak intervals deterministically.
	 *
	 * @param list<int> $timestamps Parallel bucket timestamp array.
	 * @param list<int> $values Parallel scalar metric values.
	 * @param int $droppedCount Number of top peak intervals to drop.
	 * @param bool $useFullSort True for O(N log N) reference sort; false for O(N) 3-way Quickselect.
	 * @return array{billable: int, peaks: list<array{bucket_ts: int, value: int}>}
	 */
	private function selectNth(array $timestamps, array $values, int $droppedCount, bool $useFullSort): array {
		$n = count($values);

		if ($n === 0) {
			return ['billable' => 0, 'peaks' => []];
		}

		$targetRank = $n - 1 - $droppedCount;

		if ($useFullSort) {
			$pairs = [];

			for ($i = 0; $i < $n; $i++) {
				$pairs[] = ['bucket_ts' => $timestamps[$i], 'value' => $values[$i]];
			}

			usort(
				$pairs,
				static function (array $a, array $b): int {
					if ($a['value'] !== $b['value']) {
						return $b['value'] <=> $a['value'];
					}

					return $b['bucket_ts'] <=> $a['bucket_ts'];
				}
			);

			return [
				'billable' => $pairs[$droppedCount]['value'],
				'peaks'    => array_slice($pairs, 0, $droppedCount),
			];
		}

		$billable = self::quickselect3Way($values, $targetRank);

		if ($droppedCount === 0) {
			return ['billable' => $billable, 'peaks' => []];
		}

		/** @var list<array{bucket_ts: int, value: int}> $strictlyAbove */
		$strictlyAbove = [];
		/** @var list<int> $tiedTimestamps */
		$tiedTimestamps = [];

		for ($i = 0; $i < $n; $i++) {
			$v = $values[$i];

			if ($v > $billable) {
				$strictlyAbove[] = ['bucket_ts' => $timestamps[$i], 'value' => $v];
			} elseif ($v === $billable) {
				$tiedTimestamps[] = $timestamps[$i];
			}
		}

		$remaining = $droppedCount - count($strictlyAbove);

		if ($remaining > 0 && $tiedTimestamps !== []) {
			rsort($tiedTimestamps, SORT_NUMERIC);
			$take = min($remaining, count($tiedTimestamps));

			for ($i = 0; $i < $take; $i++) {
				$strictlyAbove[] = ['bucket_ts' => $tiedTimestamps[$i], 'value' => $billable];
			}
		}

		usort(
			$strictlyAbove,
			static function (array $a, array $b): int {
				if ($a['value'] !== $b['value']) {
					return $b['value'] <=> $a['value'];
				}

				return $b['bucket_ts'] <=> $a['bucket_ts'];
			}
		);

		return [
			'billable' => $billable,
			'peaks'    => $strictlyAbove,
		];
	}

	/**
	 * Iterative 3-way (Dutch National Flag) Quickselect with median-of-three pivot.
	 * Runs in O(N) expected time even on duplicate-heavy or constant traffic series.
	 *
	 * @param list<int> $arr Value copy of integer list to partition in place.
	 * @param int $k 0-based ascending target rank.
	 * @return int Value at ascending index $k.
	 */
	private static function quickselect3Way(array $arr, int $k): int {
		$left  = 0;
		$right = count($arr) - 1;

		while ($left < $right) {
			$mid = $left + intdiv($right - $left, 2);

			if ($arr[$mid] < $arr[$left]) {
				$tmp        = $arr[$left];
				$arr[$left] = $arr[$mid];
				$arr[$mid]  = $tmp;
			}

			if ($arr[$right] < $arr[$left]) {
				$tmp         = $arr[$left];
				$arr[$left]  = $arr[$right];
				$arr[$right] = $tmp;
			}

			if ($arr[$mid] < $arr[$right]) {
				$tmp         = $arr[$mid];
				$arr[$mid]   = $arr[$right];
				$arr[$right] = $tmp;
			}

			$pivot = $arr[$right];
			$lt    = $left;
			$i     = $left;
			$gt    = $right;

			while ($i <= $gt) {
				$v = $arr[$i];

				if ($v < $pivot) {
					$tmp      = $arr[$lt];
					$arr[$lt] = $v;
					$arr[$i]  = $tmp;
					$lt++;
					$i++;
				} elseif ($v > $pivot) {
					$tmp      = $arr[$gt];
					$arr[$gt] = $v;
					$arr[$i]  = $tmp;
					$gt--;
				} else {
					$i++;
				}
			}

			if ($k < $lt) {
				$right = $lt - 1;
			} elseif ($k > $gt) {
				$left = $gt + 1;
			} else {
				return $pivot;
			}
		}

		return $arr[$left];
	}

	/**
	 * Merge and sort maintenance windows so interval checks run in O(log M).
	 *
	 * @param list<array{start: int, end: int}> $windows Raw maintenance intervals.
	 * @return list<array{start: int, end: int}>
	 */
	private static function mergeIntervals(array $windows): array {
		$clean = [];

		foreach ($windows as $w) {
			if ($w['end'] > $w['start']) {
				$clean[] = ['start' => $w['start'], 'end' => $w['end']];
			}
		}

		if ($clean === []) {
			return [];
		}

		usort($clean, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
		$merged = [$clean[0]];

		for ($i = 1, $len = count($clean); $i < $len; $i++) {
			$lastIdx = count($merged) - 1;

			if ($clean[$i]['start'] <= $merged[$lastIdx]['end']) {
				$merged[$lastIdx]['end'] = max($merged[$lastIdx]['end'], $clean[$i]['end']);
			} else {
				$merged[] = $clean[$i];
			}
		}

		return $merged;
	}

	/**
	 * Test whether a bucket timestamp falls inside any merged maintenance window [start, end).
	 *
	 * @param int $ts Bucket Unix timestamp.
	 * @param list<array{start: int, end: int}> $merged Sorted non-overlapping maintenance intervals.
	 * @return bool
	 */
	private static function inMaintenance(int $ts, array $merged): bool {
		$lo = 0;
		$hi = count($merged) - 1;

		while ($lo <= $hi) {
			$mid = $lo + intdiv($hi - $lo, 2);
			$w   = $merged[$mid];

			if ($ts < $w['start']) {
				$hi = $mid - 1;
			} elseif ($ts >= $w['end']) {
				$lo = $mid + 1;
			} else {
				return true;
			}
		}

		return false;
	}
}
