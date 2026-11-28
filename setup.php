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

/*
 * Cacti includes setup.php on every request regardless of PHP version. Keep
 * this file PHP 8.0 parseable so sites running PHP 8.0 or 8.1 receive a clean
 * refusal instead of a fatal parse error.
 */

/**
 * Check whether the active PHP runtime meets the plugin's minimum PHP 8.2 floor.
 *
 * @return bool
 */
function billing_php_supported(): bool {
	return version_compare(PHP_VERSION, '8.2.0', '>=');
}

/**
 * Bootstrap the plugin's zero-dependency PSR-4 autoloader when PHP >= 8.2.
 *
 * @return bool
 */
function billing_boot(): bool {
	if (!billing_php_supported()) {
		return false;
	}

	require_once(__DIR__ . '/include/autoload.php');

	return true;
}

/**
 * Log and surface a clean refusal when running on PHP < 8.2.
 *
 * @return bool True when PHP is unsupported and execution should stop.
 */
function billing_refuse_unsupported_php(): bool {
	if (billing_php_supported()) {
		return false;
	}

	$message = __('The Billing plugin requires PHP %s or later. This server runs PHP %s, so the plugin was not installed or enabled.', '8.2.0', PHP_VERSION, 'billing');

	cacti_log('ERROR: ' . $message, false, 'BILLING');

	if (PHP_SAPI === 'cli') {
		fwrite(STDERR, $message . PHP_EOL);
	} elseif (function_exists('raise_message')) {
		raise_message('billing_php', $message, MESSAGE_LEVEL_ERROR);
	}

	return true;
}

/**
 * Parse plugin metadata from INFO for Cacti's Plugin Management registry.
 *
 * @return array<string, string>
 */
function plugin_billing_version(): array {
	$info = parse_ini_file(__DIR__ . '/INFO', true);

	if (!is_array($info) || !isset($info['info']) || !is_array($info['info'])) {
		return array();
	}

	$version = array();

	foreach ($info['info'] as $key => $value) {
		$version[(string) $key] = is_scalar($value) ? (string) $value : '';
	}

	return $version;
}

/**
 * Cacti plugin install lifecycle callback.
 *
 * @return void
 */
function plugin_billing_install(): void {
	if (billing_refuse_unsupported_php()) {
		return;
	}

	plugin_billing_upgrade();
}

/**
 * Cacti plugin configuration check lifecycle callback.
 *
 * @return bool
 */
function plugin_billing_check_config(): bool {
	if (billing_refuse_unsupported_php()) {
		return false;
	}

	return plugin_billing_upgrade();
}

/**
 * Run idempotent schema migrations, hook registrations, and version recording.
 *
 * @return bool
 */
function plugin_billing_upgrade(): bool {
	if (!billing_boot()) {
		return false;
	}

	$info   = plugin_billing_version();
	$schema = new \Somethingwithproof\Billing\Schema(false);

	if ($schema->isCurrent() && $schema->recordedPluginVersion() === ($info['version'] ?? '') && $schema->hooksRegistered()) {
		return true;
	}

	if (!$schema->migrate()) {
		return false;
	}

	billing_setup_hooks();
	$schema->recordPluginVersion($info['version'] ?? '0.1.0', $info['author'] ?? '', $info['homepage'] ?? '');

	return true;
}

/**
 * Register Cacti hooks and 3-tier RBAC realms.
 *
 * @return void
 */
function billing_setup_hooks(): void {
	global $config;

	if (!function_exists('replicate_out') && isset($config['library_path']) && is_string($config['library_path']) && is_file($config['library_path'] . '/poller.php')) {
		include_once($config['library_path'] . '/poller.php');
	}

	$enabled = (new \Somethingwithproof\Billing\Schema(false))->pluginEnabled();

	foreach (\Somethingwithproof\Billing\Schema::HOOKS as $hook => $function) {
		api_plugin_register_hook('billing', $hook, $function, 'setup.php', $enabled);
	}

	foreach (\Somethingwithproof\Billing\Pages::realms() as $realm) {
		api_plugin_register_realm('billing', implode(',', $realm['files']), $realm['name'], $realm['default']);
	}
}

/**
 * Cacti plugin uninstall lifecycle callback honoring ledger retention settings.
 *
 * @return void
 */
function plugin_billing_uninstall(): void {
	if (!billing_boot()) {
		return;
	}

	$schema = new \Somethingwithproof\Billing\Schema(false);

	if (read_config_option('billing_uninstall_keep_tables') !== '') {
		$schema->untrackTables();
		cacti_log('NOTE: Uninstall kept plugin_billing tables as configured', false, 'BILLING');
	} else {
		$schema->forgetVersion();
	}
}

