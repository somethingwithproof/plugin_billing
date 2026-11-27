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
 * Three-tier RBAC realms, Console menu entries, and navigation breadcrumbs for plugin_billing.
 */
final class Pages {
	/**
	 * Customer usage and mid-cycle burst forecast page filename.
	 */
	public const VIEW = 'billing_view.php';

	/**
	 * Operator statement audit, integrity verification, dry-run rating, and export page filename.
	 */
	public const OPERATOR = 'billing_operator.php';

	/**
	 * Administrator accounts, contracts, tariffs, circuits, and ERP connector page filename.
	 */
	public const ADMIN = 'billing_admin.php';

	/**
	 * Return Cacti realm definitions in ascending privilege order (View, Operator, Admin).
	 *
	 * @return list<array{name: string, files: list<string>, default: bool}>
	 */
	public static function realms(): array {
		return [
			['name' => __('Billing View', 'billing'), 'files' => [self::VIEW], 'default' => false],
			['name' => __('Billing Operator', 'billing'), 'files' => [self::OPERATOR], 'default' => false],
			['name' => __('Billing Admin', 'billing'), 'files' => [self::ADMIN], 'default' => true],
		];
	}

	/**
	 * Return the ordered list of plugin web page filenames.
	 *
	 * @return list<string>
	 */
	public static function files(): array {
		return [self::VIEW, self::OPERATOR, self::ADMIN];
	}

	/**
	 * Return Console sidebar menu entries keyed by plugin-relative page filename.
	 *
	 * @return array<string, string>
	 */
	public static function menu(): array {
		return [
			self::VIEW     => __('Billing Usage & Forecasts', 'billing'),
			self::OPERATOR => __('Billing Statements & Audit', 'billing'),
			self::ADMIN    => __('Billing Accounts & Tariffs', 'billing'),
		];
	}

	/**
	 * Return page filenames that trigger an automatic schema and hook currency check.
	 *
	 * @return list<string>
	 */
	public static function upgradeTriggers(): array {
		return array_merge(['plugins.php'], self::files());
	}

	/**
	 * Return Cacti navigation breadcrumb entries for all plugin pages.
	 *
	 * @return array<string, array{title: string, mapping: string, url: string, level: string}>
	 */
	public static function navigation(): array {
		$nav = [];

		foreach (self::menu() as $file => $title) {
			$nav[$file . ':'] = [
				'title'   => $title,
				'mapping' => 'index.php:',
				'url'     => $file,
				'level'   => '1',
			];
		}

		return $nav;
	}
}
