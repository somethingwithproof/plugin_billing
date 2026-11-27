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

namespace Somethingwithproof\Billing\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Somethingwithproof\Billing\Db\PdoDbAdapter;
use Somethingwithproof\Billing\Ledger\BillingException;
use Somethingwithproof\Billing\Ledger\BillingRepository;
use Somethingwithproof\Billing\Ledger\SampleIngestor;
use Somethingwithproof\Billing\Rating\BurstForecaster;
use Somethingwithproof\Billing\Rating\PercentileCalculator;
use Somethingwithproof\Billing\Rating\TariffRater;

final class LedgerAndRatingEngineTest extends TestCase {
	public function testSampleIngestor64BitWrapSpikeCapAndAppendOnlyImmutability(): void {
		$repo     = new BillingRepository(PdoDbAdapter::createSqliteMemory());
		$ingestor = new SampleIngestor($repo, 1.5);

		$acctId     = $repo->upsertAccount(['account_code' => 'ACME-01', 'name' => 'Acme Transit']);
		$contractId = $repo->upsertContract(['account_id' => $acctId, 'contract_code' => 'CTR-01', 'name' => '10G Transit']);
		$circuitId  = $repo->addCircuit(['contract_id' => $contractId, 'local_data_id' => 101, 'label' => 'et-0/0/1', 'port_speed_bps' => 10000000000]);

		// Exact unsigned 64-bit counter wraparound: prev = 2^64 - 375000000, curr = 375000000 -> delta = 750,000,000 octets in 300s = 20,000,000 bps (20 Mbps).
		$wrap = SampleIngestor::computeCounterRate('18446744073334551616', '375000000', 300, 10000000000, 1.5, true);
		$this->assertTrue($wrap['wrapped']);
		$this->assertFalse($wrap['clamped']);
		$this->assertSame(750000000, $wrap['deltaOctets']);
		$this->assertSame(20000000, $wrap['bps']);

		// 32-bit wraparound: prev = 2^32 - 300, curr = 700 -> delta = 1000 octets in 10s = 800 bps.
		$wrap32 = SampleIngestor::computeCounterRate(4294966996, 700, 10, 0, 1.5, false);
		$this->assertTrue($wrap32['wrapped']);
		$this->assertSame(800, $wrap32['bps']);

		// Spike sanity cap: 1G port with 1.5x multiplier caps a 5G spike to 1.5G.
		$clamped = SampleIngestor::computeCounterRate(0, 187500000000, 300, 1000000000, 1.5, true);
		$this->assertTrue($clamped['clamped']);
		$this->assertSame(1500000000, $clamped['bps']);

		// Append-only ledger immutability: second write to same (circuit, 300s bucket) must NOT overwrite.
		$first = $ingestor->ingestCounterDelta($circuitId, 1735689645, '18446744073334551616', '375000000', 0, 375000000, 300, 10000000000);
		$this->assertTrue($first['inserted']);
		$this->assertSame(1735689600, $first['bucketTs']);
		$this->assertSame('wrap', $first['delta']->qualityFlag);

		$duplicate = $ingestor->ingestCounterDelta($circuitId, 1735689710, 0, 99999999999, 0, 99999999999, 300, 10000000000);
		$this->assertFalse($duplicate['inserted']);

		$samples = $repo->fetchSamplesByCircuit([$circuitId], 1735689600, 1735690200);
		$this->assertCount(1, $samples[$circuitId]);
		$this->assertSame(20000000, $samples[$circuitId][0]['in_bps']);

		// Generic metered resources (power watts -> kWh micro-units, storage TiB-hours, GPU-hours).
		$wattsMicro = SampleIngestor::toResourceMicroUnits(1200.0, 'watts', 300); // 1.2 kW * (300/3600) h = 0.1 kWh = 100,000 micro-kWh
		$this->assertSame(100000, $wattsMicro);
		$this->assertSame(2500000, SampleIngestor::toResourceMicroUnits(2.5, 'tib_hours'));
		$this->assertSame(8000000, SampleIngestor::toResourceMicroUnits(8.0, 'gpu_hours'));

		$direct = $ingestor->ingestDirectSample($circuitId, 1735689900, 0, 0, $wattsMicro, 'ok');
		$this->assertTrue($direct['inserted']);
		$latest = $repo->latestSampleBefore($circuitId, 1735690200);
		$this->assertNotNull($latest);
		$this->assertSame(100000, (int) $latest['resource_units']);
	}

