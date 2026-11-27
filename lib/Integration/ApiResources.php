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

use Somethingwithproof\Billing\Ledger\BillingRepository;
use Somethingwithproof\Billing\Pages;

/**
 * Plain-array resource descriptors for the api_register_resources hook
 * exposing read-only billingAccounts, billingContracts, and billingStatements
 * collections with secrets stripped.
 */
final class ApiResources {
	/**
	 * Build plain-list API resource descriptors following the canonical
	 * api_register_resources contract (plugin, collection, realm_read, handlers).
	 *
	 * @param callable(): BillingRepository $repoFactory Lazy BillingRepository provider.
	 * @return list<array<string, mixed>>
	 */
	public static function build(callable $repoFactory): array {
		return [
			[
				'plugin'      => 'billing',
				'collection'  => 'billingAccounts',
				'realm_read'  => Pages::VIEW,
				'readOnly'    => true,
				'description' => 'Configured customer billing accounts (redacted of connector secrets).',
				'handlers'    => [
					'list' => static function () use ($repoFactory): array {
						return array_map([self::class, 'redactAccount'], $repoFactory()->listAccounts());
					},
					'get' => static function (string $id) use ($repoFactory): ?array {
						$row = $repoFactory()->findAccountByCode($id);

						return $row !== null ? self::redactAccount($row) : null;
					},
				],
			],
			[
				'plugin'      => 'billing',
				'collection'  => 'billingContracts',
				'realm_read'  => Pages::VIEW,
				'readOnly'    => true,
				'description' => 'Burstable 95th-percentile and metered resource billing contracts.',
				'handlers'    => [
					'list' => static function () use ($repoFactory): array {
						return $repoFactory()->listContracts();
					},
					'get' => static function (string $id) use ($repoFactory): ?array {
						return $repoFactory()->findContractByCode($id);
					},
				],
			],
			[
				'plugin'      => 'billing',
				'collection'  => 'billingStatements',
				'realm_read'  => Pages::VIEW,
				'readOnly'    => true,
				'description' => 'Immutable finalized billing statements with SHA-256 dispute proof digests.',
				'handlers'    => [
					'list' => static function () use ($repoFactory): array {
						return $repoFactory()->listStatements();
					},
					'get' => static function (string $id) use ($repoFactory): ?array {
						return $repoFactory()->findStatementByCode($id);
					},
				],
			],
		];
	}

	/**
	 * Strip encrypted connector secret columns from an account record before API exposure.
	 *
	 * @param array<string, mixed> $row Raw plugin_billing_account row.
	 * @return array<string, mixed>
	 */
	private static function redactAccount(array $row): array {
		unset($row['secret_ciphertext'], $row['webhook_url']);

		return $row;
	}
}
