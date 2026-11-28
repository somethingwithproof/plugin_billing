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

namespace Somethingwithproof\Billing\Ui;

/**
 * Synchronizer-token CSRF guard protecting Operator and Admin POST forms
 * alongside Cacti's built-in csrf-magic layer.
 */
final class CsrfGuard {
	/**
	 * Session key storing the per-session plugin_billing CSRF token.
	 */
	public const SESSION_KEY = 'plugin_billing_csrf_token';

	/**
	 * HTML form field name carrying the CSRF token.
	 */
	public const FIELD_NAME = 'billing_csrf_token';

	/**
	 * Return or lazily generate the active session's 256-bit hex CSRF token.
	 *
	 * @param array<string, mixed> $session Session array reference.
	 * @return string 64-character lowercase hex token.
	 */
	public static function token(array &$session): string {
		$existing = $session[self::SESSION_KEY] ?? null;

		if (is_string($existing) && strlen($existing) === 64 && ctype_xdigit($existing)) {
			return $existing;
		}

		$token                      = bin2hex(random_bytes(32));
		$session[self::SESSION_KEY] = $token;

		return $token;
	}

	/**
	 * Render an HTML hidden input element carrying the active CSRF token.
	 *
	 * @param array<string, mixed> $session Session array reference.
	 * @return string Safe HTML hidden input element.
	 */
	public static function hiddenField(array &$session): string {
		$token = self::token($session);

		return '<input type="hidden" name="' . self::FIELD_NAME . '" value="' . html_escape($token) . '">';
	}

	/**
	 * Verify a submitted CSRF token in constant time.
	 *
	 * @param array<string, mixed> $session Session array.
	 * @param string $submitted Token submitted in the POST payload.
	 * @return bool
	 */
	public static function verify(array $session, string $submitted): bool {
		$expected = $session[self::SESSION_KEY] ?? null;

		if (!is_string($expected) || strlen($expected) !== 64 || !ctype_xdigit($expected)) {
			return false;
		}

		if ($submitted === '' || strlen($submitted) !== 64 || !ctype_xdigit($submitted)) {
			return false;
		}

		return hash_equals($expected, $submitted);
	}
}
