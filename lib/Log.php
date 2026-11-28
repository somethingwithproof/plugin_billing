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

namespace Somethingwithproof\Billing;

/**
 * Structured logger wrapper emitting entries through Cacti's cacti_log() facility.
 */
final class Log {
	/**
	 * Cacti log environment identifier for plugin_billing entries.
	 */
	private const ENVIRON = 'BILLING';

	/**
	 * Emit an informational log entry.
	 *
	 * @param string $message Log message body.
	 * @return void
	 */
	public static function info(string $message): void {
		self::emit('NOTE', $message);
	}

	/**
	 * Emit a warning log entry.
	 *
	 * @param string $message Log message body.
	 * @return void
	 */
	public static function warning(string $message): void {
		self::emit('WARNING', $message);
	}

	/**
	 * Emit an error log entry.
	 *
	 * @param string $message Log message body.
	 * @return void
	 */
	public static function error(string $message): void {
		self::emit('ERROR', $message);
	}

	/**
	 * Forward a formatted severity message to Cacti's logger when available.
	 *
	 * @param string $level Severity prefix (NOTE, WARNING, ERROR).
	 * @param string $message Log message body.
	 * @return void
	 */
	private static function emit(string $level, string $message): void {
		if (function_exists('cacti_log')) {
			cacti_log($level . ': ' . $message, false, self::ENVIRON);
		}
	}
}
