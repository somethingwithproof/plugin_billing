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

namespace Somethingwithproof\Billing\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Somethingwithproof\Billing\Ledger\SampleIngestor;
use Somethingwithproof\Billing\Pages;
use Somethingwithproof\Billing\Schema;
use Somethingwithproof\Billing\Settings;

final class MariaDbIntegrationTest extends TestCase {
	public function testSchemaHooksRealmsEndToEndBillingAndIntegrationsOnLiveMariaDb(): void {
		$schema = new Schema(false);
		$this->assertTrue($schema->isCurrent());
		$this->assertTrue($schema->hooksRegistered());

		// Repeated upgrade must be idempotent.
		$this->assertTrue(plugin_billing_upgrade());

		$realmCount = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_realms WHERE plugin = ?', ['billing']);
		$this->assertSame(count(Pages::realms()), $realmCount);

		billing_reset_runtime();
		$repo       = billing_repository();
		$ingestor   = new SampleIngestor($repo);
		$statements = billing_statement_service();

		// End-to-end on live MariaDB: account -> contract -> 2 LAG circuits -> samples -> finalize -> SHA-256 verify.
		$acctId = $repo->upsertAccount([
			'account_code'   => 'CI-CARRIER-1',
			'name'           => 'CI Integration Carrier',
			'external_ref'   => 'ext_ci_01',
			'currency'       => 'USD',
			'connector_type' => 'stripe',
		]);
		$contractId = $repo->upsertContract([
			'account_id'         => $acctId,
			'contract_code'      => 'CI-LAG-20G',
			'name'               => 'CI 2x10G LAG Transit',
			'percentile'         => 95.0,
			'commit_units'       => 500_000_000,
			'unit_divisor'       => 1_000_000,
			'base_fee_minor'     => 250000,
			'overage_rate_minor' => 150,
			'currency'           => 'USD',
		]);
		$c1 = $repo->addCircuit(['contract_id' => $contractId, 'local_data_id' => 1001, 'label' => 'lag-port-1']);
		$c2 = $repo->addCircuit(['contract_id' => $contractId, 'local_data_id' => 1002, 'label' => 'lag-port-2']);

		$start = 1735689600;
		$end   = $start + (100 * 300);

		for ($i = 0; $i < 100; $i++) {
			$ts = $start + ($i * 300);
			$ingestor->ingestDirectSample($c1, $ts, $i < 10 ? 700_000_000 : 100_000_000, 50_000_000);
			$ingestor->ingestDirectSample($c2, $ts, ($i >= 10 && $i < 20) ? 700_000_000 : 100_000_000, 50_000_000);
		}

		// Re-ingesting an existing bucket must preserve immutability.
		$dup = $ingestor->ingestDirectSample($c1, $start, 9_999_999_999, 0);
		$this->assertFalse($dup['inserted']);

		$stmt = $statements->finalize('CI-LAG-20G', $start, $end, 'ci-runner');
		$this->assertSame(800_000_000, (int) $stmt['billable_units']);
		$this->assertSame(295000, (int) $stmt['total_amount_minor']); // 250,000 base + 300 Mbps * 150 = 45,000

		$verified = $statements->verifyIntegrityByCode((string) $stmt['statement_code']);
		$this->assertTrue($verified['valid']);

		// Settings persistence & ecosystem hooks on live MariaDB.
		$updated = Settings::fromRow(['billing_default_currency' => 'EUR', 'billing_outbound_timeout' => 15]);
		$this->assertTrue(billing_save_settings($updated));
		$this->assertSame('EUR', billing_settings()->defaultCurrency);

		$checks = billing_doctor_register_checks(['mode' => 'run', 'plugin' => 'billing']);
		$this->assertCount(3, $checks);

		foreach ($checks as $check) {
			$this->assertSame('ok', $check['status']);
		}

		$debug = billing_doctor_register_debug_info(['plugin' => 'billing']);
		$this->assertCount(1, $debug);
		$this->assertSame('billing.overview', $debug[0]['id']);

		$resources = billing_api_register_resources([]);
		$this->assertCount(3, $resources);
		$this->assertSame('billingAccounts', $resources[0]['collection']);

		$ssOut = ss_billing_stats();
		$this->assertStringContainsString('accounts_active:1', $ssOut);
		$this->assertStringContainsString('statements_finalized:1', $ssOut);

		// Clean up CI test rows.
		db_execute_prepared('DELETE FROM plugin_billing_statement WHERE contract_id = ?', [$contractId]);
		db_execute_prepared('DELETE FROM plugin_billing_sample WHERE circuit_id IN (?, ?)', [$c1, $c2]);
		db_execute_prepared('DELETE FROM plugin_billing_circuit WHERE contract_id = ?', [$contractId]);
		db_execute_prepared('DELETE FROM plugin_billing_contract WHERE id = ?', [$contractId]);
		db_execute_prepared('DELETE FROM plugin_billing_account WHERE id = ?', [$acctId]);
	}
}
