#!/usr/bin/env php
<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_billing, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_billing
 *
 * Real PHP performance benchmark comparing O(N) 3-way Quickselect vs. O(N log N)
 * full sort across 500 and 1,000 multi-circuit LAG contracts x 8,640 monthly
 * 5-minute intervals, including aligned bucket aggregation, integer minor-unit
 * tariff rating, and streaming SHA-256 statement proof generation.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/include/autoload.php';

use Somethingwithproof\Billing\Rating\PercentileCalculator;
use Somethingwithproof\Billing\Rating\TariffRater;
use Somethingwithproof\Billing\Statement\StatementProof;

$calc  = new PercentileCalculator();
$rater = new TariffRater();

$start     = 1735689600;
$intervals = 8640; // 30 days of 5-minute intervals
$end       = $start + ($intervals * 300);

// Generate deterministic realistic 2-circuit LAG dataset (diurnal sine + burst spikes + duplicate plateaus).
$baseCircuitA = [];
$baseCircuitB = [];

for ($i = 0; $i < $intervals; $i++) {
	$ts   = $start + ($i * 300);
	$wave = (int) (400_000_000 + 250_000_000 * sin(($i % 288) / 288.0 * 2.0 * M_PI));
	$burstA = ($i % 97 === 0) ? 900_000_000 : 0;
	$burstB = ($i % 113 === 0) ? 850_000_000 : 0;

	$baseCircuitA[] = [
		'bucket_ts' => $ts,
		'in_bps'    => max(50_000_000, $wave + $burstA),
		'out_bps'   => max(25_000_000, intdiv($wave, 2)),
	];
	$baseCircuitB[] = [
		'bucket_ts' => $ts,
		'in_bps'    => max(50_000_000, intdiv($wave * 3, 4) + $burstB),
		'out_bps'   => max(25_000_000, intdiv($wave, 3)),
	];
}

$dataset  = [1 => $baseCircuitA, 2 => $baseCircuitB];
$contract = [
	'contract_code'         => 'BENCH-LAG-20G',
	'name'                  => 'Benchmark 2x10G LAG',
	'metric_type'           => 'bandwidth',
	'direction_mode'        => 'max_in_out',
	'percentile'            => 95.0,
	'drop_rounding'         => 'floor',
	'gap_policy'            => 'exclude',
	'tariff_model'          => 'stepped',
	'commit_units'          => 1_000_000_000,
	'unit_divisor'          => 1_000_000,
	'base_fee_minor'        => 250000,
	'overage_rate_minor'    => 200,
	'tiers_json'            => '[{"up_to_units":500000000,"rate_minor":220},{"up_to_units":null,"rate_minor":180}]',
	'tb_cap_bytes'          => 100_000_000_000_000,
	'tb_overage_rate_minor' => 500,
	'currency'              => 'USD',
];

// Verify exact equivalence before timing.
$refQs   = $calc->compute($dataset, $start, $end);
$refSort = $calc->computeWithFullSort($dataset, $start, $end);

if ($refQs->billableValue !== $refSort->billableValue || $refQs->droppedPeaks !== $refSort->droppedPeaks) {
	fwrite(STDERR, "ERROR: Quickselect and full-sort results diverged!\n");
	exit(1);
}

$results = [
	'php_version'     => PHP_VERSION,
	'os'              => PHP_OS_FAMILY . ' ' . php_uname('m'),
	'intervals'       => $intervals,
	'circuits_per_contract' => 2,
	'billable_bps'    => $refQs->billableValue,
	'dropped_count'   => $refQs->droppedCount,
	'runs'            => [],
];

foreach ([500, 1000] as $contracts) {
	gc_collect_cycles();
	$t0 = hrtime(true);

	for ($c = 0; $c < $contracts; $c++) {
		$res    = $calc->compute($dataset, $start, $end);
		$rating = $rater->rate($contract, $res, $start, $end);
		StatementProof::computeDigest(
			[
				'statement_code'       => 'BENCH-' . $c,
				'account_id'           => 1,
				'contract_id'          => $c + 1,
				'period_start'         => $start,
				'period_end'           => $end,
				'currency'             => 'USD',
				'billable_units'       => $res->billableValue,
				'billable_mbps_x1000'  => intdiv($res->billableValue, 1000),
				'total_transfer_bytes' => $res->totalTransferBytes,
				'base_amount_minor'    => $rating['base_amount_minor'],
				'overage_amount_minor' => $rating['overage_amount_minor'],
				'transfer_amount_minor' => $rating['transfer_amount_minor'],
				'total_amount_minor'   => $rating['total_amount_minor'],
				'sample_count'         => $res->effectiveSamples,
				'dropped_count'        => $res->droppedCount,
			],
			[
				'contract'      => $contract,
				'line_items'    => $rating['line_items'],
				'dropped_peaks' => $res->droppedPeaks,
			]
		);
	}

	$qsMs = (hrtime(true) - $t0) / 1e6;

	gc_collect_cycles();
	$t1 = hrtime(true);

	for ($c = 0; $c < $contracts; $c++) {
		$res    = $calc->computeWithFullSort($dataset, $start, $end);
		$rating = $rater->rate($contract, $res, $start, $end);
		StatementProof::computeDigest(
			[
				'statement_code'       => 'BENCH-' . $c,
				'account_id'           => 1,
				'contract_id'          => $c + 1,
				'period_start'         => $start,
				'period_end'           => $end,
				'currency'             => 'USD',
				'billable_units'       => $res->billableValue,
				'billable_mbps_x1000'  => intdiv($res->billableValue, 1000),
				'total_transfer_bytes' => $res->totalTransferBytes,
				'base_amount_minor'    => $rating['base_amount_minor'],
				'overage_amount_minor' => $rating['overage_amount_minor'],
				'transfer_amount_minor' => $rating['transfer_amount_minor'],
				'total_amount_minor'   => $rating['total_amount_minor'],
				'sample_count'         => $res->effectiveSamples,
				'dropped_count'        => $res->droppedCount,
			],
			[
				'contract'      => $contract,
				'line_items'    => $rating['line_items'],
				'dropped_peaks' => $res->droppedPeaks,
			]
		);
	}

	$sortMs = (hrtime(true) - $t1) / 1e6;

	$results['runs'][] = [
		'contracts'            => $contracts,
		'quickselect_total_ms' => round($qsMs, 2),
		'quickselect_per_ms'   => round($qsMs / $contracts, 3),
		'full_sort_total_ms'   => round($sortMs, 2),
		'full_sort_per_ms'     => round($sortMs / $contracts, 3),
		'speedup_ratio'        => round($sortMs / max(0.001, $qsMs), 2),
		'peak_rss_mib'         => round(memory_get_peak_usage(true) / (1024 * 1024), 2),
	];
}

echo json_encode($results, JSON_PRETTY_PRINT) . PHP_EOL;
