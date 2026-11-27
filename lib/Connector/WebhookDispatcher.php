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

namespace Somethingwithproof\Billing\Connector;

use Somethingwithproof\Billing\Ledger\BillingException;
use Somethingwithproof\Billing\Ledger\BillingRepository;
use Somethingwithproof\Billing\Security\OutboundGuard;

/**
 * Dispatches HMAC-SHA256 signed billing statement webhooks through OutboundGuard
 * and records every delivery outcome in plugin_billing_export_log.
 */
final class WebhookDispatcher {
	/**
	 * HTTP header carrying the HMAC-SHA256 payload signature.
	 */
	public const SIGNATURE_HEADER = 'X-Billing-Signature-256';

	/**
	 * HTTP header carrying the Unix signature timestamp for replay protection.
	 */
	public const TIMESTAMP_HEADER = 'X-Billing-Timestamp';

	/**
	 * Optional HTTP transport callback for deterministic unit tests.
	 *
	 * @var (callable(array{url: string, host: string, port: int, ips: list<string>}, list<string>, string): array{status: int, body: string, error: string})|null
	 */
	private $transport;

	/**
	 * Construct a WebhookDispatcher.
	 *
	 * @param OutboundGuard $guard SSRF and HTTPS outbound guard.
	 * @param BillingRepository $repository Repository for recording export logs.
	 * @param ConnectorPayloadBuilder $payloadBuilder ERP adapter payload builder.
	 * @param (callable(array{url: string, host: string, port: int, ips: list<string>}, list<string>, string): array{status: int, body: string, error: string})|null $transport Optional test HTTP transport.
	 */
	public function __construct(
		private readonly OutboundGuard $guard,
		private readonly BillingRepository $repository,
		private readonly ConnectorPayloadBuilder $payloadBuilder = new ConnectorPayloadBuilder(),
		?callable $transport = null,
	) {
		$this->transport = $transport;
	}

	/**
	 * Compute the HMAC-SHA256 signature header value over "<timestamp>.<body>".
	 *
	 * @param string $payload JSON request body.
	 * @param string $secret Shared webhook signing secret.
	 * @param int $timestamp Unix timestamp.
	 * @return string Signature formatted as "sha256=<64-hex>".
	 */
	public static function sign(string $payload, string $secret, int $timestamp): string {
		if ($secret === '') {
			throw new BillingException('Webhook signing secret must not be empty.');
		}

		return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
	}

	/**
	 * Verify an incoming or logged webhook signature with replay-window protection.
	 *
	 * @param string $payload Raw JSON body.
	 * @param string $secret Shared webhook signing secret.
	 * @param string $signatureHeader Value of X-Billing-Signature-256.
	 * @param int $timestamp Value of X-Billing-Timestamp.
	 * @param int $maxSkewSeconds Maximum allowed clock skew in seconds (default 300).
	 * @param int|null $now Optional current timestamp override.
	 * @return bool
	 */
	public static function verifySignature(
		string $payload,
		string $secret,
		string $signatureHeader,
		int $timestamp,
		int $maxSkewSeconds = 300,
		?int $now = null,
	): bool {
		if ($secret === '' || !str_starts_with($signatureHeader, 'sha256=')) {
			return false;
		}

		$current = $now ?? time();

		if (abs($current - $timestamp) > $maxSkewSeconds) {
			return false;
		}

		$expected = self::sign($payload, $secret, $timestamp);

		return hash_equals($expected, $signatureHeader);
	}

