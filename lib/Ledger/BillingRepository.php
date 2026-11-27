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

namespace Somethingwithproof\Billing\Ledger;

use Somethingwithproof\Billing\Db\DbAdapter;

/**
 * Prepared-statement repository for accounts, contracts, circuits, immutable
 * 5-minute billing samples, finalized statements, and ERP export audit logs.
 */
final class BillingRepository {
	/**
	 * Construct a BillingRepository over a DbAdapter.
	 *
	 * @param DbAdapter $db Active database adapter.
	 */
	public function __construct(private readonly DbAdapter $db) {
	}

	/**
	 * Return the underlying database adapter.
	 *
	 * @return DbAdapter
	 */
	public function db(): DbAdapter {
		return $this->db;
	}

	/**
	 * Create or update a billing account by account_code and return its numeric ID.
	 *
	 * @param array<string, mixed> $data Account attributes.
	 * @return int Account primary key ID.
	 */
	public function upsertAccount(array $data): int {
		$code = self::requireCode($data['account_code'] ?? '', 'account_code');
		$name = trim(self::asString($data['name'] ?? $code));

		if ($name === '') {
			throw new BillingException('Account name must not be empty.');
		}

		$currency = strtoupper(trim(self::asString($data['currency'] ?? 'USD')));

		if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
			throw new BillingException('Account currency must be a 3-letter ISO 4217 code.');
		}

		$status = strtolower(trim(self::asString($data['status'] ?? 'active')));

		if (!in_array($status, ['active', 'suspended', 'closed'], true)) {
			throw new BillingException('Invalid account status: ' . $status);
		}

		$connector = strtolower(trim(self::asString($data['connector_type'] ?? 'webhook')));
		$extRef    = trim(self::asString($data['external_ref'] ?? ''));
		$webhook   = trim(self::asString($data['webhook_url'] ?? ''));
		$secret    = self::asString($data['secret_ciphertext'] ?? '');
		$now       = time();

		$existing = $this->findAccountByCode($code);

		if ($existing !== null) {
			$id = self::asInt($existing['id'] ?? 0);
			$this->db->execute(
				'UPDATE plugin_billing_account SET name = ?, external_ref = ?, currency = ?, status = ?, connector_type = ?, webhook_url = ?, secret_ciphertext = ?, updated_at = ? WHERE id = ?',
				[$name, $extRef, $currency, $status, $connector, $webhook, $secret !== '' ? $secret : self::asString($existing['secret_ciphertext'] ?? ''), $now, $id]
			);

			return $id;
		}

		$ok = $this->db->execute(
			'INSERT INTO plugin_billing_account (account_code, name, external_ref, currency, status, connector_type, webhook_url, secret_ciphertext, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[$code, $name, $extRef, $currency, $status, $connector, $webhook, $secret, $now, $now]
		);

		if (!$ok) {
			throw new BillingException('Failed to insert billing account ' . $code);
		}

