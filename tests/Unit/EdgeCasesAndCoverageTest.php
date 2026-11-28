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

namespace Somethingwithproof\Billing\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Somethingwithproof\Billing\Cli\CliRunner;
use Somethingwithproof\Billing\Config\ConfigSchema;
use Somethingwithproof\Billing\Connector\ConnectorPayloadBuilder;
use Somethingwithproof\Billing\Connector\CsvStatementExporter;
use Somethingwithproof\Billing\Connector\WebhookDispatcher;
use Somethingwithproof\Billing\Db\CactiDbAdapter;
use Somethingwithproof\Billing\Db\PdoDbAdapter;
use Somethingwithproof\Billing\Integration\ApiResources;
use Somethingwithproof\Billing\Integration\CascProvider;
use Somethingwithproof\Billing\Integration\DoctorChecks;
use Somethingwithproof\Billing\Integration\McpAbilities;
use Somethingwithproof\Billing\Ledger\BillingException;
use Somethingwithproof\Billing\Ledger\BillingRepository;
use Somethingwithproof\Billing\Ledger\SampleIngestor;
use Somethingwithproof\Billing\Log;
use Somethingwithproof\Billing\Pages;
use Somethingwithproof\Billing\Rating\BurstForecaster;
use Somethingwithproof\Billing\Rating\PercentileCalculator;
use Somethingwithproof\Billing\Rating\PercentileResult;
use Somethingwithproof\Billing\Rating\TariffRater;
use Somethingwithproof\Billing\Rating\TariffTier;
use Somethingwithproof\Billing\Schema;
use Somethingwithproof\Billing\Security\OutboundGuard;
use Somethingwithproof\Billing\Security\SecretVault;
use Somethingwithproof\Billing\Settings;
use Somethingwithproof\Billing\Statement\StatementProof;
use Somethingwithproof\Billing\Statement\StatementService;
use Somethingwithproof\Billing\Ui\PagesRenderer;

final class EdgeCasesAndCoverageTest extends TestCase {
	protected function tearDown(): void {
		$GLOBALS['__billing_db_mock']        = null;
		$GLOBALS['__billing_options']        = [];
		$GLOBALS['__billing_enabled']        = true;
		$GLOBALS['__billing_allowed_realms'] = [];
		$GLOBALS['__billing_current_page']   = 'index.php';
		$GLOBALS['__billing_hooks']          = [];
		$GLOBALS['__billing_realms']         = [];
		$GLOBALS['__billing_tables']         = [];
		billing_reset_runtime();
	}