	/**
	 * Build, sign, validate, and dispatch a statement payload to the account's ERP endpoint.
	 *
	 * @param array<string, mixed> $account Billing account row.
	 * @param array<string, mixed> $statement Finalized statement row.
	 * @param string $signingSecret Decrypted HMAC signing secret.
	 * @param int|null $timestamp Optional Unix timestamp override.
	 * @return array{ok: bool, http_status: int, signature: string, timestamp: int, log_id: int, error: string}
	 */
	public function dispatchStatement(
		array $account,
		array $statement,
		string $signingSecret,
		?int $timestamp = null,
	): array {
		$statementId   = is_numeric($statement['id'] ?? null) ? (int) $statement['id'] : 0;
		$connectorType = is_string($account['connector_type'] ?? null) ? $account['connector_type'] : 'webhook';
		$targetUrl     = is_string($account['webhook_url'] ?? null) ? trim($account['webhook_url']) : '';
		$ts            = $timestamp ?? time();

		$payload = $this->payloadBuilder->build($connectorType, $account, $statement);
		$body    = json_encode($payload, JSON_UNESCAPED_SLASHES);

		if (!is_string($body)) {
			throw new BillingException('Unable to JSON-encode ERP webhook payload.');
		}

		$signature = self::sign($body, $signingSecret, $ts);

		try {
			$validated = $this->guard->validateUrl($targetUrl);
		} catch (BillingException $error) {
			$logId = $this->repository->recordExportLog(
				$statementId,
				$connectorType,
				$targetUrl,
				'failed',
				0,
				1,
				'',
				$error->getMessage()
			);

			return [
				'ok'          => false,
				'http_status' => 0,
				'signature'   => $signature,
				'timestamp'   => $ts,
				'log_id'      => $logId,
				'error'       => $error->getMessage(),
			];
		}

		$headers = [
			'Content-Type: application/json',
			'User-Agent: cacti-plugin-billing/0.1.0',
			self::TIMESTAMP_HEADER . ': ' . $ts,
			self::SIGNATURE_HEADER . ': ' . $signature,
		];

		$response   = $this->send($validated, $headers, $body);
		$httpStatus = $response['status'];
		$ok         = $httpStatus >= 200 && $httpStatus < 300 && $response['error'] === '';
		$digest     = $response['body'] !== '' ? hash('sha256', $response['body']) : '';
		$logId      = $this->repository->recordExportLog(
			$statementId,
			$connectorType,
			$validated['url'],
			$ok ? 'sent' : 'failed',
			$httpStatus,
			1,
			$digest,
			$response['error']
		);

		return [
			'ok'          => $ok,
			'http_status' => $httpStatus,
			'signature'   => $signature,
			'timestamp'   => $ts,
			'log_id'      => $logId,
			'error'       => $response['error'],
		];
	}

	/**
	 * Execute the bounded HTTPS POST via injectable transport or hardened cURL.
	 *
	 * @param array{url: string, host: string, port: int, ips: list<string>} $validated Validated endpoint descriptor.
	 * @param list<string> $headers HTTP headers.
	 * @param string $body Request body.
	 * @return array{status: int, body: string, error: string}
	 */
	private function send(array $validated, array $headers, string $body): array {
		if ($this->transport !== null) {
			return ($this->transport)($validated, $headers, $body);
		}

		if (!function_exists('curl_init')) {
			return ['status' => 0, 'body' => '', 'error' => 'cURL extension is not available.'];
		}

		$ch = curl_init();

		if ($ch === false) {
			return ['status' => 0, 'body' => '', 'error' => 'Unable to initialize cURL handle.'];
		}

		$buffer   = '';
		$maxBytes = $this->guard->maxResponseBytes;
		$options  = $this->guard->buildCurlOptions($validated, $headers, $body);

		$options[CURLOPT_WRITEFUNCTION] = static function (mixed $handle, string $chunk) use (&$buffer, $maxBytes): int {
			$len = strlen($chunk);

			if (strlen($buffer) + $len > $maxBytes) {
				$buffer .= substr($chunk, 0, max(0, $maxBytes - strlen($buffer)));

				return 0;
			}

			$buffer .= $chunk;

			return $len;
		};

		curl_setopt_array($ch, $options);
		curl_exec($ch);

		$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err    = curl_error($ch);
		curl_close($ch);

		return [
			'status' => $status,
			'body'   => $buffer,
			'error'  => $err,
		];
	}
}
