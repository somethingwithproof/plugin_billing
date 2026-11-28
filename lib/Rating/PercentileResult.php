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
 * Immutable result returned by PercentileCalculator for a billing period.
 */
final class PercentileResult {
	/**
	 * Construct a PercentileResult.
	 *
	 * @param int $billableValue Final Nth-percentile billable value (bps or micro-units).
	 * @param int $inBillableValue Ingress Nth-percentile value (populated for separate_in_out).
	 * @param int $outBillableValue Egress Nth-percentile value (populated for separate_in_out).
	 * @param int $expectedBuckets Total expected 5-minute buckets in the active period.
	 * @param int $effectiveSamples Number of effective samples evaluated after gap and maintenance policies.
	 * @param int $droppedCount Number of top peak intervals excluded by the Nth-percentile rule.
	 * @param int $maintExcludedCount Number of buckets excluded due to Cacti maintenance windows.
	 * @param int $totalTransferBytes Total ingress + egress bytes transferred across evaluated buckets.
	 * @param list<array{bucket_ts: int, value: int}> $droppedPeaks Exact dropped peak intervals sorted descending.
	 * @param string $algorithm Selection algorithm used (quickselect or sort).
	 */
	public function __construct(
		public readonly int $billableValue,
		public readonly int $inBillableValue,
		public readonly int $outBillableValue,
		public readonly int $expectedBuckets,
		public readonly int $effectiveSamples,
		public readonly int $droppedCount,
		public readonly int $maintExcludedCount,
		public readonly int $totalTransferBytes,
		public readonly array $droppedPeaks,
		public readonly string $algorithm = 'quickselect',
	) {
	}

	/**
	 * Export summary metrics as an associative array for statements and APIs.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'billable_value'       => $this->billableValue,
			'in_billable_value'    => $this->inBillableValue,
			'out_billable_value'   => $this->outBillableValue,
			'expected_buckets'     => $this->expectedBuckets,
			'effective_samples'    => $this->effectiveSamples,
			'dropped_count'        => $this->droppedCount,
			'maint_excluded_count' => $this->maintExcludedCount,
			'total_transfer_bytes' => $this->totalTransferBytes,
			'dropped_peaks'        => $this->droppedPeaks,
			'algorithm'            => $this->algorithm,
		];
	}
}
