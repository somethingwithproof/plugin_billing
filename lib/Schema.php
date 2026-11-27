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
 * Plugin schema migrations, hook registration catalog, and uninstall table control.
 */
final class Schema {
	/**
	 * Current database schema version.
	 */
	public const VERSION = 1;

	/**
	 * Cacti plugin hooks registered by plugin_billing.
	 */
	public const HOOKS = [
		'auth_alternate_realms'      => 'billing_auth_alternate_realms',
		'config_arrays'              => 'billing_config_arrays',
		'config_settings'            => 'billing_config_settings',
		'draw_navigation_text'       => 'billing_draw_navigation_text',
		'poller_bottom'              => 'billing_poller_bottom',
		'casc_register_providers'    => 'billing_casc_register_providers',
		'doctor_register_checks'     => 'billing_doctor_register_checks',
		'doctor_register_debug_info' => 'billing_doctor_register_debug_info',
		'abilities_register'         => 'billing_abilities_register',
		'api_register_resources'     => 'billing_api_register_resources',
	];

	/**
	 * Cacti plugin directory slug.
	 */
	private const PLUGIN = 'billing';

	/**
	 * Settings table key tracking installed schema version.
	 */
	private const SETTING = 'billing_schema_version';

	/**
	 * Construct a Schema manager bound to an optional database connection.
	 *
	 * @param \PDO|false $connection Cacti PDO connection or false for default session.
	 */
	public function __construct(private readonly \PDO|false $connection = false) {
	}

	/**
	 * Read the currently recorded plugin schema version from Cacti's settings table.
	 *
	 * @return int Recorded schema version, or 0 when not installed.
	 */
	public function storedVersion(): int {
		$value = db_fetch_cell_prepared(
			'SELECT value FROM settings WHERE name = ?',
			[self::SETTING],
			'',
			true,
			$this->connection
		);

		return is_numeric($value) ? (int) $value : 0;
	}

	/**
	 * Determine whether the database schema matches Schema::VERSION.
	 *
	 * @return bool
	 */
	public function isCurrent(): bool {
		return $this->storedVersion() === self::VERSION;
	}

	/**
	 * Create or migrate all plugin_billing tables idempotently.
	 *
	 * @return bool True when schema is current after migration; false on downgrade or error.
	 */
	public function migrate(): bool {
		$from = $this->storedVersion();

		if ($from > self::VERSION) {
			Log::error(sprintf('Schema version %d is newer than plugin code (%d); refusing downgrade', $from, self::VERSION));

			return false;
		}

		foreach (self::tables() as $table => $definition) {
			api_plugin_db_table_create(self::PLUGIN, $table, $definition);

			if (!$this->tableExists($table)) {
				Log::error(sprintf('Unable to create table %s; schema left at version %d', $table, $from));

				return false;
			}
		}

		db_execute_prepared(
			'REPLACE INTO settings (name, value) VALUES (?, ?)',
			[self::SETTING, (string) self::VERSION],
			true,
			$this->connection
		);

		if ($from !== self::VERSION) {
			Log::info(sprintf('Schema migrated from version %d to %d', $from, self::VERSION));
		}

		return true;
	}

	/**
	 * Read the plugin version currently recorded in Cacti's plugin_config table.
	 *
	 * @return string
	 */
	public function recordedPluginVersion(): string {
		$value = db_fetch_cell_prepared(
			'SELECT version FROM plugin_config WHERE directory = ?',
			[self::PLUGIN],
			'',
			true,
			$this->connection
		);

		return is_scalar($value) ? (string) $value : '';
	}

	/**
	 * Persist plugin metadata into Cacti's plugin_config row.
	 *
	 * @param string $version Plugin version string from INFO.
	 * @param string $author Plugin author string from INFO.
	 * @param string $homepage Plugin homepage URL from INFO.
	 * @return void
	 */
	public function recordPluginVersion(string $version, string $author, string $homepage): void {
		db_execute_prepared(
			'UPDATE plugin_config SET version = ?, author = ?, webpage = ? WHERE directory = ?',
			[$version, $author, $homepage, self::PLUGIN],
			true,
			$this->connection
		);
	}

	/**
	 * Determine whether plugin_billing is currently enabled in plugin_config.
	 *
	 * @return bool
	 */
	public function pluginEnabled(): bool {
		$status = db_fetch_cell_prepared(
			'SELECT status FROM plugin_config WHERE directory = ?',
			[self::PLUGIN],
			'',
			true,
			$this->connection
		);

		return is_numeric($status) && (int) $status === 1;
	}