	public function testSchemaLifecycleCactiDbAdapterAndSetupHooks(): void {
		$state = [
			'schema_version' => 0,
			'plugin_version' => '0.0.1',
			'plugin_status'  => 1,
			'hooks_count'    => 0,
			'realms_count'   => 0,
			'table_exists'   => 1,
			'direct_realm'   => 0,
			'group_realm'    => 1,
		];

		$GLOBALS['__billing_db_mock'] = static function (string $op, string $sql, array $params) use (&$state): mixed {
			if ($op === 'execute') {
				if (str_contains($sql, 'REPLACE INTO settings') && ($params[0] ?? '') === 'billing_schema_version') {
					$state['schema_version'] = (int) ($params[1] ?? 0);
				}

				if (str_contains($sql, 'UPDATE plugin_config')) {
					$state['plugin_version'] = (string) ($params[0] ?? '');
				}

				return true;
			}

			if ($op === 'cell') {
				if (str_contains($sql, 'FROM settings')) {
					return $state['schema_version'];
				}

				if (str_contains($sql, 'SELECT version FROM plugin_config')) {
					return $state['plugin_version'];
				}

				if (str_contains($sql, 'SELECT status FROM plugin_config')) {
					return $state['plugin_status'];
				}

				if (str_contains($sql, 'FROM plugin_hooks')) {
					return $state['hooks_count'];
				}

				if (str_contains($sql, 'SELECT COUNT(*) FROM plugin_realms')) {
					return $state['realms_count'];
				}

				if (str_contains($sql, 'information_schema.TABLES')) {
					return $state['table_exists'];
				}

				if (str_contains($sql, 'SELECT id FROM plugin_realms')) {
					return 5;
				}

				if (str_contains($sql, 'FROM user_auth_realm')) {
					return $state['direct_realm'];
				}

				if (str_contains($sql, 'FROM user_auth_group_realm')) {
					return $state['group_realm'];
				}

				return 1;
			}

			if ($op === 'row') {
				return ['id' => 1, 'account_code' => 'A1'];
			}

			if ($op === 'assoc') {
				return [['id' => 1, 'account_code' => 'A1']];
			}

			if ($op === 'insert_id') {
				return 42;
			}

			if ($op === 'affected') {
				return 3;
			}

			return null;
		};

		// Fresh install & upgrade lifecycle.
		plugin_billing_install();
		$this->assertCount(6, $GLOBALS['__billing_tables']);
		$this->assertCount(count(Schema::HOOKS), $GLOBALS['__billing_hooks']);
		$this->assertCount(count(Pages::realms()), $GLOBALS['__billing_realms']);
		$this->assertTrue(plugin_billing_check_config());

		// Already current shortcut.
		$state['hooks_count']  = count(Schema::HOOKS);
		$state['realms_count'] = count(Pages::realms());
		$this->assertTrue(plugin_billing_upgrade());

		// Downgrade refusal.
		$state['schema_version'] = 99;
		$schema                  = new Schema(false);
		$this->assertFalse($schema->migrate());

		// Table creation failure path.
		$state['schema_version'] = 0;
		$state['table_exists']   = 0;
		$this->assertFalse($schema->migrate());

		// Uninstall keep vs forget.
		$GLOBALS['__billing_options']['billing_uninstall_keep_tables'] = 'on';
		plugin_billing_uninstall();
		$GLOBALS['__billing_options']['billing_uninstall_keep_tables'] = '';
		plugin_billing_uninstall();

		// CactiDbAdapter methods.
		$adapter = new CactiDbAdapter(false);
		$this->assertTrue($adapter->execute('UPDATE x SET a = ?', [1]));
		$this->assertSame(3, $adapter->affectedRows());
		$this->assertSame(42, $adapter->lastInsertId());
		$this->assertSame(['id' => 1, 'account_code' => 'A1'], $adapter->fetchRow('SELECT * FROM x'));
		$this->assertCount(1, $adapter->fetchAssoc('SELECT * FROM x'));
		$this->assertSame(1, $adapter->fetchCell('SELECT 1'));

		// Save settings & Console hooks.
		$this->assertTrue(billing_save_settings(Settings::fromRow(['billing_default_currency' => 'CAD'])));
		$GLOBALS['menu']                   = [];
		$GLOBALS['tabs']                   = [];
		$GLOBALS['settings']               = [];
		$GLOBALS['current_user']           = ['enabled' => 'on'];
		$GLOBALS['__billing_current_page'] = 'settings.php';
		billing_config_arrays();
		billing_config_settings();
		$nav = billing_draw_navigation_text([]);
		$this->assertArrayHasKey('billing_view.php:', $nav);
		$this->assertArrayHasKey('billing', $GLOBALS['tabs']);

		// Realm permission via group realm and direct realm.
		$this->assertTrue(McpAbilities::userHasViewRealm(7));
		$state['direct_realm'] = 1;
		$this->assertTrue(McpAbilities::userHasViewRealm(7));
		$this->assertFalse(McpAbilities::userHasViewRealm(0));

		// Page guard when plugin is disabled renders notice cleanly.
		$GLOBALS['__billing_enabled'] = false;
		ob_start();
		$allowed = billing_page_start();
		$out     = (string) ob_get_clean();
		$this->assertFalse($allowed);
		$this->assertStringContainsString('not enabled', $out);

		// Log methods.
		Log::info('test info');
		Log::warning('test warn');
		Log::error('test err');
		$this->assertNotEmpty($GLOBALS['__billing_log']);
	}