	public function testMultiCircuitLagAggregate95thIsNotSumOfPerPort95ths(): void {
		$calc  = new PercentileCalculator();
		$start = 1735689600;
		$end   = $start + (100 * 300);

		// Two 10G LAG member ports whose peaks occur at DIFFERENT 5-minute buckets.
		// Port A peaks at 900 Mbps on buckets 0..9 (10 buckets), 100 Mbps otherwise -> per-port 95th = 900 Mbps.
		// Port B peaks at 900 Mbps on buckets 10..19 (10 buckets), 100 Mbps otherwise -> per-port 95th = 900 Mbps.
		// Sum of per-port 95ths = 1,800 Mbps.
		// True timestamp-aligned LAG aggregate: every bucket 0..19 has 900 + 100 = 1,000 Mbps, all others 200 Mbps -> aggregate 95th = 1,000 Mbps!
		$portA = [];
		$portB = [];

		for ($i = 0; $i < 100; $i++) {
			$ts      = $start + ($i * 300);
			$portA[] = ['bucket_ts' => $ts, 'in_bps' => $i < 10 ? 900_000_000 : 100_000_000, 'out_bps' => 50_000_000];
			$portB[] = ['bucket_ts' => $ts, 'in_bps' => ($i >= 10 && $i < 20) ? 900_000_000 : 100_000_000, 'out_bps' => 50_000_000];
		}

		$pA   = $calc->compute([1 => $portA], $start, $end, 95.0);
		$pB   = $calc->compute([2 => $portB], $start, $end, 95.0);
		$agg  = $calc->compute([1 => $portA, 2 => $portB], $start, $end, 95.0);
		$sort = $calc->computeWithFullSort([1 => $portA, 2 => $portB], $start, $end, 95.0);

		$this->assertSame(900_000_000, $pA->billableValue);
		$this->assertSame(900_000_000, $pB->billableValue);
		$this->assertSame(1_000_000_000, $agg->billableValue);
		$this->assertNotSame($pA->billableValue + $pB->billableValue, $agg->billableValue);

		// Quickselect and Full-Sort reference must match bit-for-bit on billable value and dropped peaks.
		$this->assertSame($sort->billableValue, $agg->billableValue);
		$this->assertSame($sort->droppedPeaks, $agg->droppedPeaks);
		$this->assertSame(5, $agg->droppedCount);
	}