/**
 * Load current plugin configuration from Cacti's settings table.
 *
 * @return \Somethingwithproof\Billing\Settings
 */
function billing_settings(): \Somethingwithproof\Billing\Settings {
	$row = array();

	foreach (\Somethingwithproof\Billing\Settings::NAMES as $name) {
		$row[$name] = read_config_option($name);
	}

	return \Somethingwithproof\Billing\Settings::fromRow($row);
}

/**
 * Persist validated plugin configuration into Cacti's settings table.
 *
 * @param \Somethingwithproof\Billing\Settings $settings Validated settings snapshot.
 * @return bool
 */
function billing_save_settings(\Somethingwithproof\Billing\Settings $settings): bool {
	if (!function_exists('db_execute_prepared')) {
		return false;
	}

	foreach ($settings->toCactiSettingsMap() as $name => $value) {
		$ok = db_execute_prepared(
			'REPLACE INTO settings (name, value) VALUES (?, ?)',
			array($name, $value)
		);

		if (!$ok) {
			return false;
		}
	}

	billing_reset_runtime();

	return true;
}

/**
 * Reset cached in-process billing repository and service singletons.
 *
 * @param \Somethingwithproof\Billing\Ledger\BillingRepository|null $repoOverride Optional test repository override.
 * @return void
 */
function billing_reset_runtime(
	?\Somethingwithproof\Billing\Ledger\BillingRepository $repoOverride = null
): void {
	$GLOBALS['__plugin_billing_repo'] = $repoOverride;
}

/**
 * Return the singleton BillingRepository for the active request or CLI process.
 *
 * @return \Somethingwithproof\Billing\Ledger\BillingRepository
 */
function billing_repository(): \Somethingwithproof\Billing\Ledger\BillingRepository {
	if (isset($GLOBALS['__plugin_billing_repo']) && $GLOBALS['__plugin_billing_repo'] instanceof \Somethingwithproof\Billing\Ledger\BillingRepository) {
		return $GLOBALS['__plugin_billing_repo'];
	}

	$adapter = function_exists('db_execute_prepared')
		? new \Somethingwithproof\Billing\Db\CactiDbAdapter(false)
		: \Somethingwithproof\Billing\Db\PdoDbAdapter::createSqliteMemory();

	$repo                             = new \Somethingwithproof\Billing\Ledger\BillingRepository($adapter);
	$GLOBALS['__plugin_billing_repo'] = $repo;

	return $repo;
}

/**
 * Build a StatementService bound to the active BillingRepository.
 *
 * @return \Somethingwithproof\Billing\Statement\StatementService
 */
function billing_statement_service(): \Somethingwithproof\Billing\Statement\StatementService {
	return new \Somethingwithproof\Billing\Statement\StatementService(billing_repository());
}

/**
 * Build a BurstForecaster configured from live plugin settings.
 *
 * @return \Somethingwithproof\Billing\Rating\BurstForecaster
 */
function billing_forecaster(): \Somethingwithproof\Billing\Rating\BurstForecaster {
	$settings = billing_settings();

	return new \Somethingwithproof\Billing\Rating\BurstForecaster(
		new \Somethingwithproof\Billing\Rating\PercentileCalculator(),
		new \Somethingwithproof\Billing\Rating\TariffRater(),
		$settings->burstWarningPct,
		$settings->burstCriticalPct
	);
}

/**
 * Register Console menu entries under Utilities and run automatic upgrade checks.
 *
 * @return void
 */
function billing_config_arrays(): void {
	global $menu;

	if (!billing_boot()) {
		return;
	}

	foreach (\Somethingwithproof\Billing\Pages::menu() as $file => $title) {
		$menu[__('Utilities')]['plugins/billing/' . $file] = $title;
	}

	if (in_array(get_current_page(), \Somethingwithproof\Billing\Pages::upgradeTriggers(), true)) {
		plugin_billing_upgrade();
	}
}

/**
 * Register the Billing tab under Console -> Configuration -> Settings for Admin users.
 *
 * @return void
 */
function billing_config_settings(): void {
	global $tabs, $settings;

	if (PHP_SAPI !== 'cli' && !api_plugin_user_realm_auth(\Somethingwithproof\Billing\Pages::ADMIN)) {
		return;
	}

	if (!billing_boot()) {
		return;
	}

	$tabs['billing']     = __('Billing', 'billing');
	$settings['billing'] = \Somethingwithproof\Billing\Settings::form();
}