	public function testNonBandwidthMetricsErrorPathsAndPollerPass(): void {
		$repo     = new BillingRepository(PdoDbAdapter::createSqliteMemory());
		$ingestor = new SampleIngestor($repo);
		$calc     = new PercentileCalculator();
		billing_reset_runtime($repo);

		$acctId     = $repo->upsertAccount(['account_code' => 'COLO-1', 'name' => 'Colocation Power', 'currency' => 'EUR']);
		$contractId = $repo->upsertContract([
			'account_id'         => $acctId,
			'contract_code'      => 'PWR-RACK-1',
			'name'               => 'Rack Power Metering',
			'metric_type'        => 'power',
			'direction_mode'     => 'max_in_out',
			'percentile'         => 95.0,
			'commit_units'       => 50000,
			'unit_divisor'       => 1000000,
			'base_fee_minor'     => 50000,
			'overage_rate_minor' => 15,
			'currency'           => 'EUR',
		]);
		$cid = $repo->addCircuit(['contract_id' => $contractId, 'local_data_id' => 900, 'label' => 'pdu-a']);

		$now        = time();
		$monthStart = (int) gmmktime(0, 0, 0, (int) gmdate('n', $now), 1, (int) gmdate('Y', $now));

		for ($i = 0; $i < 25; $i++) {
			$ingestor->ingestDirectSample($cid, $monthStart + ($i * 300), 0, 0, 120000, 'ok');
		}

		// Power metric with exclude, zero_fill, carry_forward, and circuit active windows.
		$samples = $repo->fetchSamplesByCircuit([$cid], $monthStart, $monthStart + (30 * 300));
		$resEx   = $calc->compute($samples, $monthStart, $monthStart + (30 * 300), 95.0, 'max_in_out', 'floor', 'exclude', [], [$cid => ['active_from' => $monthStart, 'active_until' => $monthStart + (20 * 300)]], 0, 0, 'power');
		$resZero = $calc->compute($samples, $monthStart, $monthStart + (30 * 300), 95.0, 'max_in_out', 'floor', 'zero_fill', [], [], 0, 0, 'power');
		$resCf   = $calc->compute($samples, $monthStart, $monthStart + (30 * 300), 95.0, 'max_in_out', 'floor', 'carry_forward', [], [], 0, 0, 'power');
		$this->assertSame(20, $resEx->effectiveSamples);
		$this->assertSame(30, $resZero->effectiveSamples);
		$this->assertSame(120000, $resCf->billableValue);

		// Poller bottom runs burst check cleanly.
		billing_poller_bottom();
		$this->assertStringContainsString('accounts_active:1', ss_billing_stats());

		// Upsert existing account and contract updates cleanly.
		$this->assertSame($acctId, $repo->upsertAccount(['account_code' => 'COLO-1', 'name' => 'Colocation Power Updated', 'currency' => 'EUR']));
		$this->assertSame($contractId, $repo->upsertContract(['account_id' => $acctId, 'contract_code' => 'PWR-RACK-1', 'name' => 'Rack Power Updated', 'metric_type' => 'power', 'currency' => 'EUR']));
		$this->assertNotNull($repo->findCircuitById($cid));

		// TariffTier & TariffRater edge cases (empty tiers fallback, large remainder GCD path, multiple tiers).
		$tiers = TariffTier::parseList([
			['up_to_units' => null, 'rate_minor' => 100],
			['up_to_units' => 500, 'rate_minor' => 200],
			['up_to_units' => 100, 'rate_minor' => 300],
		]);
		$this->assertSame(100, $tiers[0]->upToUnits);
		$this->assertNull($tiers[2]->upToUnits);
		$this->assertSame(0, TariffRater::scaleMinorUnits(0, 100, 10));
		$this->assertSame(-25, TariffRater::scaleMinorUnits(-50, 5, 10));

		$rater = new TariffRater();
		$pct   = new PercentileResult(500, 0, 0, 10, 10, 0, 0, 0, []);
		$fb    = $rater->rate(['tariff_model' => 'stepped', 'commit_units' => 0, 'unit_divisor' => 100, 'overage_rate_minor' => 20, 'tiers_json' => '[]'], $pct, 100, 200);
		$this->assertSame(100, $fb['overage_amount_minor']);
		$fbVol = $rater->rate(['tariff_model' => 'volume', 'commit_units' => 0, 'unit_divisor' => 100, 'overage_rate_minor' => 20, 'tiers_json' => '[]'], $pct, 100, 200);
		$this->assertSame(100, $fbVol['overage_amount_minor']);
	}

