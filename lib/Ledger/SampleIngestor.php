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

namespace Somethingwithproof\Billing\Ledger;

/**
 * Ingests 64-bit interface octet counters and generic metered resources (power,
 * storage, GPU) into the append-only 5-minute billing ledger with exact unsigned
 * 64-bit wraparound arithmetic and spike sanity capping.
 */
final class SampleIngestor {
	/**
	 * Canonical 5-minute billing bucket step in seconds.
	 */
	public const DEFAULT_STEP_SECONDS = 300;

	/**
	 * Base-10^18 limb radix for zero-extension exact unsigned 64-bit arithmetic.
	 */
	private const LIMB_BASE = 1000000000000000000;

	/**
	 * High limb of 2^64 (18446744073709551616 = 18 * 10^18 + 446744073709551616).
	 */
	private const UINT64_MOD_HI = 18;

	/**
	 * Low limb of 2^64 modulo 10^18.
	 */
	private const UINT64_MOD_LO = 446744073709551616;

	/**
	 * Modulus for legacy 32-bit counters (2^32).
	 */
	private const UINT32_MOD = 4294967296;

	/**
	 * Scale factor converting whole generic resource units to integer micro-units.
	 */
	public const MICRO_UNIT_SCALE = 1000000;

	/**
	 * Construct a SampleIngestor backed by a BillingRepository.
	 *
	 * @param BillingRepository $repository Billing storage repository.
	 * @param float $defaultSpikeMultiplier Default port-speed multiplier for spike clamping.
	 */
	public function __construct(
		private readonly BillingRepository $repository,
		private readonly float $defaultSpikeMultiplier = 1.5,
	) {
	}

	/**
	 * Align any Unix timestamp down to its canonical bucket boundary.
	 *
	 * @param int $timestamp Raw Unix timestamp.
	 * @param int $stepSeconds Bucket step width in seconds (default 300).
	 * @return int
	 */
	public static function alignBucket(int $timestamp, int $stepSeconds = self::DEFAULT_STEP_SECONDS): int {
		if ($stepSeconds <= 0) {
			throw new BillingException('Bucket step seconds must be positive.');
		}

		if ($timestamp < 0) {
			throw new BillingException('Sample timestamp must be non-negative.');
		}

		return intdiv($timestamp, $stepSeconds) * $stepSeconds;
	}

	/**
	 * Compute exact bits-per-second from consecutive octet counters with 64-bit/32-bit
	 * wraparound handling and port-speed spike capping.
	 *
	 * @param int|string $prevOctets Previous unsigned octet counter.
	 * @param int|string $currOctets Current unsigned octet counter.
	 * @param int $intervalSeconds Elapsed interval in seconds.
	 * @param int $portSpeedBps Circuit line speed in bps (0 disables spike cap).
	 * @param float $spikeMultiplier Allowed multiple of portSpeedBps before clamping.
	 * @param bool $is64Bit True for 64-bit HC counters; false for 32-bit counters.
	 * @return array{bps: int, deltaOctets: int, wrapped: bool, clamped: bool}
	 */
	public static function computeCounterRate(
		int|string $prevOctets,
		int|string $currOctets,
		int $intervalSeconds,
		int $portSpeedBps = 0,
		float $spikeMultiplier = 1.5,
		bool $is64Bit = true,
	): array {
		if ($intervalSeconds <= 0) {
			throw new BillingException('Counter delta interval must be positive.');
		}

		$delta   = self::unsignedCounterDelta($prevOctets, $currOctets, $is64Bit);
		$wrapped = $delta['wrapped'];
		$octets  = $delta['delta'];

		// Overflow-safe exact integer (octets * 8) / intervalSeconds.
		$whole   = intdiv($octets, $intervalSeconds);
		$rem     = $octets % $intervalSeconds;
		$bps     = ($whole * 8) + intdiv($rem * 8 + intdiv($intervalSeconds, 2), $intervalSeconds);
		$clamped = false;

		if ($portSpeedBps > 0 && $spikeMultiplier >= 1.0) {
			$cap = (int) floor((float) $portSpeedBps * $spikeMultiplier);

			if ($bps > $cap) {
				$bps     = $cap;
				$clamped = true;
			}
		}

		return [
			'bps'         => max(0, $bps),
			'deltaOctets' => $octets,
			'wrapped'     => $wrapped,
			'clamped'     => $clamped,
		];
	}