	public function testDirectionModesDropRoundingGapPoliciesMaintAndProRata(): void {
		$calc  = new PercentileCalculator();
		$start = 1735689600;
		$end   = $start + (20 * 300); // 20 expected buckets

		$samples = [];

		for ($i = 0; $i < 18; $i++) {
			// Skip buckets 18 and 19 to test gap policies.
			$samples[] = [
				'bucket_ts' => $start + ($i * 300),
				'in_bps'    => ($i + 1) * 10_000_000,
				'out_bps'   => (18 - $i) * 10_000_000,
			];
		}

		// Direction modes on identical input.
		$maxMode = $calc->compute([1 => $samples], $start, $end, 95.0, 'max_in_out', 'floor', 'exclude');
		$sumMode = $calc->compute([1 => $samples], $start, $end, 95.0, 'sum_in_out', 'floor', 'exclude');
		$sepMode = $calc->compute([1 => $samples], $start, $end, 95.0, 'separate_in_out', 'floor', 'exclude');
		$inMode  = $calc->compute([1 => $samples], $start, $end, 95.0, 'in_only', 'floor', 'exclude');
		$outMode = $calc->compute([1 => $samples], $start, $end, 95.0, 'out_only', 'floor', 'exclude');

		// 18 samples at 95% floor -> drop 0 (floor(0.9) = 0), so max of series is returned.
		$this->assertSame(0, $maxMode->droppedCount);
		$this->assertSame(180_000_000, $maxMode->billableValue);
		$this->assertSame(190_000_000, $sumMode->billableValue);
		$this->assertSame(180_000_000, $sepMode->inBillableValue);
		$this->assertSame(180_000_000, $sepMode->outBillableValue);
		$this->assertSame(180_000_000, $inMode->billableValue);
		$this->assertSame(180_000_000, $outMode->billableValue);

		// Drop-count rounding: 18 samples at 95% -> raw drop = 0.9: floor=0, ceil=1, nearest=1.
		$ceilMode    = $calc->compute([1 => $samples], $start, $end, 95.0, 'in_only', 'ceil', 'exclude');
		$nearestMode = $calc->compute([1 => $samples], $start, $end, 95.0, 'in_only', 'nearest', 'exclude');
		$this->assertSame(1, $ceilMode->droppedCount);
		$this->assertSame(170_000_000, $ceilMode->billableValue);
		$this->assertSame(1, $nearestMode->droppedCount);
		$this->assertSame(170_000_000, $nearestMode->billableValue);

		// Gap policies: zero_fill adds 2 zeros (20 samples -> floor(1.0)=1 drop -> 170M); carry_forward repeats 180M twice -> 180M.
		$zeroFill     = $calc->compute([1 => $samples], $start, $end, 95.0, 'in_only', 'floor', 'zero_fill');
		$carryForward = $calc->compute([1 => $samples], $start, $end, 95.0, 'in_only', 'floor', 'carry_forward');
		$this->assertSame(20, $zeroFill->effectiveSamples);
		$this->assertSame(170_000_000, $zeroFill->billableValue);
		$this->assertSame(20, $carryForward->effectiveSamples);
		$this->assertSame(180_000_000, $carryForward->billableValue);

		// Cacti Maint window exclusion drops buckets 16 & 17 (170M and 180M peaks).
		$maintWindows = [['start' => $start + (16 * 300), 'end' => $start + (18 * 300)]];
		$withMaint    = $calc->compute([1 => $samples], $start, $end, 95.0, 'in_only', 'floor', 'exclude', $maintWindows);
		$this->assertSame(2, $withMaint->maintExcludedCount);
		$this->assertSame(160_000_000, $withMaint->billableValue);

		// Mid-month pro-rata active contract window restricts evaluation to first 10 buckets.
		$proRata = $calc->compute([1 => $samples], $start, $end, 95.0, 'in_only', 'floor', 'exclude', [], [], $start, $start + (10 * 300));
		$this->assertSame(10, $proRata->effectiveSamples);
		$this->assertSame(100_000_000, $proRata->billableValue);
	}