	public function testConnectorsIntegrationsAndErrorBranches(): void {
		$repo     = new BillingRepository(PdoDbAdapter::createSqliteMemory());
		$guard    = new OutboundGuard(5, 4096, static fn (string $h): array => ['127.0.0.1']);
		$disp     = new WebhookDispatcher($guard, $repo);
		$acct     = ['account_code' => 'A1', 'connector_type' => 'webhook', 'webhook_url' => 'https://127.0.0.1/hook'];
		$stmt     = ['id' => 1, 'statement_code' => 'S1', 'currency' => 'USD', 'total_amount_minor' => 100, 'snapshot_json' => '{"line_items":[]}'];
		$blocked  = $disp->dispatchStatement($acct, $stmt, 'secret_key_123', 1735700000);
		$this->assertFalse($blocked['ok']);
		$this->assertStringContainsString('blocked', $blocked['error']);

		// DoctorChecks warning (1 failed export) & critical (missing keyfile) & ok (valid keyfile).
		$keyfile = tempnam(sys_get_temp_dir(), 'billing_kf_');
		$this->assertIsString($keyfile);
		file_put_contents($keyfile, random_bytes(32));

		try {
			$checksOk = DoctorChecks::buildChecks(
				static fn (): BillingRepository => $repo,
				static fn (): Settings => new Settings(secretKeyfile: $keyfile),
				'run'
			);
			$this->assertSame('warn', $checksOk[1]['status']);
			$this->assertSame('ok', $checksOk[2]['status']);

			$checksMissing = DoctorChecks::buildChecks(
				static fn (): BillingRepository => $repo,
				static fn (): Settings => new Settings(secretKeyfile: '/nonexistent/path/key.bin'),
				'run'
			);
			$this->assertSame('fail', $checksMissing[2]['status']);

			$vault = SecretVault::fromKeyfile($keyfile);
			$this->assertSame('hello', $vault->decrypt($vault->encrypt('hello')));
		} finally {
			@unlink($keyfile);
		}

		// CasC provider plan/apply error and valid branches.
		$live = Settings::fromRow([]);
		$desc = CascProvider::descriptor(
			static function () use (&$live): Settings {
				return $live;
			},
			static function (Settings $s) use (&$live): bool {
				$live = $s;

				return true;
			}
		);
		$badPlan = ($desc['plan'])(['unknown_key' => 1], []);
		$this->assertSame('error', $badPlan[0]['operation']);
		$goodPlan = ($desc['plan'])(['billing_default_currency' => 'JPY'], []);
		$this->assertSame('update', $goodPlan[0]['operation']);
		$badApply = ($desc['apply'])(['unknown_key' => 1], 'hash', []);
		$this->assertFalse($badApply['ok']);
		$goodApply = ($desc['apply'])(['billing_default_currency' => 'JPY'], 'hash', []);
		$this->assertTrue($goodApply['ok']);
		$this->assertSame('JPY', $live->defaultCurrency);

		// ApiResources get() for missing records returns null.
		$resources = ApiResources::build(static fn (): BillingRepository => $repo);
		$this->assertNull(($resources[0]['handlers']['get'])('NO-SUCH'));
		$this->assertNull(($resources[1]['handlers']['get'])('NO-SUCH'));
		$this->assertNull(($resources[2]['handlers']['get'])('NO-SUCH'));

		// StatementProof malformed cases.
		$badDigest = StatementProof::verify(['proof_sha256' => 'short', 'snapshot_json' => '{}']);
		$this->assertFalse($badDigest['valid']);
		$badSnap = StatementProof::verify(['proof_sha256' => str_repeat('a', 64), 'snapshot_json' => '{not-json']);
		$this->assertFalse($badSnap['valid']);
		$svc     = new StatementService($repo);
		$missing = $svc->verifyIntegrityByCode('NO-STMT');
		$this->assertFalse($missing['valid']);

		// CSV quoting for commas & quotes.
		$encoded = CsvStatementExporter::encodeRow(['normal', 'a,b', 'say "hi"', '']);
		$this->assertSame('normal,"a,b","say ""hi""",', $encoded);

		// PagesRenderer with banners and non-empty rows.
		$html = PagesRenderer::renderViewDashboard(
			[['contract_code' => 'C1', 'commit_units' => 100, 'current_percentile_units' => 90, 'consumed_burst_slots' => 5, 'burst_budget_slots' => 432, 'remaining_burst_minutes' => 2135, 'currency' => 'USD', 'projected_overage_minor' => 0, 'severity' => 'ok']],
			$repo->operationalCounts()
		);
		$this->assertStringContainsString('C1', $html);

		// Settings coercion edge cases.
		$coerced = Settings::fromRow([
			'billing_default_drop_rounding' => 'invalid',
			'billing_default_gap_policy'    => 'invalid',
			'billing_default_currency'      => '123',
			'billing_burst_warning_pct'     => 95,
			'billing_burst_critical_pct'    => 50,
			'billing_uninstall_keep_tables' => false,
		]);
		$this->assertSame('floor', $coerced->defaultDropRounding);
		$this->assertSame('exclude', $coerced->defaultGapPolicy);
		$this->assertSame('USD', $coerced->defaultCurrency);
		$this->assertFalse($coerced->uninstallKeepTables);

		// Validation error checks across SampleIngestor, SecretVault, ConfigSchema, CliRunner.
		$this->assertNotEmpty(ConfigSchema::validate([
			'billing_default_percentile'    => 10.0,
			'billing_default_drop_rounding' => 'bad',
			'billing_default_gap_policy'    => 'bad',
			'billing_default_currency'      => 'us',
			'billing_spike_multiplier'      => 99.0,
			'billing_outbound_timeout'      => 999,
			'billing_max_response_bytes'    => 10,
			'billing_burst_warning_pct'     => 5,
			'billing_burst_critical_pct'    => 5,
			'billing_uninstall_keep_tables' => 'yes',
		]));

		$runner = new CliRunner(
			$repo,
			$svc,
			new BurstForecaster(),
			new ConnectorPayloadBuilder(),
			static fn (): Settings => Settings::fromRow([]),
			static fn (Settings $s): bool => true
		);
		$this->assertSame(1, $runner->run(['rate', '--contract=NOPE', '--start=100', '--end=200'])['exitCode']);
		$this->assertSame(1, $runner->run(['validate', '--file=/nonexistent/cfg.json'])['exitCode']);

		// Exception branches.
		foreach ([
			static fn (): mixed => SampleIngestor::alignBucket(-1),
			static fn (): mixed => SampleIngestor::alignBucket(100, 0),
			static fn (): mixed => SampleIngestor::computeCounterRate(0, 10, 0),
			static fn (): mixed => SampleIngestor::computeCounterRate(-1, 10, 300),
			static fn (): mixed => SampleIngestor::computeCounterRate('not-a-number', 10, 300),
			static fn (): mixed => SampleIngestor::computeCounterRate('99999999999999999999', 10, 300),
			static fn (): mixed => SampleIngestor::computeCounterRate(4294967296, 10, 300, 0, 1.5, false),
			static fn (): mixed => SampleIngestor::toResourceMicroUnits(-1.0, 'kwh'),
			static fn (): mixed => SampleIngestor::toResourceMicroUnits(1.0, 'unknown'),
			static fn (): mixed => new SecretVault('too-short'),
			static fn (): mixed => SecretVault::fromKeyfile('/does/not/exist'),
			static fn (): mixed => (new ConnectorPayloadBuilder())->build('invalid', [], []),
			static fn (): mixed => WebhookDispatcher::sign('{}', '', 100),
			static fn (): mixed => new TariffTier(0, 100),
			static fn (): mixed => new TariffTier(100, -1),
		] as $fn) {
			try {
				$fn();
				$this->fail('Expected BillingException to be thrown.');
			} catch (BillingException) {
				$this->addToAssertionCount(1);
			}
		}
	}
}