	/**
	 * Ingest a pair of raw ingress/egress octet counters for a circuit into the immutable ledger.
	 *
	 * @param int $circuitId Circuit primary key ID.
	 * @param int $timestamp Sample Unix timestamp (aligned to 300s bucket).
	 * @param int|string $prevInOctets Previous ingress counter.
	 * @param int|string $currInOctets Current ingress counter.
	 * @param int|string $prevOutOctets Previous egress counter.
	 * @param int|string $currOutOctets Current egress counter.
	 * @param int $intervalSeconds Elapsed interval in seconds.
	 * @param int $portSpeedBps Circuit interface speed in bps.
	 * @param bool $is64Bit True for 64-bit counters; false for 32-bit counters.
	 * @param float|null $spikeMultiplier Optional override for spike sanity multiplier.
	 * @return array{inserted: bool, bucketTs: int, delta: CounterDelta}
	 */
	public function ingestCounterDelta(
		int $circuitId,
		int $timestamp,
		int|string $prevInOctets,
		int|string $currInOctets,
		int|string $prevOutOctets,
		int|string $currOutOctets,
		int $intervalSeconds = self::DEFAULT_STEP_SECONDS,
		int $portSpeedBps = 0,
		bool $is64Bit = true,
		?float $spikeMultiplier = null,
	): array {
		$bucketTs   = self::alignBucket($timestamp, self::DEFAULT_STEP_SECONDS);
		$multiplier = $spikeMultiplier ?? $this->defaultSpikeMultiplier;

		$in  = self::computeCounterRate($prevInOctets, $currInOctets, $intervalSeconds, $portSpeedBps, $multiplier, $is64Bit);
		$out = self::computeCounterRate($prevOutOctets, $currOutOctets, $intervalSeconds, $portSpeedBps, $multiplier, $is64Bit);

		$wrapped = $in['wrapped'] || $out['wrapped'];
		$clamped = $in['clamped'] || $out['clamped'];
		$flag    = $clamped ? 'clamped' : ($wrapped ? 'wrap' : 'ok');

		$delta    = new CounterDelta($in['bps'], $out['bps'], 0, $flag, $wrapped, $clamped);
		$rawIn    = self::clampToSignedInt($currInOctets);
		$rawOut   = self::clampToSignedInt($currOutOctets);
		$inserted = $this->repository->appendSample(
			$circuitId,
			$bucketTs,
			$delta->inBps,
			$delta->outBps,
			0,
			$rawIn,
			$rawOut,
			$delta->qualityFlag
		);

		return [
			'inserted' => $inserted,
			'bucketTs' => $bucketTs,
			'delta'    => $delta,
		];
	}

	/**
	 * Ingest pre-computed network bps or generic metered resource usage (power watts/kWh,
	 * storage TiB-hours, GPU-hours) into the append-only 5-minute ledger.
	 *
	 * @param int $circuitId Circuit or resource feed ID.
	 * @param int $timestamp Sample Unix timestamp (aligned to 300s bucket).
	 * @param int $inBps Ingress rate in bps (0 for non-network resources).
	 * @param int $outBps Egress rate in bps (0 for non-network resources).
	 * @param int $resourceMicroUnits Generic metered quantity in micro-units (1e-6).
	 * @param string $qualityFlag Quality marker (ok, wrap, clamped, gap, maint).
	 * @return array{inserted: bool, bucketTs: int}
	 */
	public function ingestDirectSample(
		int $circuitId,
		int $timestamp,
		int $inBps,
		int $outBps,
		int $resourceMicroUnits = 0,
		string $qualityFlag = 'ok',
	): array {
		if (!in_array($qualityFlag, ['ok', 'wrap', 'clamped', 'gap', 'maint'], true)) {
			throw new BillingException('Unsupported sample quality_flag: ' . $qualityFlag);
		}

		$bucketTs = self::alignBucket($timestamp, self::DEFAULT_STEP_SECONDS);
		$inserted = $this->repository->appendSample(
			$circuitId,
			$bucketTs,
			max(0, $inBps),
			max(0, $outBps),
			max(0, $resourceMicroUnits),
			0,
			0,
			$qualityFlag
		);

		return [
			'inserted' => $inserted,
			'bucketTs' => $bucketTs,
		];
	}

	/**
	 * Convert a generic resource measurement (watts, kWh, TiB-hours, GPU-hours) into
	 * integer micro-units for exact storage and rating.
	 *
	 * @param float $units Positive resource measurement.
	 * @param string $unitType Unit type (watts, kwh, tib_hours, gpu_hours).
	 * @param int $intervalSeconds Interval width in seconds (used when converting watts to kWh).
	 * @return int Integer micro-units (scaled by 1,000,000).
	 */
	public static function toResourceMicroUnits(
		float $units,
		string $unitType,
		int $intervalSeconds = self::DEFAULT_STEP_SECONDS,
	): int {
		if ($units < 0.0 || !is_finite($units)) {
			throw new BillingException('Resource measurement must be finite and non-negative.');
		}

		$type = strtolower(trim($unitType));

		if ($type === 'watts') {
			// Convert average watts over intervalSeconds into kWh micro-units: (W * s) / 3,600,000 * 1e6.
			$kwh = ($units * (float) $intervalSeconds) / 3600000.0;

			return (int) round($kwh * self::MICRO_UNIT_SCALE);
		}

		if (in_array($type, ['kwh', 'tib_hours', 'gpu_hours'], true)) {
			return (int) round($units * self::MICRO_UNIT_SCALE);
		}

		throw new BillingException('Unsupported resource unit type: ' . $unitType);
	}