/**
 * Register navigation breadcrumbs for plugin_billing Console pages.
 *
 * @param array<string, mixed> $nav Existing Cacti navigation breadcrumb map.
 * @return array<string, mixed>
 */
function billing_draw_navigation_text(array $nav): array {
	global $current_user;

	if (!billing_boot()) {
		return $nav;
	}

	if (get_current_page() === 'settings.php' && (PHP_SAPI === 'cli' || ($current_user['enabled'] ?? '') === 'on')) {
		billing_config_settings();
	}

	return array_merge($nav, \Somethingwithproof\Billing\Pages::navigation());
}

/**
 * Resolve 3-tier realm inheritance: Billing Admin > Billing Operator > Billing View.
 *
 * @return void
 */
function billing_auth_alternate_realms(): void {
	global $user_auth_realm_filenames;

	static $deferred = array();

	foreach ($deferred as $file => $realm) {
		$user_auth_realm_filenames[$file] = $realm;
	}
	$deferred = array();

	if (!api_plugin_is_enabled('billing')) {
		return;
	}

	if (empty($_SESSION['sess_user_id'])) {
		$page = get_current_page();

		if (in_array($page, array('billing_view.php', 'billing_operator.php'), true) && isset($user_auth_realm_filenames[$page])) {
			$deferred[$page]                  = $user_auth_realm_filenames[$page];
			$user_auth_realm_filenames[$page] = -1;
		}

		return;
	}

	$adminRealm    = is_numeric($user_auth_realm_filenames['billing_admin.php'] ?? null) ? (int) $user_auth_realm_filenames['billing_admin.php'] : 0;
	$operatorRealm = is_numeric($user_auth_realm_filenames['billing_operator.php'] ?? null) ? (int) $user_auth_realm_filenames['billing_operator.php'] : 0;

	if ($adminRealm > 0 && is_realm_allowed($adminRealm)) {
		$user_auth_realm_filenames['billing_operator.php'] = $adminRealm;
		$user_auth_realm_filenames['billing_view.php']     = $adminRealm;
	} elseif ($operatorRealm > 0 && is_realm_allowed($operatorRealm)) {
		$user_auth_realm_filenames['billing_view.php'] = $operatorRealm;
	}
}

/**
 * Determine whether the current authenticated user is authorized for the active plugin page.
 *
 * @return bool
 */
function billing_page_allowed(): bool {
	global $user_auth_realm_filenames;

	billing_auth_alternate_realms();
	$page  = get_current_page();
	$realm = is_numeric($user_auth_realm_filenames[$page] ?? null) ? (int) $user_auth_realm_filenames[$page] : 0;

	return $realm > 0 && is_realm_allowed($realm);
}

/**
 * Guard plugin Console page execution (boot check, enabled check, and 3-tier RBAC check).
 *
 * @return bool True when page execution may continue; false after rendering notice or HTTP 403.
 */
function billing_page_start(): bool {
	if (billing_boot() && api_plugin_is_enabled('billing')) {
		if (billing_page_allowed()) {
			return true;
		}

		http_response_code(403);

		return false;
	}

	top_header();
	html_start_box(__('Burstable Transit & Usage Billing', 'billing'), '100%', false, 3, 'center', '');
	print '<tr><td>' . html_escape(billing_php_supported() ? __('The Billing plugin is not enabled.', 'billing') : __('The Billing plugin requires PHP %s or later.', '8.2.0', 'billing')) . '</td></tr>';
	html_end_box();
	bottom_footer();

	return false;
}

/**
 * Poller bottom hook: log mid-cycle burst budget threshold alerts without blocking Cacti polling.
 *
 * @return void
 */