		return $this->db->lastInsertId();
	}

	/**
	 * Fetch a billing account by its unique account_code.
	 *
	 * @param string $code Account code.
	 * @return array<string, mixed>|null
	 */
	public function findAccountByCode(string $code): ?array {
		return $this->db->fetchRow(
			'SELECT * FROM plugin_billing_account WHERE account_code = ?',
			[trim($code)]
		);
	}

	/**
	 * Fetch a billing account by numeric ID.
	 *
	 * @param int $id Account ID.
	 * @return array<string, mixed>|null
	 */
	public function findAccountById(int $id): ?array {
		return $this->db->fetchRow(
			'SELECT * FROM plugin_billing_account WHERE id = ?',
			[$id]
		);
	}

	/**
	 * List billing accounts ordered by account_code.
	 *
	 * @param string|null $status Optional status filter.
	 * @return list<array<string, mixed>>
	 */
	public function listAccounts(?string $status = null): array {
		if ($status !== null && $status !== '') {
			return $this->db->fetchAssoc(
				'SELECT * FROM plugin_billing_account WHERE status = ? ORDER BY account_code ASC',
				[$status]
			);
		}

		return $this->db->fetchAssoc('SELECT * FROM plugin_billing_account ORDER BY account_code ASC');
	}

	/**
	 * Create or update a billing contract by contract_code and return its numeric ID.
	 *
	 * @param array<string, mixed> $data Contract attributes.
	 * @return int Contract primary key ID.
	 */
	public function upsertContract(array $data): int {
		$code      = self::requireCode($data['contract_code'] ?? '', 'contract_code');
		$accountId = self::asInt($data['account_id'] ?? 0);

		if ($accountId <= 0) {
			throw new BillingException('Contract account_id must be a positive integer.');
		}

		$name          = trim(self::asString($data['name'] ?? $code));
		$metricType    = strtolower(trim(self::asString($data['metric_type'] ?? 'bandwidth')));
		$directionMode = strtolower(trim(self::asString($data['direction_mode'] ?? 'max_in_out')));
		$percentile    = isset($data['percentile']) && is_numeric($data['percentile']) ? (float) $data['percentile'] : 95.0;
		$dropRounding  = strtolower(trim(self::asString($data['drop_rounding'] ?? 'floor')));
		$gapPolicy     = strtolower(trim(self::asString($data['gap_policy'] ?? 'exclude')));
		$tariffModel   = strtolower(trim(self::asString($data['tariff_model'] ?? 'flat')));
		$commitUnits   = max(0, self::asInt($data['commit_units'] ?? 0));
		$unitDivisor   = max(1, self::asInt($data['unit_divisor'] ?? 1000000));
		$baseFee       = self::asInt($data['base_fee_minor'] ?? 0);
		$overageRate   = self::asInt($data['overage_rate_minor'] ?? 0);
		$tiersJson     = is_array($data['tiers'] ?? null)
			? (json_encode($data['tiers'], JSON_UNESCAPED_SLASHES) ?: '[]')
			: self::asString($data['tiers_json'] ?? '[]');
		$tbCapBytes    = max(0, self::asInt($data['tb_cap_bytes'] ?? 0));
		$tbOverageRate = self::asInt($data['tb_overage_rate_minor'] ?? 0);
		$currency      = strtoupper(trim(self::asString($data['currency'] ?? 'USD')));
		$startsAt      = max(0, self::asInt($data['starts_at'] ?? 0));
		$endsAt        = max(0, self::asInt($data['ends_at'] ?? 0));
		$status        = strtolower(trim(self::asString($data['status'] ?? 'active')));
		$now           = time();

		if (!in_array($metricType, ['bandwidth', 'power', 'storage', 'gpu'], true)) {
			throw new BillingException('Invalid metric_type: ' . $metricType);
		}

		if (!in_array($directionMode, ['max_in_out', 'sum_in_out', 'separate_in_out', 'in_only', 'out_only'], true)) {
			throw new BillingException('Invalid direction_mode: ' . $directionMode);
		}

		if ($percentile <= 0.0 || $percentile > 100.0) {
			throw new BillingException('Percentile must be in (0, 100].');
		}

		if (!in_array($dropRounding, ['ceil', 'floor', 'nearest'], true)) {
			throw new BillingException('Invalid drop_rounding: ' . $dropRounding);
		}

		if (!in_array($gapPolicy, ['exclude', 'zero_fill', 'carry_forward'], true)) {
			throw new BillingException('Invalid gap_policy: ' . $gapPolicy);
		}

		if (!in_array($tariffModel, ['flat', 'stepped', 'volume'], true)) {
			throw new BillingException('Invalid tariff_model: ' . $tariffModel);
		}

		if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
			throw new BillingException('Contract currency must be a 3-letter ISO 4217 code.');
		}

		$existing = $this->findContractByCode($code);

		if ($existing !== null) {
			$id = self::asInt($existing['id'] ?? 0);
			$this->db->execute(
				'UPDATE plugin_billing_contract SET account_id = ?, name = ?, metric_type = ?, direction_mode = ?, percentile = ?, drop_rounding = ?, gap_policy = ?, tariff_model = ?, commit_units = ?, unit_divisor = ?, base_fee_minor = ?, overage_rate_minor = ?, tiers_json = ?, tb_cap_bytes = ?, tb_overage_rate_minor = ?, currency = ?, starts_at = ?, ends_at = ?, status = ?, updated_at = ? WHERE id = ?',
				[$accountId, $name, $metricType, $directionMode, $percentile, $dropRounding, $gapPolicy, $tariffModel, $commitUnits, $unitDivisor, $baseFee, $overageRate, $tiersJson, $tbCapBytes, $tbOverageRate, $currency, $startsAt, $endsAt, $status, $now, $id]
			);

			return $id;
		}

		$ok = $this->db->execute(
			'INSERT INTO plugin_billing_contract (account_id, contract_code, name, metric_type, direction_mode, percentile, drop_rounding, gap_policy, tariff_model, commit_units, unit_divisor, base_fee_minor, overage_rate_minor, tiers_json, tb_cap_bytes, tb_overage_rate_minor, currency, starts_at, ends_at, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[$accountId, $code, $name, $metricType, $directionMode, $percentile, $dropRounding, $gapPolicy, $tariffModel, $commitUnits, $unitDivisor, $baseFee, $overageRate, $tiersJson, $tbCapBytes, $tbOverageRate, $currency, $startsAt, $endsAt, $status, $now, $now]
		);

		if (!$ok) {
			throw new BillingException('Failed to insert billing contract ' . $code);
		}

		return $this->db->lastInsertId();
	}

	/**
	 * Fetch a contract by contract_code.
	 *
	 * @param string $code Contract code.
	 * @return array<string, mixed>|null
	 */
	public function findContractByCode(string $code): ?array {
		return $this->db->fetchRow(
			'SELECT * FROM plugin_billing_contract WHERE contract_code = ?',
			[trim($code)]
		);
	}

	/**
	 * Fetch a contract by numeric ID.
	 *
	 * @param int $id Contract ID.
	 * @return array<string, mixed>|null
	 */
	public function findContractById(int $id): ?array {
		return $this->db->fetchRow(
			'SELECT * FROM plugin_billing_contract WHERE id = ?',
			[$id]
		);
	}

	/**
	 * List contracts optionally filtered by account ID or status.
	 *
	 * @param int|null $accountId Optional account filter.
	 * @param string|null $status Optional status filter.
	 * @return list<array<string, mixed>>
	 */
	public function listContracts(?int $accountId = null, ?string $status = null): array {
		$where  = [];
		$params = [];

		if ($accountId !== null && $accountId > 0) {
			$where[]  = 'account_id = ?';
			$params[] = $accountId;
		}

		if ($status !== null && $status !== '') {
			$where[]  = 'status = ?';
			$params[] = $status;
		}

		$sql = 'SELECT * FROM plugin_billing_contract';

		if ($where !== []) {
			$sql .= ' WHERE ' . implode(' AND ', $where);
		}

		$sql .= ' ORDER BY contract_code ASC';

		return $this->db->fetchAssoc($sql, $params);
	}

	/**
	 * Add a circuit to a contract and return its numeric ID.
	 *
	 * @param array<string, mixed> $data Circuit attributes.
	 * @return int Circuit primary key ID.
	 */
	public function addCircuit(array $data): int {
		$contractId  = self::asInt($data['contract_id'] ?? 0);
		$localDataId = max(0, self::asInt($data['local_data_id'] ?? 0));
		$hostId      = max(0, self::asInt($data['host_id'] ?? 0));
		$label       = trim(self::asString($data['label'] ?? ''));
		$portSpeed   = max(0, self::asInt($data['port_speed_bps'] ?? 10000000000));
		$activeFrom  = max(0, self::asInt($data['active_from'] ?? 0));
		$activeUntil = max(0, self::asInt($data['active_until'] ?? 0));
		$status      = strtolower(trim(self::asString($data['status'] ?? 'active')));
		$now         = time();

		if ($contractId <= 0) {
			throw new BillingException('Circuit contract_id must be a positive integer.');
		}

		if ($label === '') {
			$label = 'circuit-' . $localDataId;
		}

		$ok = $this->db->execute(
			'INSERT INTO plugin_billing_circuit (contract_id, local_data_id, host_id, label, port_speed_bps, active_from, active_until, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[$contractId, $localDataId, $hostId, $label, $portSpeed, $activeFrom, $activeUntil, $status, $now, $now]
		);

		if (!$ok) {
			throw new BillingException('Failed to insert billing circuit.');
		}

		return $this->db->lastInsertId();
	}

	/**
	 * List all circuits assigned to a contract.
	 *
	 * @param int $contractId Contract ID.
	 * @return list<array<string, mixed>>
	 */
	public function listCircuitsForContract(int $contractId): array {
		return $this->db->fetchAssoc(
			'SELECT * FROM plugin_billing_circuit WHERE contract_id = ? ORDER BY id ASC',
			[$contractId]
		);
	}

	/**
	 * Fetch a single circuit by ID.
	 *
	 * @param int $circuitId Circuit ID.
	 * @return array<string, mixed>|null
	 */
	public function findCircuitById(int $circuitId): ?array {
		return $this->db->fetchRow(
			'SELECT * FROM plugin_billing_circuit WHERE id = ?',
			[$circuitId]
		);
	}

	/**
	 * Append an immutable 5-minute sample row. Refuses to overwrite an existing bucket.
	 *
	 * @param int $circuitId Circuit ID.
	 * @param int $bucketTs Aligned 300-second Unix timestamp.
	 * @param int $inBps Ingress rate in bps.
	 * @param int $outBps Egress rate in bps.
	 * @param int $resourceUnits Generic resource usage in micro-units.
	 * @param int $rawInCounter Raw ingress counter snapshot.
	 * @param int $rawOutCounter Raw egress counter snapshot.
	 * @param string $qualityFlag Quality marker (ok, wrap, clamped, gap, maint).
	 * @param int|null $ingestedAt Optional ingestion timestamp.
	 * @return bool True when inserted; false when the immutable bucket already exists.
	 */
	public function appendSample(
		int $circuitId,
		int $bucketTs,
		int $inBps,
		int $outBps,
		int $resourceUnits = 0,
		int $rawInCounter = 0,
		int $rawOutCounter = 0,
		string $qualityFlag = 'ok',
		?int $ingestedAt = null,
	): bool {
		$existing = $this->db->fetchCell(
			'SELECT id FROM plugin_billing_sample WHERE circuit_id = ? AND bucket_ts = ?',
			[$circuitId, $bucketTs]
		);

		if (is_numeric($existing) && (int) $existing > 0) {
			return false;
		}

		return $this->db->execute(
			'INSERT INTO plugin_billing_sample (circuit_id, bucket_ts, in_bps, out_bps, resource_units, raw_in_counter, raw_out_counter, quality_flag, ingested_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[$circuitId, $bucketTs, max(0, $inBps), max(0, $outBps), max(0, $resourceUnits), max(0, $rawInCounter), max(0, $rawOutCounter), $qualityFlag, $ingestedAt ?? time()]
		);
	}

	/**
	 * Fetch the most recent sample recorded for a circuit prior to a given bucket timestamp.
	 *
	 * @param int $circuitId Circuit ID.
	 * @param int $beforeTs Exclusive upper timestamp bound.
	 * @return array<string, mixed>|null
	 */
	public function latestSampleBefore(int $circuitId, int $beforeTs): ?array {
		return $this->db->fetchRow(
			'SELECT * FROM plugin_billing_sample WHERE circuit_id = ? AND bucket_ts < ? ORDER BY bucket_ts DESC LIMIT 1',
			[$circuitId, $beforeTs]
		);
	}

	/**
	 * Fetch all samples for a list of circuit IDs within [periodStart, periodEnd).
	 *
	 * @param list<int> $circuitIds Circuit primary keys.
	 * @param int $periodStart Inclusive period start Unix timestamp.
	 * @param int $periodEnd Exclusive period end Unix timestamp.
	 * @return array<int, list<array{bucket_ts: int, in_bps: int, out_bps: int, resource_units: int, quality_flag: string}>>
	 */
	public function fetchSamplesByCircuit(array $circuitIds, int $periodStart, int $periodEnd): array {
		if ($circuitIds === []) {
			return [];
		}

		$placeholders = implode(', ', array_fill(0, count($circuitIds), '?'));
		$params       = array_merge($circuitIds, [$periodStart, $periodEnd]);
		$rows         = $this->db->fetchAssoc(
			"SELECT circuit_id, bucket_ts, in_bps, out_bps, resource_units, quality_flag FROM plugin_billing_sample WHERE circuit_id IN ($placeholders) AND bucket_ts >= ? AND bucket_ts < ? ORDER BY circuit_id ASC, bucket_ts ASC",
			$params
		);

		$grouped = [];

		foreach ($circuitIds as $cid) {
			$grouped[$cid] = [];
		}

		foreach ($rows as $row) {
			$cid = self::asInt($row['circuit_id'] ?? 0);

			if (!isset($grouped[$cid])) {
				$grouped[$cid] = [];
			}

			$grouped[$cid][] = [
				'bucket_ts'      => self::asInt($row['bucket_ts'] ?? 0),
				'in_bps'         => self::asInt($row['in_bps'] ?? 0),
				'out_bps'        => self::asInt($row['out_bps'] ?? 0),
				'resource_units' => self::asInt($row['resource_units'] ?? 0),
				'quality_flag'   => self::asString($row['quality_flag'] ?? 'ok'),
			];
		}

		return $grouped;
	}

	/**
	 * Insert an immutable finalized billing statement. Throws if period already finalized.
	 *
	 * @param array<string, mixed> $statement Statement row payload.
	 * @return int Inserted statement ID.
	 */
	public function insertStatement(array $statement): int {
		$contractId  = self::asInt($statement['contract_id'] ?? 0);
		$periodStart = self::asInt($statement['period_start'] ?? 0);
		$periodEnd   = self::asInt($statement['period_end'] ?? 0);

		$existing = $this->db->fetchCell(
			'SELECT id FROM plugin_billing_statement WHERE contract_id = ? AND period_start = ? AND period_end = ?',
			[$contractId, $periodStart, $periodEnd]
		);

		if (is_numeric($existing) && (int) $existing > 0) {
			throw new BillingException('A finalized statement already exists for this contract and period.');
		}

		$ok = $this->db->execute(
			'INSERT INTO plugin_billing_statement (statement_code, account_id, contract_id, period_start, period_end, currency, billable_units, billable_mbps_x1000, total_transfer_bytes, base_amount_minor, overage_amount_minor, transfer_amount_minor, total_amount_minor, sample_count, dropped_count, snapshot_json, proof_sha256, status, finalized_at, finalized_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[
				self::asString($statement['statement_code'] ?? ''),
				self::asInt($statement['account_id'] ?? 0),
				$contractId,
				$periodStart,
				$periodEnd,
				self::asString($statement['currency'] ?? 'USD'),
				self::asInt($statement['billable_units'] ?? 0),
				self::asInt($statement['billable_mbps_x1000'] ?? 0),
				self::asInt($statement['total_transfer_bytes'] ?? 0),
				self::asInt($statement['base_amount_minor'] ?? 0),
				self::asInt($statement['overage_amount_minor'] ?? 0),
				self::asInt($statement['transfer_amount_minor'] ?? 0),
				self::asInt($statement['total_amount_minor'] ?? 0),
				self::asInt($statement['sample_count'] ?? 0),
				self::asInt($statement['dropped_count'] ?? 0),
				self::asString($statement['snapshot_json'] ?? '{}'),
				self::asString($statement['proof_sha256'] ?? ''),
				self::asString($statement['status'] ?? 'finalized'),
				self::asInt($statement['finalized_at'] ?? time()),
				self::asString($statement['finalized_by'] ?? 'system'),
			]
		);

		if (!$ok) {
			throw new BillingException('Failed to persist finalized billing statement.');
		}

		return $this->db->lastInsertId();
	}

	/**
	 * Fetch a finalized statement by statement_code.
	 *
	 * @param string $code Statement code.
	 * @return array<string, mixed>|null
	 */
	public function findStatementByCode(string $code): ?array {
		return $this->db->fetchRow(
			'SELECT * FROM plugin_billing_statement WHERE statement_code = ?',
			[trim($code)]
		);
	}

	/**
	 * Fetch a finalized statement by numeric ID.
	 *
	 * @param int $id Statement ID.
	 * @return array<string, mixed>|null
	 */
	public function findStatementById(int $id): ?array {
		return $this->db->fetchRow(
			'SELECT * FROM plugin_billing_statement WHERE id = ?',
			[$id]
		);
	}

	/**
	 * List finalized statements ordered newest first.
	 *
	 * @param int|null $accountId Optional account ID filter.
	 * @param int $limit Maximum rows returned.
	 * @return list<array<string, mixed>>
	 */
	public function listStatements(?int $accountId = null, int $limit = 100): array {
		$limit = max(1, min(1000, $limit));

		if ($accountId !== null && $accountId > 0) {
			return $this->db->fetchAssoc(
				'SELECT * FROM plugin_billing_statement WHERE account_id = ? ORDER BY period_start DESC, id DESC LIMIT ' . $limit,
				[$accountId]
			);
		}

		return $this->db->fetchAssoc(
			'SELECT * FROM plugin_billing_statement ORDER BY period_start DESC, id DESC LIMIT ' . $limit
		);
	}

	/**
	 * Record an ERP webhook export delivery attempt in plugin_billing_export_log.
	 *
	 * @param int $statementId Finalized statement ID.
	 * @param string $connectorType Connector adapter slug.
	 * @param string $targetUrl Destination HTTPS endpoint.
	 * @param string $status Delivery status (sent, failed, skipped).
	 * @param int $httpStatus HTTP response status code (0 on transport error).
	 * @param int $attempts Number of delivery attempts performed.
	 * @param string $responseDigest SHA-256 hex digest of bounded response body.
	 * @param string $errorMessage Redacted error message if failed.
	 * @return int Inserted log row ID.
	 */
	public function recordExportLog(
		int $statementId,
		string $connectorType,
		string $targetUrl,
		string $status,
		int $httpStatus,
		int $attempts,
		string $responseDigest,
		string $errorMessage = '',
	): int {
		$this->db->execute(
			'INSERT INTO plugin_billing_export_log (statement_id, connector_type, target_url, status, http_status, attempts, response_digest, error_message, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[$statementId, $connectorType, $targetUrl, $status, $httpStatus, max(1, $attempts), $responseDigest, substr($errorMessage, 0, 512), time()]
		);

		return $this->db->lastInsertId();
	}

	/**
	 * Fetch recent ERP export audit log entries.
	 *
	 * @param int $limit Maximum rows returned.
	 * @return list<array<string, mixed>>
	 */
	public function listExportLogs(int $limit = 50): array {
		$limit = max(1, min(500, $limit));

		return $this->db->fetchAssoc(
			'SELECT * FROM plugin_billing_export_log ORDER BY id DESC LIMIT ' . $limit
		);
	}

	/**
	 * Aggregate operational telemetry counters for Cacti script server and health checks.
	 *
	 * @param int|null $now Unix timestamp reference for 24h sample window.
	 * @return array{
	 *   accounts_active: int,
	 *   contracts_active: int,
	 *   circuits_total: int,
	 *   statements_finalized: int,
	 *   exports_failed: int,
	 *   samples_24h: int
	 * }
	 */
	public function operationalCounts(?int $now = null): array {
		$since = ($now ?? time()) - 86400;

		return [
			'accounts_active'      => self::asInt($this->db->fetchCell("SELECT COUNT(*) FROM plugin_billing_account WHERE status = 'active'")),
			'contracts_active'     => self::asInt($this->db->fetchCell("SELECT COUNT(*) FROM plugin_billing_contract WHERE status = 'active'")),
			'circuits_total'       => self::asInt($this->db->fetchCell('SELECT COUNT(*) FROM plugin_billing_circuit')),
			'statements_finalized' => self::asInt($this->db->fetchCell("SELECT COUNT(*) FROM plugin_billing_statement WHERE status = 'finalized'")),
			'exports_failed'       => self::asInt($this->db->fetchCell("SELECT COUNT(*) FROM plugin_billing_export_log WHERE status = 'failed'")),
			'samples_24h'          => self::asInt($this->db->fetchCell('SELECT COUNT(*) FROM plugin_billing_sample WHERE ingested_at >= ?', [$since])),
		];
	}

	/**
	 * Validate a required slug/code identifier.
	 *
	 * @param mixed $value Raw identifier value.
	 * @param string $field Field name for error context.
	 * @return string
	 */
	private static function requireCode(mixed $value, string $field): string {
		$code = trim(self::asString($value));

		if (preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $code) !== 1) {
			throw new BillingException(sprintf('Invalid %s "%s": expected 1..64 alphanumeric/._:- characters.', $field, $code));
		}

		return $code;
	}

	/**
	 * Safely coerce a mixed value to integer.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private static function asInt(mixed $value): int {
		return is_numeric($value) ? (int) $value : 0;
	}

	/**
	 * Safely coerce a mixed value to string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function asString(mixed $value): string {
		return is_scalar($value) ? (string) $value : '';
	}
}
