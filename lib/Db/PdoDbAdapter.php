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

namespace Somethingwithproof\Billing\Db;

/**
 * PDO-backed DbAdapter used for isolated SQLite in-memory testing and standalone CLI runs.
 */
final class PdoDbAdapter implements DbAdapter {
	/**
	 * Cached row count from the most recent write statement.
	 */
	private int $lastAffectedRows = 0;

	/**
	 * Construct a PDO adapter wrapping an active PDO handle.
	 *
	 * @param \PDO $pdo Active PDO connection configured with exception error mode.
	 */
	public function __construct(private readonly \PDO $pdo) {
		$this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
	}

	/**
	 * Create an in-memory SQLite PDO adapter initialized with the plugin_billing schema.
	 *
	 * @return self
	 */
	public static function createSqliteMemory(): self {
		$pdo     = new \PDO('sqlite::memory:');
		$adapter = new self($pdo);
		$adapter->createSqliteTables();

		return $adapter;
	}

	/**
	 * Execute a parameterized write statement.
	 *
	 * @param string $sql Parameterized SQL statement.
	 * @param list<bool|float|int|string|null> $params Positional parameter bindings.
	 * @return bool
	 */
	public function execute(string $sql, array $params = []): bool {
		try {
			$stmt                   = $this->pdo->prepare($sql);
			$ok                     = $stmt->execute($params);
			$this->lastAffectedRows = $ok ? $stmt->rowCount() : 0;

			return $ok;
		} catch (\PDOException) {
			$this->lastAffectedRows = 0;

			return false;
		}
	}

	/**
	 * Fetch a single scalar column value.
	 *
	 * @param string $sql Parameterized SQL SELECT query.
	 * @param list<bool|float|int|string|null> $params Positional parameter bindings.
	 * @return mixed
	 */
	public function fetchCell(string $sql, array $params = []): mixed {
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);