	/**
	 * Verify that all declared plugin hooks and RBAC realms are registered.
	 *
	 * @return bool
	 */
	public function hooksRegistered(): bool {
		$placeholders = implode(', ', array_fill(0, count(self::HOOKS), '?'));
		$hooks        = db_fetch_cell_prepared(
			"SELECT COUNT(DISTINCT hook) FROM plugin_hooks WHERE name = ? AND hook IN ($placeholders)",
			array_merge([self::PLUGIN], array_keys(self::HOOKS)),
			'',
			true,
			$this->connection
		);
		$realms = db_fetch_cell_prepared(
			'SELECT COUNT(*) FROM plugin_realms WHERE plugin = ?',
			[self::PLUGIN],
			'',
			true,
			$this->connection
		);

		return is_numeric($hooks)
			&& (int) $hooks === count(self::HOOKS)
			&& is_numeric($realms)
			&& (int) $realms === count(Pages::realms());
	}

	/**
	 * Detach plugin tables from plugin_db_changes so Cacti uninstall preserves them.
	 *
	 * @return void
	 */
	public function untrackTables(): void {
		db_execute_prepared(
			"DELETE FROM plugin_db_changes WHERE plugin = ? AND method = 'create'",
			[self::PLUGIN],
			true,
			$this->connection
		);
	}

	/**
	 * Remove the recorded schema version setting during uninstall.
	 *
	 * @return void
	 */
	public function forgetVersion(): void {
		db_execute_prepared(
			'DELETE FROM settings WHERE name = ?',
			[self::SETTING],
			true,
			$this->connection
		);
	}

	/**
	 * Check whether a plugin table exists in the active MariaDB schema.
	 *
	 * @param string $table Unquoted table name.
	 * @return bool
	 */
	private function tableExists(string $table): bool {
		/** @var mixed $count */
		$count = db_fetch_cell_prepared(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
			[$table],
			'',
			true,
			$this->connection
		);

		return is_numeric($count) && (int) $count > 0;
	}