function billing_poller_bottom(): void {
	if (!billing_boot()) {
		return;
	}

	try {
		$repo       = billing_repository();
		$forecaster = billing_forecaster();
		$now        = time();
		$monthStart = (int) gmmktime(0, 0, 0, (int) gmdate('n', $now), 1, (int) gmdate('Y', $now));
		$monthEnd   = (int) gmmktime(0, 0, 0, (int) gmdate('n', $now) + 1, 1, (int) gmdate('Y', $now));

		foreach ($repo->listContracts(null, 'active') as $contract) {
			$contractId = is_numeric($contract['id'] ?? null) ? (int) $contract['id'] : 0;
			$circuits   = $repo->listCircuitsForContract($contractId);

			if ($circuits === array()) {
				continue;
			}

			$ids = array();

			foreach ($circuits as $circuit) {
				if (is_numeric($circuit['id'] ?? null)) {
					$ids[] = (int) $circuit['id'];
				}
			}

			$samples  = $repo->fetchSamplesByCircuit($ids, $monthStart, $monthEnd);
			$forecast = $forecaster->forecast($contract, $samples, $monthStart, $monthEnd, $now);

			if ($forecast['severity'] !== 'ok') {
				\Somethingwithproof\Billing\Log::warning(sprintf(
					'Burst forecast %s for contract %s: %d/%d slots consumed (%.1f%%)',
					strtoupper($forecast['severity']),
					$forecast['contract_code'],
					$forecast['consumed_burst_slots'],
					$forecast['burst_budget_slots'],
					$forecast['budget_consumed_pct']
				));
			}
		}
	} catch (\Throwable $error) {
		\Somethingwithproof\Billing\Log::warning('Poller billing pass skipped: ' . $error->getMessage());
	}
}

/**
 * casc_register_providers hook: register config-only provider for slug "billing".
 *
 * @param mixed $registrar Registrar object supplied by configuration-as-code hook.
 * @return void
 */
function billing_casc_register_providers(mixed $registrar): void {
	if (!billing_boot() || !is_object($registrar)) {
		return;
	}

	\Somethingwithproof\Billing\Integration\CascProvider::register(
		$registrar,
		static function (): \Somethingwithproof\Billing\Settings {
			return billing_settings();
		},
		static function (\Somethingwithproof\Billing\Settings $settings): bool {
			return billing_save_settings($settings);
		}
	);
}

/**
 * doctor_register_checks hook: return a plain list of pre-evaluated billing diagnostic checks.
 *
 * @param mixed $context Context array (for example, ['mode' => 'run'|'list', 'plugin' => 'billing']).
 * @return list<array<string, mixed>>
 */
function billing_doctor_register_checks(mixed $context): array {
	if (!billing_boot()) {
		return array();
	}

	$mode = (is_array($context) && is_string($context['mode'] ?? null) && $context['mode'] === 'list')
		? 'list'
		: 'run';

	return \Somethingwithproof\Billing\Integration\DoctorChecks::buildChecks(
		static function (): \Somethingwithproof\Billing\Ledger\BillingRepository {
			return billing_repository();
		},
		static function (): \Somethingwithproof\Billing\Settings {
			return billing_settings();
		},
		$mode
	);
}

/**
 * doctor_register_debug_info hook: return a plain list of redacted billing debug info sections.
 *
 * @param mixed $context Context array (for example, ['plugin' => 'billing']).
 * @return list<array<string, mixed>>
 */
function billing_doctor_register_debug_info(mixed $context): array {
	if (!billing_boot()) {
		return array();
	}

	return \Somethingwithproof\Billing\Integration\DoctorChecks::buildDebugInfo(
		static function (): \Somethingwithproof\Billing\Ledger\BillingRepository {
			return billing_repository();
		},
		static function (): \Somethingwithproof\Billing\Settings {
			return billing_settings();
		}
	);
}

/**
 * abilities_register hook: register read-only billing abilities.
 *
 * @param mixed $registrar Abilities registrar object.
 * @return void
 */
function billing_abilities_register(mixed $registrar): void {
	if (!billing_boot() || !is_object($registrar)) {
		return;
	}

	\Somethingwithproof\Billing\Integration\McpAbilities::register(
		$registrar,
		static function (): \Somethingwithproof\Billing\Ledger\BillingRepository {
			return billing_repository();
		},
		static function (): \Somethingwithproof\Billing\Rating\BurstForecaster {
			return billing_forecaster();
		},
		static function (): \Somethingwithproof\Billing\Statement\StatementService {
			return billing_statement_service();
		},
		static function (int $userId): bool {
			return \Somethingwithproof\Billing\Integration\McpAbilities::userHasViewRealm($userId);
		}
	);
}

/**
 * api_register_resources hook: return plain-list descriptors for billingAccounts,
 * billingContracts, and billingStatements.
 *
 * @param mixed $resources Existing resource list or context.
 * @return list<array<string, mixed>>
 */
function billing_api_register_resources(mixed $resources): array {
	if (!billing_boot()) {
		return array();
	}

	$built = \Somethingwithproof\Billing\Integration\ApiResources::build(
		static function (): \Somethingwithproof\Billing\Ledger\BillingRepository {
			return billing_repository();
		}
	);

	if (is_array($resources) && array_is_list($resources)) {
		return array_merge($resources, $built);
	}

	return $built;
}
