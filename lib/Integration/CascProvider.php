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

namespace Somethingwithproof\Billing\Integration;

use Somethingwithproof\Billing\Config\ConfigSchema;
use Somethingwithproof\Billing\Settings;

/**
 * Decoupled plain-array provider for the casc_register_providers hook (slug: billing).
 * Exports declarative billing Config only and never touches immutable State tables
 * (plugin_billing_sample, plugin_billing_statement, plugin_billing_export_log) or secrets.
 */
final class CascProvider {
	/**
	 * Plugin slug registered with configuration-as-code providers.
	 */
	public const SLUG = 'billing';

	/**
	 * Register the billing Config provider on a duck-typed registrar.
	 *
	 * @param object $registrar Registrar supplied by casc_register_providers.
	 * @param callable(): Settings $settingsProvider Returns live plugin settings.
	 * @param callable(Settings): bool $settingsWriter Persists updated plugin settings.
	 * @return bool True when registration succeeded.
	 */
	public static function register(
		object $registrar,
		callable $settingsProvider,
		callable $settingsWriter,
	): bool {
		if (!method_exists($registrar, 'register')) {
			return false;
		}

		$registrar->register(self::descriptor($settingsProvider, $settingsWriter));

		return true;
	}

	/**
	 * Build the plain-array CasC provider descriptor for plugin_billing.
	 *
	 * @param callable(): Settings $settingsProvider Returns live plugin settings.
	 * @param callable(Settings): bool $settingsWriter Persists updated plugin settings.
	 * @return array{
	 *   slug: string,
	 *   schemaVersion: string,
	 *   export: callable(array<string, mixed>): array<string, bool|float|int|string>,
	 *   validate: callable(array<string, mixed>): list<string>,
	 *   plan: callable(array<string, mixed>, array<string, mixed>): list<array<string, mixed>>,
	 *   apply: callable(array<string, mixed>, string, array<string, mixed>): array<string, mixed>
	 * }
	 */
	public static function descriptor(callable $settingsProvider, callable $settingsWriter): array {
		return [
			'slug'          => self::SLUG,
			'schemaVersion' => ConfigSchema::SCHEMA_VERSION,
			'export'        => static function (array $options = []) use ($settingsProvider): array {
				return $settingsProvider()->toConfigArray();
			},
			'validate'      => static function (array $desired): array {
				return ConfigSchema::validate($desired);
			},
			'plan'          => static function (array $desired, array $options = []) use ($settingsProvider): array {
				$errors = ConfigSchema::validate($desired);

				if ($errors !== []) {
					return array_map(
						static fn (string $msg): array => [
							'operation' => 'error',
							'key'       => self::SLUG,
							'message'   => $msg,
						],
						$errors
					);
				}

				$plan = ConfigSchema::plan($settingsProvider()->toConfigArray(), $desired);

				return $plan['actions'];
			},
			'apply'         => static function (array $desired, string $planHash, array $options = []) use ($settingsProvider, $settingsWriter): array {
				$errors = ConfigSchema::validate($desired);

				if ($errors !== []) {
					return [
						'ok'       => false,
						'planHash' => $planHash,
						'errors'   => $errors,
					];
				}

				$current = $settingsProvider()->toConfigArray();
				$next    = Settings::fromRow(array_merge($current, $desired));
				$written = $settingsWriter($next);

				return [
					'ok'       => $written,
					'planHash' => $planHash,
					'updated'  => array_keys($desired),
				];
			},
		];
	}
}