	/**
	 * Compute exact unsigned 64-bit or 32-bit counter delta without bcmath or gmp.
	 *
	 * @param int|string $prev Previous unsigned counter.
	 * @param int|string $curr Current unsigned counter.
	 * @param bool $is64Bit True for 64-bit modulus; false for 32-bit modulus.
	 * @return array{delta: int, wrapped: bool}
	 */
	private static function unsignedCounterDelta(int|string $prev, int|string $curr, bool $is64Bit): array {
		[$pHi, $pLo] = self::parseUnsignedLimbs($prev);
		[$cHi, $cLo] = self::parseUnsignedLimbs($curr);

		if (!$is64Bit) {
			if ($pHi > 0 || $pLo >= self::UINT32_MOD || $cHi > 0 || $cLo >= self::UINT32_MOD) {
				throw new BillingException('32-bit counter value out of range [0, 2^32 - 1].');
			}

			if ($cLo >= $pLo) {
				return ['delta' => $cLo - $pLo, 'wrapped' => false];
			}

			return ['delta' => (self::UINT32_MOD - $pLo) + $cLo, 'wrapped' => true];
		}

		if ($cHi > $pHi || ($cHi === $pHi && $cLo >= $pLo)) {
			$dHi = $cHi - $pHi;
			$dLo = $cLo - $pLo;

			if ($dLo < 0) {
				$dLo += self::LIMB_BASE;
				$dHi--;
			}

			return ['delta' => self::limbsToInt($dHi, $dLo), 'wrapped' => false];
		}

		// Wraparound modulo 2^64: delta = (2^64 - prev) + curr.
		$dHi = (self::UINT64_MOD_HI - $pHi) + $cHi;
		$dLo = (self::UINT64_MOD_LO - $pLo) + $cLo;

		while ($dLo < 0) {
			$dLo += self::LIMB_BASE;
			$dHi--;
		}

		while ($dLo >= self::LIMB_BASE) {
			$dLo -= self::LIMB_BASE;
			$dHi++;
		}

		return ['delta' => self::limbsToInt($dHi, $dLo), 'wrapped' => true];
	}

	/**
	 * Parse an unsigned decimal integer into (hi, lo) base-10^18 limbs.
	 *
	 * @param int|string $value Non-negative integer or decimal string.
	 * @return array{0: int, 1: int}
	 */
	private static function parseUnsignedLimbs(int|string $value): array {
		if (is_int($value)) {
			if ($value < 0) {
				throw new BillingException('Counter value must be non-negative.');
			}

			return [intdiv($value, self::LIMB_BASE), $value % self::LIMB_BASE];
		}

		$str = trim($value);

		if (preg_match('/^[0-9]{1,20}$/', $str) !== 1) {
			throw new BillingException('Invalid unsigned counter string: ' . $str);
		}

		$str = ltrim($str, '0');

		if ($str === '') {
			return [0, 0];
		}

		if (strlen($str) <= 18) {
			return [0, (int) $str];
		}

		$hi = (int) substr($str, 0, -18);
		$lo = (int) substr($str, -18);

		if ($hi > self::UINT64_MOD_HI || ($hi === self::UINT64_MOD_HI && $lo >= self::UINT64_MOD_LO)) {
			throw new BillingException('Counter value exceeds unsigned 64-bit maximum.');
		}

		return [$hi, $lo];
	}

	/**
	 * Combine base-10^18 limbs back into a signed 64-bit PHP integer, capping at PHP_INT_MAX.
	 *
	 * @param int $hi High limb.
	 * @param int $lo Low limb.
	 * @return int
	 */
	private static function limbsToInt(int $hi, int $lo): int {
		if ($hi > 9 || ($hi === 9 && $lo > (PHP_INT_MAX % self::LIMB_BASE))) {
			return PHP_INT_MAX;
		}

		return ($hi * self::LIMB_BASE) + $lo;
	}

	/**
	 * Convert a raw counter value into a non-negative PHP integer for ledger metadata storage.
	 *
	 * @param int|string $value Raw counter value.
	 * @return int
	 */
	private static function clampToSignedInt(int|string $value): int {
		[$hi, $lo] = self::parseUnsignedLimbs($value);

		return self::limbsToInt($hi, $lo);
	}
}
