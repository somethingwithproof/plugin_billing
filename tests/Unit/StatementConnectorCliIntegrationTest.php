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
use Somethingwithproof\Billing\Db\PdoDbAdapter;
use Somethingwithproof\Billing\Ledger\BillingException;
use Somethingwithproof\Billing\Ledger\BillingRepository;
use Somethingwithproof\Billing\Ledger\SampleIngestor;
use Somethingwithproof\Billing\Pages;
use Somethingwithproof\Billing\Rating\BurstForecaster;
use Somethingwithproof\Billing\Schema;
use Somethingwithproof\Billing\Security\OutboundGuard;
use Somethingwithproof\Billing\Settings;
use Somethingwithproof\Billing\Statement\StatementService;
use Somethingwithproof\Billing\Ui\CsrfGuard;
use Somethingwithproof\Billing\Ui\PagesRenderer;

final class StatementConnectorCliIntegrationTest extends TestCase {
	private BillingRepository $repo;
	private StatementService $statements;
	private int $periodStart = 1735689600;
	private int $periodEnd   = 1735719600; // 100 buckets (500 min)

	protected function setUp(): void {
		$this->repo       = new BillingRepository(PdoDbAdapter::createSqliteMemory());
		$this->statements = new StatementService($this->repo);
		billing_reset_runtime($this->repo);

		$acctId = $this->repo->upsertAccount([
			'account_code'   => 'ISP-CUST-1',
			'name'           => 'Northwind Fiber',
			'external_ref'   => 'cus_991122',
			'currency'       => 'USD',
			'connector_type' => 'stripe',
			'webhook_url'    => 'https://billing.example.net/webhooks/cacti',
		]);
		$contractId = $this->repo->upsertContract([
			'account_id'         => $acctId,
			'contract_code'      => 'TRANSIT-10G',
			'name'               => '10G Burstable Transit',
			'percentile'         => 95.0,
			'commit_units'       => 500_000_000,
			'unit_divisor'       => 1_000_000,
			'base_fee_minor'     => 100000,
			'overage_rate_minor' => 200,
			'currency'           => 'USD',
		]);
		$circuitId = $this->repo->addCircuit([
			'contract_id'    => $contractId,
			'local_data_id'  => 55,
			'label'          => 'xe-0/0/0',
			'port_speed_bps' => 10_000_000_000,
		]);

		$ingestor = new SampleIngestor($this->repo);

		for ($i = 0; $i < 100; $i++) {
			$bps = $i >= 90 ? 800_000_000 : 400_000_000;
			$ingestor->ingestDirectSample($circuitId, $this->periodStart + ($i * 300), $bps, 100_000_000);
		}
	}

	public function testStatementFinalizationProofVerificationAndTamperDetection(): void {
		$stmt = $this->statements->finalize('TRANSIT-10G', $this->periodStart, $this->periodEnd, 'operator1');

		$this->assertSame('STMT-TRANSIT-10G-202501', $stmt['statement_code']);
		$this->assertSame(800_000_000, (int) $stmt['billable_units']);
		// Commit 500 Mbps, billable 800 Mbps -> 300 Mbps overage @ 200 minor = 60,000 minor + 100,000 base = 160,000 minor ($1,600.00).
		$this->assertSame(160000, (int) $stmt['total_amount_minor']);
		$this->assertSame(64, strlen((string) $stmt['proof_sha256']));

		$ok = $this->statements->verifyIntegrityByCode('STMT-TRANSIT-10G-202501');
		$this->assertTrue($ok['valid']);

		// Tampering with even 1 minor unit must fail SHA-256 verification.
		$tampered                       = $stmt;
		$tampered['total_amount_minor'] = 159999;
		$bad                            = $this->statements->verifyIntegrity($tampered);
		$this->assertFalse($bad['valid']);
		$this->assertStringContainsString('mismatch', $bad['reason']);

		// Re-finalizing same contract & period must be refused.
		$this->expectException(BillingException::class);
		$this->statements->finalize('TRANSIT-10G', $this->periodStart, $this->periodEnd, 'operator1');
	}