		return $stmt->fetchColumn(0);
	}

	/**
	 * Fetch the first matching row as an associative array.
	 *
	 * @param string $sql Parameterized SQL SELECT query.
	 * @param list<bool|float|int|string|null> $params Positional parameter bindings.
	 * @return array<string, mixed>|null
	 */
	public function fetchRow(string $sql, array $params = []): ?array {
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);
		$row  = $stmt->fetch(\PDO::FETCH_ASSOC);

		if (!is_array($row) || $row === []) {
			return null;
		}

		$normalized = [];

		foreach ($row as $k => $v) {
			$normalized[(string) $k] = $v;
		}

		return $normalized;
	}

	/**
	 * Fetch all matching rows as a list of associative arrays.
	 *
	 * @param string $sql Parameterized SQL SELECT query.
	 * @param list<bool|float|int|string|null> $params Positional parameter bindings.
	 * @return list<array<string, mixed>>
	 */
	public function fetchAssoc(string $sql, array $params = []): array {
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		$result = [];

		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$normalized = [];

			foreach ($row as $k => $v) {
				$normalized[(string) $k] = $v;
			}

			$result[] = $normalized;
		}

		return $result;
	}

	/**
	 * Return the last auto-increment insert ID.
	 *
	 * @return int
	 */
	public function lastInsertId(): int {
		$id = $this->pdo->lastInsertId();

		return is_numeric($id) ? (int) $id : 0;
	}

	/**
	 * Return affected rows from the last execute() call.
	 *
	 * @return int
	 */
	public function affectedRows(): int {
		return $this->lastAffectedRows;
	}

	/**
	 * Provision SQLite-compatible DDL matching Schema::tables() for unit testing.
	 *
	 * @return void
	 */
	private function createSqliteTables(): void {
		$statements = [
			'CREATE TABLE plugin_billing_account (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				account_code TEXT NOT NULL UNIQUE,
				name TEXT NOT NULL DEFAULT "",
				external_ref TEXT NOT NULL DEFAULT "",
				currency TEXT NOT NULL DEFAULT "USD",
				status TEXT NOT NULL DEFAULT "active",
				connector_type TEXT NOT NULL DEFAULT "webhook",
				webhook_url TEXT NOT NULL DEFAULT "",
				secret_ciphertext TEXT NOT NULL DEFAULT "",
				created_at INTEGER NOT NULL DEFAULT 0,
				updated_at INTEGER NOT NULL DEFAULT 0
			)',
			'CREATE TABLE plugin_billing_contract (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				account_id INTEGER NOT NULL DEFAULT 0,
				contract_code TEXT NOT NULL UNIQUE,
				name TEXT NOT NULL DEFAULT "",
				metric_type TEXT NOT NULL DEFAULT "bandwidth",
				direction_mode TEXT NOT NULL DEFAULT "max_in_out",
				percentile REAL NOT NULL DEFAULT 95.0,
				drop_rounding TEXT NOT NULL DEFAULT "floor",
				gap_policy TEXT NOT NULL DEFAULT "exclude",
				tariff_model TEXT NOT NULL DEFAULT "flat",
				commit_units INTEGER NOT NULL DEFAULT 0,
				unit_divisor INTEGER NOT NULL DEFAULT 1000000,
				base_fee_minor INTEGER NOT NULL DEFAULT 0,
				overage_rate_minor INTEGER NOT NULL DEFAULT 0,
				tiers_json TEXT NOT NULL DEFAULT "[]",
				tb_cap_bytes INTEGER NOT NULL DEFAULT 0,
				tb_overage_rate_minor INTEGER NOT NULL DEFAULT 0,
				currency TEXT NOT NULL DEFAULT "USD",
				starts_at INTEGER NOT NULL DEFAULT 0,
				ends_at INTEGER NOT NULL DEFAULT 0,
				status TEXT NOT NULL DEFAULT "active",
				created_at INTEGER NOT NULL DEFAULT 0,
				updated_at INTEGER NOT NULL DEFAULT 0
			)',
			'CREATE TABLE plugin_billing_circuit (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				contract_id INTEGER NOT NULL DEFAULT 0,
				local_data_id INTEGER NOT NULL DEFAULT 0,
				host_id INTEGER NOT NULL DEFAULT 0,
				label TEXT NOT NULL DEFAULT "",
				port_speed_bps INTEGER NOT NULL DEFAULT 10000000000,
				active_from INTEGER NOT NULL DEFAULT 0,
				active_until INTEGER NOT NULL DEFAULT 0,
				status TEXT NOT NULL DEFAULT "active",
				created_at INTEGER NOT NULL DEFAULT 0,
				updated_at INTEGER NOT NULL DEFAULT 0
			)',
			'CREATE TABLE plugin_billing_sample (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				circuit_id INTEGER NOT NULL DEFAULT 0,
				bucket_ts INTEGER NOT NULL DEFAULT 0,
				in_bps INTEGER NOT NULL DEFAULT 0,
				out_bps INTEGER NOT NULL DEFAULT 0,
				resource_units INTEGER NOT NULL DEFAULT 0,
				raw_in_counter INTEGER NOT NULL DEFAULT 0,
				raw_out_counter INTEGER NOT NULL DEFAULT 0,
				quality_flag TEXT NOT NULL DEFAULT "ok",
				ingested_at INTEGER NOT NULL DEFAULT 0,
				UNIQUE (circuit_id, bucket_ts)
			)',
			'CREATE TABLE plugin_billing_statement (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				statement_code TEXT NOT NULL UNIQUE,
				account_id INTEGER NOT NULL DEFAULT 0,
				contract_id INTEGER NOT NULL DEFAULT 0,
				period_start INTEGER NOT NULL DEFAULT 0,
				period_end INTEGER NOT NULL DEFAULT 0,
				currency TEXT NOT NULL DEFAULT "USD",
				billable_units INTEGER NOT NULL DEFAULT 0,
				billable_mbps_x1000 INTEGER NOT NULL DEFAULT 0,
				total_transfer_bytes INTEGER NOT NULL DEFAULT 0,
				base_amount_minor INTEGER NOT NULL DEFAULT 0,
				overage_amount_minor INTEGER NOT NULL DEFAULT 0,
				transfer_amount_minor INTEGER NOT NULL DEFAULT 0,
				total_amount_minor INTEGER NOT NULL DEFAULT 0,
				sample_count INTEGER NOT NULL DEFAULT 0,
				dropped_count INTEGER NOT NULL DEFAULT 0,
				snapshot_json TEXT NOT NULL DEFAULT "{}",
				proof_sha256 TEXT NOT NULL DEFAULT "",
				status TEXT NOT NULL DEFAULT "finalized",
				finalized_at INTEGER NOT NULL DEFAULT 0,
				finalized_by TEXT NOT NULL DEFAULT "system",
				UNIQUE (contract_id, period_start, period_end)
			)',
			'CREATE TABLE plugin_billing_export_log (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				statement_id INTEGER NOT NULL DEFAULT 0,
				connector_type TEXT NOT NULL DEFAULT "webhook",
				target_url TEXT NOT NULL DEFAULT "",
				status TEXT NOT NULL DEFAULT "sent",
				http_status INTEGER NOT NULL DEFAULT 0,
				attempts INTEGER NOT NULL DEFAULT 1,
				response_digest TEXT NOT NULL DEFAULT "",
				error_message TEXT NOT NULL DEFAULT "",
				created_at INTEGER NOT NULL DEFAULT 0
			)',
		];

		foreach ($statements as $ddl) {
			$this->pdo->exec($ddl);
		}
	}
}