	/**
	 * Return Cacti api_plugin_db_table_create() definitions for all six plugin_billing tables.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function tables(): array {
		return [
			'plugin_billing_account' => [
				'columns' => [
					['name' => 'id', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true],
					['name' => 'account_code', 'type' => 'varchar(64)', 'NULL' => false, 'default' => ''],
					['name' => 'name', 'type' => 'varchar(191)', 'NULL' => false, 'default' => ''],
					['name' => 'external_ref', 'type' => 'varchar(128)', 'NULL' => false, 'default' => ''],
					['name' => 'currency', 'type' => 'char(3)', 'NULL' => false, 'default' => 'USD'],
					['name' => 'status', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'active'],
					['name' => 'connector_type', 'type' => 'varchar(32)', 'NULL' => false, 'default' => 'webhook'],
					['name' => 'webhook_url', 'type' => 'varchar(512)', 'NULL' => false, 'default' => ''],
					['name' => 'secret_ciphertext', 'type' => 'text', 'NULL' => false],
					['name' => 'created_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'updated_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
				],
				'primary'     => 'id',
				'unique_keys' => [
					['name' => 'uniq_account_code', 'columns' => 'account_code'],
				],
				'keys' => [
					['name' => 'idx_account_status', 'columns' => 'status'],
				],
				'type'    => 'InnoDB',
				'comment' => 'Customer billing accounts and encrypted ERP connector metadata',
			],
			'plugin_billing_contract' => [
				'columns' => [
					['name' => 'id', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true],
					['name' => 'account_id', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'contract_code', 'type' => 'varchar(64)', 'NULL' => false, 'default' => ''],
					['name' => 'name', 'type' => 'varchar(191)', 'NULL' => false, 'default' => ''],
					['name' => 'metric_type', 'type' => 'varchar(24)', 'NULL' => false, 'default' => 'bandwidth'],
					['name' => 'direction_mode', 'type' => 'varchar(24)', 'NULL' => false, 'default' => 'max_in_out'],
					['name' => 'percentile', 'type' => 'decimal(5,2)', 'NULL' => false, 'default' => '95.00'],
					['name' => 'drop_rounding', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'floor'],
					['name' => 'gap_policy', 'type' => 'varchar(24)', 'NULL' => false, 'default' => 'exclude'],
					['name' => 'tariff_model', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'flat'],
					['name' => 'commit_units', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'unit_divisor', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => '1000000'],
					['name' => 'base_fee_minor', 'type' => 'bigint(20)', 'NULL' => false, 'default' => '0'],
					['name' => 'overage_rate_minor', 'type' => 'bigint(20)', 'NULL' => false, 'default' => '0'],
					['name' => 'tiers_json', 'type' => 'text', 'NULL' => false],
					['name' => 'tb_cap_bytes', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'tb_overage_rate_minor', 'type' => 'bigint(20)', 'NULL' => false, 'default' => '0'],
					['name' => 'currency', 'type' => 'char(3)', 'NULL' => false, 'default' => 'USD'],
					['name' => 'starts_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'ends_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'status', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'active'],
					['name' => 'created_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'updated_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
				],
				'primary'     => 'id',
				'unique_keys' => [
					['name' => 'uniq_contract_code', 'columns' => 'contract_code'],
				],
				'keys' => [
					['name' => 'idx_contract_account', 'columns' => ['account_id', 'status']],
				],
				'type'    => 'InnoDB',
				'comment' => 'Burstable 95th-percentile and metered resource billing contracts',
			],
			'plugin_billing_circuit' => [
				'columns' => [
					['name' => 'id', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true],
					['name' => 'contract_id', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'local_data_id', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'host_id', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'label', 'type' => 'varchar(191)', 'NULL' => false, 'default' => ''],
					['name' => 'port_speed_bps', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => '10000000000'],
					['name' => 'active_from', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'active_until', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'status', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'active'],
					['name' => 'created_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'updated_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
				],
				'primary' => 'id',
				'keys'    => [
					['name' => 'idx_circuit_contract', 'columns' => ['contract_id', 'status']],
					['name' => 'idx_circuit_local_data', 'columns' => 'local_data_id'],
				],
				'type'    => 'InnoDB',
				'comment' => 'Member circuits and metered resource feeds bound to billing contracts',
			],
			'plugin_billing_sample' => [
				'columns' => [
					['name' => 'id', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true],
					['name' => 'circuit_id', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'bucket_ts', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'in_bps', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'out_bps', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'resource_units', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'raw_in_counter', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'raw_out_counter', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'quality_flag', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'ok'],
					['name' => 'ingested_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
				],
				'primary'     => 'id',
				'unique_keys' => [
					['name' => 'uniq_circuit_bucket', 'columns' => ['circuit_id', 'bucket_ts']],
				],
				'keys' => [
					['name' => 'idx_sample_bucket_ts', 'columns' => 'bucket_ts'],
					['name' => 'idx_sample_ingested', 'columns' => 'ingested_at'],
				],
				'type'    => 'InnoDB',
				'comment' => 'Append-only immutable 5-minute billing sample ledger (State, never exported)',
			],
			'plugin_billing_statement' => [
				'columns' => [
					['name' => 'id', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true],
					['name' => 'statement_code', 'type' => 'varchar(96)', 'NULL' => false, 'default' => ''],
					['name' => 'account_id', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'contract_id', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'period_start', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'period_end', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'currency', 'type' => 'char(3)', 'NULL' => false, 'default' => 'USD'],
					['name' => 'billable_units', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'billable_mbps_x1000', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'total_transfer_bytes', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'base_amount_minor', 'type' => 'bigint(20)', 'NULL' => false, 'default' => '0'],
					['name' => 'overage_amount_minor', 'type' => 'bigint(20)', 'NULL' => false, 'default' => '0'],
					['name' => 'transfer_amount_minor', 'type' => 'bigint(20)', 'NULL' => false, 'default' => '0'],
					['name' => 'total_amount_minor', 'type' => 'bigint(20)', 'NULL' => false, 'default' => '0'],
					['name' => 'sample_count', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'dropped_count', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'snapshot_json', 'type' => 'mediumtext', 'NULL' => false],
					['name' => 'proof_sha256', 'type' => 'char(64)', 'NULL' => false, 'default' => ''],
					['name' => 'status', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'finalized'],
					['name' => 'finalized_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'finalized_by', 'type' => 'varchar(64)', 'NULL' => false, 'default' => 'system'],
				],
				'primary'     => 'id',
				'unique_keys' => [
					['name' => 'uniq_statement_code', 'columns' => 'statement_code'],
					['name' => 'uniq_contract_period', 'columns' => ['contract_id', 'period_start', 'period_end']],
				],
				'keys' => [
					['name' => 'idx_stmt_account_period', 'columns' => ['account_id', 'period_start']],
					['name' => 'idx_stmt_status', 'columns' => 'status'],
				],
				'type'    => 'InnoDB',
				'comment' => 'Immutable finalized billing statements with SHA-256 tamper-evident proof',
			],
			'plugin_billing_export_log' => [
				'columns' => [
					['name' => 'id', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true],
					['name' => 'statement_id', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'connector_type', 'type' => 'varchar(32)', 'NULL' => false, 'default' => 'webhook'],
					['name' => 'target_url', 'type' => 'varchar(512)', 'NULL' => false, 'default' => ''],
					['name' => 'status', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'sent'],
					['name' => 'http_status', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'attempts', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '1'],
					['name' => 'response_digest', 'type' => 'char(64)', 'NULL' => false, 'default' => ''],
					['name' => 'error_message', 'type' => 'varchar(512)', 'NULL' => false, 'default' => ''],
					['name' => 'created_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
				],
				'primary' => 'id',
				'keys'    => [
					['name' => 'idx_export_statement', 'columns' => ['statement_id', 'created_at']],
					['name' => 'idx_export_status', 'columns' => 'status'],
				],
				'type'    => 'InnoDB',
				'comment' => 'Audit log of ERP webhook deliveries and export failures (State)',
			],
		];
	}
}
