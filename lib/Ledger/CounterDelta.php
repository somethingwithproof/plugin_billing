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
 * Immutable value object representing a computed 5-minute billing sample delta.
 */
final class CounterDelta {
	/**
	 * Construct a CounterDelta result.
	 *
	 * @param int $inBps Computed ingress rate in bits per second.
	 * @param int $outBps Computed egress rate in bits per second.
	 * @param int $resourceUnits Generic metered resource quantity in micro-units (1e-6).
	 * @param string $qualityFlag Quality marker (ok, wrap, clamped, gap, maint).
	 * @param bool $wrapped Whether a 64-bit or 32-bit counter wraparound occurred.
	 * @param bool $clamped Whether the spike sanity cap clamped either direction.
	 */
	public function __construct(
		public readonly int $inBps,
		public readonly int $outBps,
		public readonly int $resourceUnits,
		public readonly string $qualityFlag,
		public readonly bool $wrapped,
		public readonly bool $clamped,
	) {
	}
}
