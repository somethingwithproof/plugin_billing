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
 * Immutable tariff pricing tier defining an upper overage bound and minor-unit rate.
 */
final class TariffTier {
	/**
	 * Construct a TariffTier.
	 *
	 * @param int|null $upToUnits Inclusive upper bound in raw units (null = unbounded top tier).
	 * @param int $rateMinor Minor-unit price per billing unit (divisor-scaled, e.g. cents per Mbps).
	 */
	public function __construct(
		public readonly ?int $upToUnits,
		public readonly int $rateMinor,
	) {
		if ($upToUnits !== null && $upToUnits <= 0) {
			throw new BillingException('Tier up_to_units must be positive or null.');
		}

		if ($rateMinor < 0) {
			throw new BillingException('Tier rate_minor must be non-negative.');
		}
	}

	/**
	 * Parse a JSON string or raw list into a sorted list of TariffTier objects.
	 *
	 * @param string|list<array<string, mixed>> $source JSON string or array of tier definitions.
	 * @return list<self>
	 */
	public static function parseList(string|array $source): array {
		$raw = $source;

		if (is_string($raw)) {
			$trimmed = trim($raw);

			if ($trimmed === '' || $trimmed === '[]') {
				return [];
			}

			$decoded = json_decode($trimmed, true);

			if (!is_array($decoded)) {
				throw new BillingException('Invalid tariff tiers_json payload.');
			}

			$raw = $decoded;
		}

		$tiers = [];

		foreach ($raw as $entry) {
			if (!is_array($entry)) {
				continue;
			}

			$upTo = isset($entry['up_to_units']) && is_numeric($entry['up_to_units'])
				? (int) $entry['up_to_units']
				: null;
			$rate = isset($entry['rate_minor']) && is_numeric($entry['rate_minor'])
				? (int) $entry['rate_minor']
				: 0;

			$tiers[] = new self($upTo, $rate);
		}

		usort(
			$tiers,
			static function (self $a, self $b): int {
				if ($a->upToUnits === null && $b->upToUnits === null) {
					return 0;
				}

				if ($a->upToUnits === null) {
					return 1;
				}

				if ($b->upToUnits === null) {
					return -1;
				}

				return $a->upToUnits <=> $b->upToUnits;
			}
		);

		return $tiers;
	}
}
