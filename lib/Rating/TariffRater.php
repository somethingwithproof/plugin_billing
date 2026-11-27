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
 * Pure integer minor-unit (cents / micro-dollars) tariff rater with zero
 * floating-point currency rounding drift. Supports pro-rata base fees,
 * flat/stepped/volume burstable overage tiers, and TB data-transfer caps.
 */
final class TariffRater {
	/**
	 * Decimal bytes per terabyte (1e12 bytes) for transfer cap overage rating.
	 */
	public const BYTES_PER_TB = 1000000000000;

	/**
	 * Rate a contract period purely in integer minor currency units.
	 *
	 * @param array<string, mixed> $contract Contract configuration row.
	 * @param PercentileResult $percentileResult Evaluated Nth-percentile result.
	 * @param int $periodStart Billing period start Unix timestamp.
	 * @param int $periodEnd Billing period end Unix timestamp.
	 * @return array{
	 *   currency: string,
	 *   base_amount_minor: int,
	 *   overage_amount_minor: int,
	 *   transfer_amount_minor: int,
	 *   total_amount_minor: int,
	 *   overage_units: int,
	 *   excess_transfer_bytes: int,
	 *   prorata_active_seconds: int,
	 *   prorata_period_seconds: int,
	 *   line_items: list<array<string, mixed>>
	 * }
	 */
	public function rate(
		array $contract,
		PercentileResult $percentileResult,
		int $periodStart,
		int $periodEnd,
	): array {
		if ($periodEnd <= $periodStart) {
			throw new BillingException('Billing period_end must be strictly greater than period_start.');
		}

		$currency       = is_string($contract['currency'] ?? null) ? strtoupper(trim($contract['currency'])) : 'USD';
		$tariffModel    = is_string($contract['tariff_model'] ?? null) ? strtolower(trim($contract['tariff_model'])) : 'flat';
		$commitUnits    = is_numeric($contract['commit_units'] ?? null) ? max(0, (int) $contract['commit_units']) : 0;
		$unitDivisor    = is_numeric($contract['unit_divisor'] ?? null) ? max(1, (int) $contract['unit_divisor']) : 1000000;
		$baseFeeMinor   = is_numeric($contract['base_fee_minor'] ?? null) ? (int) $contract['base_fee_minor'] : 0;
		$overageRate    = is_numeric($contract['overage_rate_minor'] ?? null) ? (int) $contract['overage_rate_minor'] : 0;
		$tbCapBytes     = is_numeric($contract['tb_cap_bytes'] ?? null) ? max(0, (int) $contract['tb_cap_bytes']) : 0;
		$tbOverageRate  = is_numeric($contract['tb_overage_rate_minor'] ?? null) ? (int) $contract['tb_overage_rate_minor'] : 0;
		$contractStart  = is_numeric($contract['starts_at'] ?? null) ? (int) $contract['starts_at'] : 0;
		$contractEnd    = is_numeric($contract['ends_at'] ?? null) ? (int) $contract['ends_at'] : 0;
		$tiersRaw       = is_string($contract['tiers_json'] ?? null) ? $contract['tiers_json'] : '[]';
		$tiers          = TariffTier::parseList($tiersRaw);

		// Pro-rata fixed commit/base fee using exact integer half-up arithmetic.
		$periodSeconds = $periodEnd - $periodStart;
		$effStart      = $contractStart > 0 ? max($periodStart, $contractStart) : $periodStart;
		$effEnd        = $contractEnd > 0 ? min($periodEnd, $contractEnd) : $periodEnd;
		$activeSeconds = max(0, $effEnd - $effStart);
		$baseMinor     = self::scaleMinorUnits($baseFeeMinor, $activeSeconds, $periodSeconds);

		// Burstable overage above commit.
		$overageUnits = max(0, $percentileResult->billableValue - $commitUnits);
		$overageMinor = 0;

		if ($overageUnits > 0) {
			$overageMinor = match ($tariffModel) {
				'stepped' => self::rateStepped($overageUnits, $unitDivisor, $tiers, $overageRate),
				'volume'  => self::rateVolume($overageUnits, $unitDivisor, $tiers, $overageRate),
				default   => self::scaleMinorUnits($overageRate, $overageUnits, $unitDivisor),
			};
		}

		// Optional TB data-transfer cap overage.
		$excessTransferBytes = 0;
		$transferMinor       = 0;

		if ($tbCapBytes > 0 && $percentileResult->totalTransferBytes > $tbCapBytes && $tbOverageRate > 0) {
			$excessTransferBytes = $percentileResult->totalTransferBytes - $tbCapBytes;
			$transferMinor       = self::scaleMinorUnits($tbOverageRate, $excessTransferBytes, self::BYTES_PER_TB);
		}

		$lineItems = [];

		if ($baseMinor > 0 || $baseFeeMinor > 0) {
			$lineItems[] = [
				'code'            => 'commit_base',
				'description'     => $activeSeconds < $periodSeconds ? 'Pro-rata commit fee' : 'Monthly commit fee',
				'quantity_units'  => $commitUnits,
				'unit_divisor'    => $unitDivisor,
				'unit_rate_minor' => $baseFeeMinor,
				'amount_minor'    => $baseMinor,
			];
		}

		if ($overageUnits > 0) {
			$lineItems[] = [
				'code'            => 'burst_overage',
				'description'     => sprintf('Burstable overage (%s tariff)', $tariffModel),
				'quantity_units'  => $overageUnits,
				'unit_divisor'    => $unitDivisor,
				'unit_rate_minor' => $overageRate,
				'amount_minor'    => $overageMinor,
			];
		}

		if ($excessTransferBytes > 0) {
			$lineItems[] = [
				'code'            => 'transfer_overage',
				'description'     => 'Data transfer cap overage (TB)',
				'quantity_units'  => $excessTransferBytes,
				'unit_divisor'    => self::BYTES_PER_TB,
				'unit_rate_minor' => $tbOverageRate,
				'amount_minor'    => $transferMinor,
			];
		}

		$totalMinor = $baseMinor + $overageMinor + $transferMinor;

		return [
			'currency'               => $currency,
			'base_amount_minor'      => $baseMinor,
			'overage_amount_minor'   => $overageMinor,
			'transfer_amount_minor'  => $transferMinor,
			'total_amount_minor'     => $totalMinor,
			'overage_units'          => $overageUnits,
			'excess_transfer_bytes'  => $excessTransferBytes,
			'prorata_active_seconds' => $activeSeconds,
			'prorata_period_seconds' => $periodSeconds,
			'line_items'             => $lineItems,
		];
	}