	public function testAllSixErpAdaptersFocusExportCsvAndSignedWebhooks(): void {
		$stmt    = $this->statements->finalize('TRANSIT-10G', $this->periodStart, $this->periodEnd, 'cli');
		$account = $this->repo->findAccountByCode('ISP-CUST-1');
		$this->assertNotNull($account);

		$builder = new ConnectorPayloadBuilder();

		foreach (ConnectorPayloadBuilder::CONNECTORS as $type) {
			$payload = $builder->build($type, $account, $stmt);
			$this->assertNotEmpty($payload, 'Empty payload for connector ' . $type);
		}

		$stripe = $builder->build('stripe', $account, $stmt);
		$this->assertSame('usd', $stripe['currency']);
		$this->assertCount(2, $stripe['invoice_items']);

		$focus = $builder->buildFocusExport($account, $stmt);
		$this->assertCount(2, $focus);
		$this->assertSame('1.0', $focus[0]['FocusVersion']);
		$this->assertSame($stmt['proof_sha256'], $focus[0]['x_ProofSha256']);

		$csv = CsvStatementExporter::exportStatements([
			array_merge($stmt, ['account_code' => $account['account_code'], 'account_name' => $account['name']]),
		]);
		$this->assertStringContainsString('STMT-TRANSIT-10G-202501', $csv);
		$this->assertStringContainsString('1600.00', $csv);

		// Signed HTTPS webhook delivery with injectable resolver and transport.
		$guard      = new OutboundGuard(5, 8192, static fn (string $h): array => ['93.184.216.34']);
		$captured   = [];
		$dispatcher = new WebhookDispatcher(
			$guard,
			$this->repo,
			$builder,
			static function (array $validated, array $headers, string $body) use (&$captured): array {
				$captured = ['validated' => $validated, 'headers' => $headers, 'body' => $body];

				return ['status' => 202, 'body' => '{"accepted":true}', 'error' => ''];
			}
		);

		$ts     = 1735720000;
		$secret = 'whsec_test_signing_key_256bit';
		$res    = $dispatcher->dispatchStatement($account, $stmt, $secret, $ts);

		$this->assertTrue($res['ok']);
		$this->assertSame(202, $res['http_status']);
		$this->assertTrue(WebhookDispatcher::verifySignature($captured['body'], $secret, $res['signature'], $ts, 300, $ts + 10));
		$this->assertFalse(WebhookDispatcher::verifySignature($captured['body'], $secret, $res['signature'], $ts, 300, $ts + 600));
		$this->assertCount(1, $this->repo->listExportLogs());
	}

	public function testCliRunnerAllCommandsAndApplyConfirmPlanHash(): void {
		$current = Settings::fromRow([]);
		$runner  = new CliRunner(
			$this->repo,
			$this->statements,
			new BurstForecaster(),
			new ConnectorPayloadBuilder(),
			static function () use (&$current): Settings {
				return $current;
			},
			static function (Settings $next) use (&$current): bool {
				$current = $next;

				return true;
			}
		);

		$health = $runner->run(['health', '--format=json']);
		$this->assertSame(0, $health['exitCode']);
		$this->assertStringContainsString('"accounts_active": 1', $health['stdout']);

		$rate = $runner->run(['rate', '--contract=TRANSIT-10G', '--start=' . $this->periodStart, '--end=' . $this->periodEnd]);
		$this->assertSame(0, $rate['exitCode']);
		$this->assertStringContainsString('"total_amount_minor": 160000', $rate['stdout']);

		$fin = $runner->run(['finalize', '--contract=TRANSIT-10G', '--start=' . $this->periodStart, '--end=' . $this->periodEnd, '--actor=cli-test']);
		$this->assertSame(0, $fin['exitCode']);

		$ver = $runner->run(['verify', '--statement=STMT-TRANSIT-10G-202501', '--format=text']);
		$this->assertSame(0, $ver['exitCode']);
		$this->assertStringContainsString('valid: 1', $ver['stdout']);

		$fc = $runner->run(['forecast', '--contract=TRANSIT-10G', '--start=' . $this->periodStart, '--end=' . $this->periodEnd, '--as-of=' . $this->periodEnd]);
		$this->assertSame(0, $fc['exitCode']);
		$this->assertStringContainsString('"severity": "breached"', $fc['stdout']);

		$expCsv   = $runner->run(['export-usage', '--statement=STMT-TRANSIT-10G-202501', '--format=csv']);
		$expFocus = $runner->run(['export-usage', '--statement=STMT-TRANSIT-10G-202501', '--format=focus']);
		$this->assertSame(0, $expCsv['exitCode']);
		$this->assertSame(0, $expFocus['exitCode']);
		$this->assertStringContainsString('FocusVersion', $expFocus['stdout']);

		$expCfg = $runner->run(['export', '--format=json']);
		$this->assertSame(0, $expCfg['exitCode']);
		$this->assertStringContainsString('billing_default_percentile', $expCfg['stdout']);

		// Validate -> Plan -> Apply with --confirm=<PLAN_HASH>.
		$tmpFile = tempnam(sys_get_temp_dir(), 'billing_cfg_');
		$this->assertIsString($tmpFile);
		file_put_contents($tmpFile, json_encode(['billing_default_currency' => 'EUR', 'billing_outbound_timeout' => 15]));

		try {
			$val = $runner->run(['validate', '--file=' . $tmpFile]);
			$this->assertSame(0, $val['exitCode']);

			$plan     = $runner->run(['plan', '--file=' . $tmpFile]);
			$planData = json_decode($plan['stdout'], true);
			$this->assertIsArray($planData);
			$hash     = (string) $planData['plan_hash'];

			$badApply = $runner->run(['apply', '--file=' . $tmpFile, '--confirm=wronghash']);
			$this->assertSame(2, $badApply['exitCode']);

			$goodApply = $runner->run(['apply', '--file=' . $tmpFile, '--confirm=' . $hash]);
			$this->assertSame(0, $goodApply['exitCode']);
			$this->assertSame('EUR', $current->defaultCurrency);
			$this->assertSame(15, $current->outboundTimeout);
		} finally {
			@unlink($tmpFile);
		}

		$help    = $runner->run(['help']);
		$unknown = $runner->run(['nonexistent']);
		$this->assertSame(0, $help['exitCode']);
		$this->assertSame(2, $unknown['exitCode']);
	}

