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

namespace Somethingwithproof\Billing\Cli;

use Somethingwithproof\Billing\Config\ConfigSchema;
use Somethingwithproof\Billing\Connector\ConnectorPayloadBuilder;
use Somethingwithproof\Billing\Connector\CsvStatementExporter;
use Somethingwithproof\Billing\Ledger\BillingException;
use Somethingwithproof\Billing\Ledger\BillingRepository;
use Somethingwithproof\Billing\Rating\BurstForecaster;
use Somethingwithproof\Billing\Settings;
use Somethingwithproof\Billing\Statement\StatementService;

/**
 * Pure-PHP CLI dispatcher implementing rate, finalize, verify, forecast,
 * export-usage, export, validate, plan, apply --confirm=<PLAN_HASH>, and health.
 */
final class CliRunner {
	/**
	 * Settings reader callback.
	 *
	 * @var callable(): Settings
	 */
	private $settingsReader;

	/**
	 * Settings writer callback.
	 *
	 * @var callable(Settings): bool
	 */
	private $settingsWriter;

	/**
	 * Construct a CliRunner with injected billing services.
	 *
	 * @param BillingRepository $repository Billing repository.
	 * @param StatementService $statements Statement rating/finalization service.
	 * @param BurstForecaster $forecaster Mid-cycle burst forecaster.
	 * @param ConnectorPayloadBuilder $payloadBuilder ERP/FOCUS payload builder.
	 * @param callable(): Settings $settingsReader Active settings provider.
	 * @param callable(Settings): bool $settingsWriter Settings writer callback.
	 */
	public function __construct(
		private readonly BillingRepository $repository,
		private readonly StatementService $statements,
		private readonly BurstForecaster $forecaster,
		private readonly ConnectorPayloadBuilder $payloadBuilder,
		callable $settingsReader,
		callable $settingsWriter,
	) {
		$this->settingsReader = $settingsReader;
		$this->settingsWriter = $settingsWriter;
	}