	/**
	 * Overflow-safe exact integer calculation of round_half_up((rateMinor * quantity) / divisor).
	 *
	 * @param int $rateMinor Integer minor-unit rate.
	 * @param int $quantity Non-negative integer quantity.
	 * @param int $divisor Positive integer divisor.
	 * @return int Exact integer minor-unit amount.
	 */
	public static function scaleMinorUnits(int $rateMinor, int $quantity, int $divisor): int {
		if ($divisor <= 0) {
			throw new BillingException('Divisor must be positive.');
		}

		if ($rateMinor === 0 || $quantity <= 0) {
			return 0;
		}

		$sign      = $rateMinor < 0 ? -1 : 1;
		$absRate   = abs($rateMinor);
		$whole     = intdiv($quantity, $divisor);
		$remainder = $quantity % $divisor;

		// Split remainder * absRate safely even when both are large.
		if ($remainder > 0 && $absRate > intdiv(PHP_INT_MAX - $divisor, $remainder)) {
			// Divide both sides by GCD to keep intermediate product well within 64-bit signed int.
			$g     = self::gcd($remainder, $divisor);
			$rNorm = intdiv($remainder, $g);
			$dNorm = intdiv($divisor, $g);
			$frac  = intdiv($rNorm * $absRate + intdiv($dNorm, 2), $dNorm);
		} else {
			$frac = intdiv($remainder * $absRate + intdiv($divisor, 2), $divisor);
		}

		return $sign * (($whole * $absRate) + $frac);
	}

	/**
	 * Rate graduated stepped tiers where each bracket applies only to units inside that bracket.
	 *
	 * @param int $overageUnits Total overage raw units.
	 * @param int $unitDivisor Raw units per billing unit.
	 * @param list<TariffTier> $tiers Sorted tier brackets.
	 * @param int $fallbackRate Fallback minor-unit rate when no tiers are configured.
	 * @return int
	 */
	private static function rateStepped(int $overageUnits, int $unitDivisor, array $tiers, int $fallbackRate): int {
		if ($tiers === []) {
			return self::scaleMinorUnits($fallbackRate, $overageUnits, $unitDivisor);
		}

		$totalMinor = 0;
		$prevBound  = 0;

		foreach ($tiers as $tier) {
			if ($overageUnits <= $prevBound) {
				break;
			}

			$upper       = $tier->upToUnits ?? $overageUnits;
			$sliceUnits  = min($overageUnits, $upper) - $prevBound;

			if ($sliceUnits > 0) {
				$totalMinor += self::scaleMinorUnits($tier->rateMinor, $sliceUnits, $unitDivisor);
				$prevBound   = min($overageUnits, $upper);
			}
		}

		if ($overageUnits > $prevBound) {
			$lastRate    = $tiers[count($tiers) - 1]->rateMinor;
			$totalMinor += self::scaleMinorUnits($lastRate, $overageUnits - $prevBound, $unitDivisor);
		}

		return $totalMinor;
	}

	/**
	 * Rate volume (all-units) tiers where total overage selects one rate applied to all overage units.
	 *
	 * @param int $overageUnits Total overage raw units.
	 * @param int $unitDivisor Raw units per billing unit.
	 * @param list<TariffTier> $tiers Sorted tier brackets.
	 * @param int $fallbackRate Fallback minor-unit rate when no tiers are configured.
	 * @return int
	 */
	private static function rateVolume(int $overageUnits, int $unitDivisor, array $tiers, int $fallbackRate): int {
		if ($tiers === []) {
			return self::scaleMinorUnits($fallbackRate, $overageUnits, $unitDivisor);
		}

		$selectedRate = $tiers[count($tiers) - 1]->rateMinor;

		foreach ($tiers as $tier) {
			if ($tier->upToUnits === null || $overageUnits <= $tier->upToUnits) {
				$selectedRate = $tier->rateMinor;

				break;
			}
		}

		return self::scaleMinorUnits($selectedRate, $overageUnits, $unitDivisor);
	}

	/**
	 * Compute Euclidean greatest common divisor of two positive integers.
	 *
	 * @param int $a First integer.
	 * @param int $b Second integer.
	 * @return int
	 */
	private static function gcd(int $a, int $b): int {
		while ($b !== 0) {
			$t = $a % $b;
			$a = $b;
			$b = $t;
		}

		return max(1, $a);
	}
}