	public function testSetupHooksRealmsIntegrationsUiAndScriptServer(): void {
		$this->assertTrue(billing_php_supported());
		$this->assertTrue(billing_boot());
		$this->assertSame('billing', plugin_billing_version()['name'] ?? '');
		$this->assertCount(6, Schema::tables());
		$this->assertCount(3, Pages::realms());
		$this->assertNotEmpty(Settings::form());

		// 3-tier realm inheritance: Admin > Operator > View.
		global $user_auth_realm_filenames;
		$user_auth_realm_filenames = [
			'billing_view.php'     => 101,
			'billing_operator.php' => 102,
			'billing_admin.php'    => 103,
		];
		$_SESSION['sess_user_id']          = 1;
		$GLOBALS['__billing_allowed_realms'] = [103];
		$GLOBALS['__billing_current_page']   = 'billing_view.php';
		$this->assertTrue(billing_page_allowed());
		$this->assertSame(103, $user_auth_realm_filenames['billing_view.php']);

		// Decoupled ecosystem hooks (CasC, Doctor, MCP Abilities, API Resources).
		$cascReg = new class() {
			/** @var list<array<string, mixed>> */
			public array $registered = [];
			public function register(array $descriptor): void {
				$this->registered[] = $descriptor;
			}
		};
		billing_casc_register_providers($cascReg);
		$this->assertCount(1, $cascReg->registered);
		$this->assertSame('billing', $cascReg->registered[0]['slug']);
		$this->assertIsArray(($cascReg->registered[0]['export'])([]));
		$this->assertSame([], ($cascReg->registered[0]['validate'])(['billing_default_currency' => 'USD']));

		$checks = billing_doctor_register_checks(['mode' => 'run', 'plugin' => 'billing']);
		$this->assertCount(3, $checks);

		foreach ($checks as $check) {
			$this->assertSame('billing', $check['plugin']);
			$this->assertStringStartsWith('billing.', (string) $check['id']);
			$this->assertSame('ok', $check['status']);
		}

		$listChecks = billing_doctor_register_checks(['mode' => 'list', 'plugin' => 'billing']);
		$this->assertCount(3, $listChecks);
		$this->assertSame('info', $listChecks[0]['status']);

		$debug = billing_doctor_register_debug_info(['plugin' => 'billing']);
		$this->assertCount(1, $debug);
		$this->assertSame('billing.overview', $debug[0]['id']);
		$this->assertSame('[REDACTED]', $debug[0]['fields']['secret_keyfile_value']['value']);

		$mcpReg = new class() {
			/** @var list<string> */
			public array $events = [];
			/** @var array<string, array<string, mixed>> */
			public array $abilities = [];
			public function registerCategory(string $id, string $label): void {
				$this->events[] = 'category:' . $id . ':' . $label;
			}
			/** @param array<string, mixed> $spec */
			public function register(string $name, array $spec): void {
				$this->events[]         = 'ability:' . $name;
				$this->abilities[$name] = $spec;
			}
		};
		billing_abilities_register($mcpReg);
		$this->assertSame('category:billing:Billing', $mcpReg->events[0] ?? null);
		$this->assertSame('ability:billing-list-accounts', $mcpReg->events[1] ?? null);
		$this->assertArrayHasKey('billing-list-accounts', $mcpReg->abilities);
		$this->assertArrayHasKey('billing-get-forecast', $mcpReg->abilities);
		$this->assertArrayHasKey('billing-verify-statement', $mcpReg->abilities);
		$this->assertSame(1000, $mcpReg->abilities['billing-list-accounts']['output_schema']['properties']['accounts']['maxItems']);

		foreach ($mcpReg->abilities as $abilityId => $spec) {
			$this->assertSchemaClosedAndBounded($spec['input_schema'], $abilityId . '.input_schema');
			$this->assertSchemaClosedAndBounded($spec['output_schema'], $abilityId . '.output_schema');
		}

		// Duplicate category LogicException must not abort ability registration.
		$dupMcpReg = new class() {
			/** @var array<string, array<string, mixed>> */
			public array $abilities = [];
			public function registerCategory(string $id, string $label): void {
				throw new \LogicException('Duplicate category');
			}
			/** @param array<string, mixed> $spec */
			public function register(string $name, array $spec): void {
				$this->abilities[$name] = $spec;
			}
		};
		billing_abilities_register($dupMcpReg);
		$this->assertCount(3, $dupMcpReg->abilities);

		$acctList = ($mcpReg->abilities['billing-list-accounts']['execute'])(1, new \stdClass());
		$this->assertCount(1, $acctList['accounts']);

		$resources = billing_api_register_resources([]);
		$this->assertCount(3, $resources);
		$this->assertSame('billingAccounts', $resources[0]['collection']);
		$this->assertSame('billing_view.php', $resources[0]['realm_read']);
		$apiAccts = ($resources[0]['handlers']['list'])();
		$this->assertArrayNotHasKey('secret_ciphertext', $apiAccts[0]);

		// Script server output contains all 8 required Cacti fields.
		$statsOut = ss_billing_stats();
		foreach (['accounts_active:', 'contracts_active:', 'circuits_total:', 'statements_finalized:', 'accounts_over_commit:', 'burst_warning_count:', 'exports_failed:', 'samples_24h:'] as $field) {
			$this->assertStringContainsString($field, $statsOut);
		}

		// UI renderers and CSRF guard.
		$session = [];
		$field   = CsrfGuard::hiddenField($session);
		$token   = CsrfGuard::token($session);
		$this->assertTrue(CsrfGuard::verify($session, $token));
		$this->assertFalse(CsrfGuard::verify($session, 'invalid'));
		$this->assertStringContainsString('Active Accounts', PagesRenderer::renderViewDashboard([], $this->repo->operationalCounts()));
		$this->assertStringContainsString('Finalized Statements', PagesRenderer::renderOperatorConsole($field, [], $this->repo->listContracts(), ['ok'], []));
		$this->assertStringContainsString('Save Account', PagesRenderer::renderAdminConsole($field, Settings::fromRow([]), $this->repo->listAccounts(), $this->repo->listContracts(), [], []));

		// ConfigSchema plan hash determinism.
		$p1 = ConfigSchema::plan(Settings::fromRow([])->toConfigArray(), ['billing_default_currency' => 'GBP']);
		$p2 = ConfigSchema::plan(Settings::fromRow([])->toConfigArray(), ['billing_default_currency' => 'GBP']);
		$this->assertSame($p1['plan_hash'], $p2['plan_hash']);
	}