	/**
	 * Execute a CLI command argument vector and return exit code plus stdout/stderr strings.
	 *
	 * @param list<string> $argv Arguments after the script name.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	public function run(array $argv): array {
		$parsed  = self::parseArgs($argv);
		$command = $parsed['command'];
		$opts    = $parsed['options'];
		$format  = strtolower($opts['format'] ?? 'json');

		try {
			return match ($command) {
				'rate'         => $this->cmdRate($opts, $format),
				'finalize'     => $this->cmdFinalize($opts, $format),
				'verify'       => $this->cmdVerify($opts, $format),
				'forecast'     => $this->cmdForecast($opts, $format),
				'export-usage' => $this->cmdExportUsage($opts),
				'export'       => $this->cmdExportConfig($format),
				'validate'     => $this->cmdValidate($opts, $format),
				'plan'         => $this->cmdPlan($opts, $format),
				'apply'        => $this->cmdApply($opts, $format),
				'health'       => $this->cmdHealth($format),
				'help', ''     => [
					'exitCode' => 0,
					'stdout'   => "Usage: billing.php <rate|finalize|verify|forecast|export-usage|export|validate|plan|apply|health> [options]\n",
					'stderr'   => '',
				],
				default        => [
					'exitCode' => 2,
					'stdout'   => '',
					'stderr'   => 'Unknown subcommand: ' . $command . "\n",
				],
			};
		} catch (BillingException $error) {
			return [
				'exitCode' => 1,
				'stdout'   => '',
				'stderr'   => $error->getMessage() . "\n",
			];
		}
	}

	/**
	 * Dry-run rate a contract for a period.
	 *
	 * @param array<string, string> $opts CLI options.
	 * @param string $format Output format.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdRate(array $opts, string $format): array {
		$contract = $opts['contract'] ?? '';
		$start    = self::parseTimestamp($opts['start'] ?? '');
		$end      = self::parseTimestamp($opts['end'] ?? '');
		$result   = $this->statements->rateDryRun($contract, $start, $end);

		return $this->emit($result, $format);
	}

	/**
	 * Finalize an immutable statement with SHA-256 proof.
	 *
	 * @param array<string, string> $opts CLI options.
	 * @param string $format Output format.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdFinalize(array $opts, string $format): array {
		$contract = $opts['contract'] ?? '';
		$start    = self::parseTimestamp($opts['start'] ?? '');
		$end      = self::parseTimestamp($opts['end'] ?? '');
		$actor    = $opts['actor'] ?? 'cli';
		$stmt     = $this->statements->finalize($contract, $start, $end, $actor);

		return $this->emit($stmt, $format);
	}

	/**
	 * Verify a finalized statement's SHA-256 tamper-evident proof.
	 *
	 * @param array<string, string> $opts CLI options.
	 * @param string $format Output format.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdVerify(array $opts, string $format): array {
		$code   = $opts['statement'] ?? '';
		$result = $this->statements->verifyIntegrityByCode($code);

		return [
			'exitCode' => $result['valid'] ? 0 : 1,
			'stdout'   => $this->formatOutput($result, $format),
			'stderr'   => '',
		];
	}

	/**
	 * Project mid-cycle burst budget consumption for a contract.
	 *
	 * @param array<string, string> $opts CLI options.
	 * @param string $format Output format.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdForecast(array $opts, string $format): array {
		$contractCode = $opts['contract'] ?? '';
		$contract     = $this->repository->findContractByCode($contractCode);

		if ($contract === null) {
			throw new BillingException('Unknown billing contract: ' . $contractCode);
		}

		$start      = self::parseTimestamp($opts['start'] ?? '');
		$end        = self::parseTimestamp($opts['end'] ?? '');
		$asOf       = isset($opts['as-of']) ? self::parseTimestamp($opts['as-of']) : min($end, time());
		$contractId = is_numeric($contract['id'] ?? null) ? (int) $contract['id'] : 0;
		$circuits   = $this->repository->listCircuitsForContract($contractId);
		$circuitIds = array_map(static fn (array $c): int => is_numeric($c['id'] ?? null) ? (int) $c['id'] : 0, $circuits);
		$samples    = $this->repository->fetchSamplesByCircuit($circuitIds, $start, $end);
		$forecast   = $this->forecaster->forecast($contract, $samples, $start, $end, $asOf);

		return $this->emit($forecast, $format);
	}

	/**
	 * Export finalized statement usage as RFC 4180 CSV or FOCUS 1.0 JSON.
	 *
	 * @param array<string, string> $opts CLI options.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdExportUsage(array $opts): array {
		$code   = $opts['statement'] ?? '';
		$format = strtolower($opts['format'] ?? 'focus');
		$stmt   = $this->repository->findStatementByCode($code);

		if ($stmt === null) {
			throw new BillingException('Statement not found: ' . $code);
		}

		$accountId = is_numeric($stmt['account_id'] ?? null) ? (int) $stmt['account_id'] : 0;
		$account   = $this->repository->findAccountById($accountId) ?? ['account_code' => '', 'name' => ''];

		if ($format === 'csv') {
			$enriched = array_merge($stmt, [
				'account_code' => $account['account_code'] ?? '',
				'account_name' => $account['name'] ?? '',
			]);

			return [
				'exitCode' => 0,
				'stdout'   => CsvStatementExporter::exportStatements([$enriched]),
				'stderr'   => '',
			];
		}

		$focus = $this->payloadBuilder->buildFocusExport($account, $stmt);

		return $this->emit(['records' => $focus], 'json');
	}

	/**
	 * Export declarative plugin Config only (never State tables or secret values).
	 *
	 * @param string $format Output format.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdExportConfig(string $format): array {
		$payload = [
			'schema_version' => ConfigSchema::SCHEMA_VERSION,
			'config'         => ($this->settingsReader)()->toConfigArray(),
		];

		return $this->emit($payload, $format);
	}

	/**
	 * Validate a declarative JSON configuration file against ConfigSchema.
	 *
	 * @param array<string, string> $opts CLI options.
	 * @param string $format Output format.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdValidate(array $opts, string $format): array {
		$desired = self::loadJsonConfigFile($opts['file'] ?? '');
		$errors  = ConfigSchema::validate($desired);

		return [
			'exitCode' => $errors === [] ? 0 : 1,
			'stdout'   => $this->formatOutput(['valid' => $errors === [], 'errors' => $errors], $format),
			'stderr'   => '',
		];
	}

	/**
	 * Compute a dry-run configuration plan and deterministic SHA-256 confirmation hash.
	 *
	 * @param array<string, string> $opts CLI options.
	 * @param string $format Output format.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdPlan(array $opts, string $format): array {
		$desired = self::loadJsonConfigFile($opts['file'] ?? '');
		$errors  = ConfigSchema::validate($desired);

		if ($errors !== []) {
			return [
				'exitCode' => 1,
				'stdout'   => $this->formatOutput(['valid' => false, 'errors' => $errors], $format),
				'stderr'   => '',
			];
		}

		$plan = ConfigSchema::plan(($this->settingsReader)()->toConfigArray(), $desired);

		return $this->emit($plan, $format);
	}

	/**
	 * Apply a validated declarative configuration when --confirm=<PLAN_HASH> matches.
	 *
	 * @param array<string, string> $opts CLI options.
	 * @param string $format Output format.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdApply(array $opts, string $format): array {
		$desired = self::loadJsonConfigFile($opts['file'] ?? '');
		$errors  = ConfigSchema::validate($desired);

		if ($errors !== []) {
			return [
				'exitCode' => 1,
				'stdout'   => $this->formatOutput(['ok' => false, 'errors' => $errors], $format),
				'stderr'   => '',
			];
		}

		$current = ($this->settingsReader)()->toConfigArray();
		$plan    = ConfigSchema::plan($current, $desired);
		$confirm = trim($opts['confirm'] ?? '');

		if ($confirm === '' || !hash_equals($plan['plan_hash'], $confirm)) {
			return [
				'exitCode' => 2,
				'stdout'   => '',
				'stderr'   => 'Plan hash confirmation mismatch. Expected --confirm=' . $plan['plan_hash'] . "\n",
			];
		}

		$next = Settings::fromRow(array_merge($current, $desired));
		$ok   = ($this->settingsWriter)($next);

		return [
			'exitCode' => $ok ? 0 : 1,
			'stdout'   => $this->formatOutput(['ok' => $ok, 'plan_hash' => $plan['plan_hash'], 'applied_actions' => count($plan['actions'])], $format),
			'stderr'   => '',
		];
	}

	/**
	 * Report billing subsystem health and operational counts.
	 *
	 * @param string $format Output format.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdHealth(string $format): array {
		$counts = $this->repository->operationalCounts();
		$status = $counts['exports_failed'] > 0 ? 'warning' : 'ok';

		return $this->emit([
			'status' => $status,
			'counts' => $counts,
		], $format);
	}

	/**
	 * Format a successful result payload.
	 *
	 * @param array<string, mixed> $payload Result map.
	 * @param string $format Output format (json or text).
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function emit(array $payload, string $format): array {
		return [
			'exitCode' => 0,
			'stdout'   => $this->formatOutput($payload, $format),
			'stderr'   => '',
		];
	}

	/**
	 * Render a result map as JSON or key=value text.
	 *
	 * @param array<string, mixed> $payload Result map.
	 * @param string $format Output format.
	 * @return string
	 */
	private function formatOutput(array $payload, string $format): string {
		if ($format === 'text') {
			$lines = [];

			foreach ($payload as $k => $v) {
				$rendered = is_scalar($v) ? (string) $v : (json_encode($v, JSON_UNESCAPED_SLASHES) ?: '');
				$lines[]  = $k . ': ' . $rendered;
			}

			return implode("\n", $lines) . "\n";
		}

		return (json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') . "\n";
	}

	/**
	 * Parse CLI subcommand and --key=value options.
	 *
	 * @param list<string> $argv Raw argument list.
	 * @return array{command: string, options: array<string, string>}
	 */
	private static function parseArgs(array $argv): array {
		$command = '';
		$options = [];

		foreach ($argv as $arg) {
			if (str_starts_with($arg, '--')) {
				$eq = strpos($arg, '=');

				if ($eq !== false) {
					$key           = substr($arg, 2, $eq - 2);
					$options[$key] = substr($arg, $eq + 1);
				} else {
					$options[substr($arg, 2)] = '1';
				}
			} elseif ($command === '') {
				$command = strtolower($arg);
			}
		}

		return ['command' => $command, 'options' => $options];
	}

	/**
	 * Parse a Unix integer timestamp or ISO-8601 UTC date string.
	 *
	 * @param string $raw Timestamp string.
	 * @return int
	 */
	private static function parseTimestamp(string $raw): int {
		$trimmed = trim($raw);

		if ($trimmed === '') {
			throw new BillingException('Required timestamp parameter is missing.');
		}

		if (ctype_digit($trimmed)) {
			return (int) $trimmed;
		}

		$ts = strtotime($trimmed . (str_contains($trimmed, 'T') || str_contains($trimmed, 'Z') ? '' : ' UTC'));

		if ($ts === false) {
			throw new BillingException('Invalid timestamp value: ' . $trimmed);
		}

		return $ts;
	}

	/**
	 * Read and decode a JSON declarative config file.
	 *
	 * @param string $path Filesystem path to JSON config file.
	 * @return array<string, mixed>
	 */
	private static function loadJsonConfigFile(string $path): array {
		$trimmed = trim($path);

		if ($trimmed === '' || !is_file($trimmed) || !is_readable($trimmed)) {
			throw new BillingException('Configuration file is missing or unreadable.');
		}

		$raw = file_get_contents($trimmed);

		if (!is_string($raw)) {
			throw new BillingException('Unable to read configuration file.');
		}

		$decoded = json_decode($raw, true);

		if (!is_array($decoded)) {
			throw new BillingException('Configuration file must contain a JSON object.');
		}

		if (isset($decoded['config']) && is_array($decoded['config'])) {
			$decoded = $decoded['config'];
		}

		$out = [];

		foreach ($decoded as $k => $v) {
			$out[(string) $k] = $v;
		}

		return $out;
	}
}