	public function testIntegerMinorUnitTariffRaterAndBurstForecaster(): void {
		$rater  = new TariffRater();
		$calc   = new PercentileCalculator();
		$start  = 1735689600;
		$end    = $start + (30 * 86400); // 30-day cycle = 8,640 5-min slots

		// Simulate 1,500 Mbps 95th-percentile over a 1,000 Mbps commit (500 Mbps overage) and 15 TB transfer over 10 TB cap.
		$pctResult = new \Somethingwithproof\Billing\Rating\PercentileResult(
			1_500_000_000,
			1_500_000_000,
			500_000_000,
			8640,
			8640,
			432,
			0,
			15_000_000_000_000,
			[],
			'quickselect'
		);

		// Flat tariff with half-month pro-rata base fee ($1,000.00 -> $500.00) + $2.50/Mbps overage (500 * 250 = $1,250.00) + $5.00/TB (5 * 500 = $25.00).
		$flatContract = [
			'currency'              => 'USD',
			'tariff_model'          => 'flat',
			'commit_units'          => 1_000_000_000,
			'unit_divisor'          => 1_000_000,
			'base_fee_minor'        => 100000,
			'overage_rate_minor'    => 250,
			'tb_cap_bytes'          => 10_000_000_000_000,
			'tb_overage_rate_minor' => 500,
			'starts_at'             => $start + (15 * 86400),
			'ends_at'               => 0,
		];
		$flatRated = $rater->rate($flatContract, $pctResult, $start, $end);
		$this->assertSame(50000, $flatRated['base_amount_minor']);
		$this->assertSame(125000, $flatRated['overage_amount_minor']);
		$this->assertSame(2500, $flatRated['transfer_amount_minor']);
		$this->assertSame(177500, $flatRated['total_amount_minor']);

		// Stepped tiers: first 200 Mbps @ $3.00 (60,000c) + next 300 Mbps @ $2.00 (60,000c) = 120,000c ($1,200.00).
		$tiersJson       = json_encode([
			['up_to_units' => 200_000_000, 'rate_minor' => 300],
			['up_to_units' => null, 'rate_minor' => 200],
		]);
		$steppedContract = array_merge($flatContract, ['tariff_model' => 'stepped', 'tiers_json' => $tiersJson, 'starts_at' => 0, 'tb_cap_bytes' => 0]);
		$steppedRated    = $rater->rate($steppedContract, $pctResult, $start, $end);
		$this->assertSame(100000, $steppedRated['base_amount_minor']);
		$this->assertSame(120000, $steppedRated['overage_amount_minor']);

		// Volume tiers: total 500 Mbps falls in top bracket ($2.00) -> all 500 Mbps @ $2.00 = 100,000c ($1,000.00).
		$volumeContract = array_merge($steppedContract, ['tariff_model' => 'volume']);
		$volumeRated    = $rater->rate($volumeContract, $pctResult, $start, $end);
		$this->assertSame(100000, $volumeRated['overage_amount_minor']);

		// BurstForecaster: 30-day cycle = 8,640 slots, 5% burst budget = 432 slots = 36 hours (2,160 min).
		$forecaster = new BurstForecaster($calc, $rater, 70, 90);
		$burstSamples = [];

		// 350 burst slots (~81% of 432) -> warning severity.
		for ($i = 0; $i < 350; $i++) {
			$burstSamples[] = ['bucket_ts' => $start + ($i * 300), 'in_bps' => 1_400_000_000, 'out_bps' => 100_000_000];
		}

		$warn = $forecaster->forecast($steppedContract, [1 => $burstSamples], $start, $end, $start + (5 * 86400));
		$this->assertSame(8640, $warn['total_period_slots']);
		$this->assertSame(432, $warn['burst_budget_slots']);
		$this->assertSame(2160, $warn['burst_budget_minutes']);
		$this->assertSame(350, $warn['consumed_burst_slots']);
		$this->assertSame(82, $warn['remaining_burst_slots']);
		$this->assertSame('warning', $warn['severity']);
		$this->assertFalse($warn['over_commit']);

		// Push past 432 slots -> 450 slots consumed -> breached severity with locked-in overage.
		for ($i = 350; $i < 450; $i++) {
			$burstSamples[] = ['bucket_ts' => $start + ($i * 300), 'in_bps' => 1_400_000_000, 'out_bps' => 100_000_000];
		}

		$breached = $forecaster->forecast($steppedContract, [1 => $burstSamples], $start, $end, $start + (5 * 86400));
		$this->assertSame('breached', $breached['severity']);
		$this->assertTrue($breached['over_commit']);
		$this->assertSame(0, $breached['remaining_burst_slots']);
		$this->assertGreaterThan(0, $breached['projected_overage_minor']);
	}

	public function testQuickselectMatchesFullSortOnDuplicateHeavyAndEdgeInputs(): void {
		$calc  = new PercentileCalculator();
		$start = 1735689600;
		$end   = $start + (500 * 300);

		// Heavy duplicate values (0, 500M, 1000M) that break naive 2-way Quickselect.
		$samples = [];

		for ($i = 0; $i < 500; $i++) {
			$v         = ($i % 10 === 0) ? 1_000_000_000 : (($i % 3 === 0) ? 500_000_000 : 0);
			$samples[] = ['bucket_ts' => $start + ($i * 300), 'in_bps' => $v, 'out_bps' => 0];
		}

		$qs   = $calc->compute([1 => $samples], $start, $end, 95.0);
		$sort = $calc->computeWithFullSort([1 => $samples], $start, $end, 95.0);

		$this->assertSame($sort->billableValue, $qs->billableValue);
		$this->assertSame($sort->droppedPeaks, $qs->droppedPeaks);
		$this->assertSame('quickselect', $qs->algorithm);
		$this->assertSame('sort', $sort->algorithm);

		// Empty period returns zeros cleanly.
		$empty = $calc->compute([], $start, $end);
		$this->assertSame(0, $empty->billableValue);

		$this->expectException(BillingException::class);
		$calc->compute([], $end, $start);
	}
}