	/**
	 * Recursively verify every JSON schema object is closed and every array declares items and maxItems.
	 *
	 * @param array<string, mixed> $schema Schema node.
	 * @param string $path Context path for failure messages.
	 * @return void
	 */
	private function assertSchemaClosedAndBounded(array $schema, string $path): void {
		$type = is_array($schema['type'] ?? null) ? ($schema['type'][0] ?? null) : ($schema['type'] ?? null);

		if ($type === 'object') {
			$this->assertArrayHasKey('properties', $schema, $path . ' missing properties');
			$this->assertFalse($schema['additionalProperties'] ?? null, $path . ' must set additionalProperties=false');
			$props = is_array($schema['properties']) ? $schema['properties'] : (array) $schema['properties'];

			foreach ((array) ($schema['required'] ?? []) as $req) {
				$this->assertArrayHasKey((string) $req, $props, $path . ' required field missing from properties: ' . (string) $req);
			}

			foreach ($props as $k => $prop) {
				if (is_array($prop)) {
					$this->assertSchemaClosedAndBounded($prop, $path . '.' . $k);
				}
			}
		}

		if ($type === 'array') {
			$this->assertArrayHasKey('items', $schema, $path . ' missing items');
			$this->assertArrayHasKey('maxItems', $schema, $path . ' missing maxItems');
			$this->assertIsInt($schema['maxItems'], $path . ' maxItems must be int');

			if (is_array($schema['items'])) {
				$this->assertSchemaClosedAndBounded($schema['items'], $path . '[]');
			}
		}
	}
}
