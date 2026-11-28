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

$GLOBALS['__billing_log']     = [];
$GLOBALS['__billing_options'] = [];
$GLOBALS['__billing_db_mock'] = null;
$GLOBALS['__billing_hooks']   = [];
$GLOBALS['__billing_realms']  = [];
$GLOBALS['__billing_tables']  = [];

if (!defined('MESSAGE_LEVEL_ERROR')) {
	define('MESSAGE_LEVEL_ERROR', 2);
}

if (!function_exists('__')) {
	function __() {
		$args = func_get_args();

		if (count($args) === 1 || preg_match('/%(%|c|[-0-9.]*[bdeEfFgGhHosuxX])/', (string) $args[0]) !== 1) {
			return (string) $args[0];
		}

		return call_user_func_array('sprintf', $args);
	}
}

if (!function_exists('cacti_log')) {
	function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '') {
		$GLOBALS['__billing_log'][] = ['message' => (string) $string, 'environ' => (string) $environ];

		return true;
	}
}

if (!function_exists('html_escape')) {
	function html_escape($string) {
		return htmlspecialchars((string) $string, ENT_QUOTES, 'UTF-8', false);
	}
}

if (!function_exists('read_config_option')) {
	function read_config_option($config_name, $force = false) {
		return $GLOBALS['__billing_options'][$config_name] ?? '';
	}
}

if (!function_exists('get_current_page')) {
	function get_current_page() {
		return $GLOBALS['__billing_current_page'] ?? 'index.php';
	}
}

if (!function_exists('api_plugin_is_enabled')) {
	function api_plugin_is_enabled($plugin) {
		return (bool) ($GLOBALS['__billing_enabled'] ?? true);
	}
}

if (!function_exists('api_plugin_user_realm_auth')) {
	function api_plugin_user_realm_auth($filename) {
		return (bool) ($GLOBALS['__billing_realm_auth'][$filename] ?? true);
	}
}

if (!function_exists('is_realm_allowed')) {
	function is_realm_allowed($realm, $user = 0) {
		return in_array((int) $realm, $GLOBALS['__billing_allowed_realms'] ?? [], true);
	}
}

if (!function_exists('api_plugin_db_table_create')) {
	function api_plugin_db_table_create($plugin, $table, $data) {
		$GLOBALS['__billing_tables'][$table] = $data;

		return true;
	}
}

if (!function_exists('api_plugin_register_hook')) {
	function api_plugin_register_hook($plugin, $hook, $function, $file, $enable = false) {
		$GLOBALS['__billing_hooks'][$hook] = $function;

		return true;
	}
}

if (!function_exists('api_plugin_register_realm')) {
	function api_plugin_register_realm($plugin, $file, $display, $default = false) {
		$GLOBALS['__billing_realms'][$file] = $display;

		return true;
	}
}

if (!function_exists('db_execute_prepared')) {
	function db_execute_prepared($sql, $parms = [], $log = true, $db_conn = false) {
		if (is_callable($GLOBALS['__billing_db_mock'])) {
			return ($GLOBALS['__billing_db_mock'])('execute', $sql, $parms);
		}

		return true;
	}
}

if (!function_exists('db_fetch_cell_prepared')) {
	function db_fetch_cell_prepared($sql, $parms = [], $col_name = '', $log = true, $db_conn = false) {
		if (is_callable($GLOBALS['__billing_db_mock'])) {
			return ($GLOBALS['__billing_db_mock'])('cell', $sql, $parms);
		}

		return false;
	}
}

if (!function_exists('db_fetch_row_prepared')) {
	function db_fetch_row_prepared($sql, $parms = [], $log = true, $db_conn = false) {
		if (is_callable($GLOBALS['__billing_db_mock'])) {
			return ($GLOBALS['__billing_db_mock'])('row', $sql, $parms);
		}

		return [];
	}
}

if (!function_exists('db_fetch_assoc_prepared')) {
	function db_fetch_assoc_prepared($sql, $parms = [], $log = true, $db_conn = false) {
		if (is_callable($GLOBALS['__billing_db_mock'])) {
			return ($GLOBALS['__billing_db_mock'])('assoc', $sql, $parms);
		}

		return [];
	}
}

if (!function_exists('db_fetch_insert_id')) {
	function db_fetch_insert_id($db_conn = false) {
		if (is_callable($GLOBALS['__billing_db_mock'])) {
			return ($GLOBALS['__billing_db_mock'])('insert_id', '', []);
		}

		return 1;
	}
}

if (!function_exists('db_affected_rows')) {
	function db_affected_rows($db_conn = false) {
		if (is_callable($GLOBALS['__billing_db_mock'])) {
			return ($GLOBALS['__billing_db_mock'])('affected', '', []);
		}

		return 1;
	}
}

if (!function_exists('top_header')) {
	function top_header() {
		print '<header>';
	}
}

if (!function_exists('html_start_box')) {
	function html_start_box($title = '', $size = '100%', $div = false, $cell_padding = 3, $align = 'center', $add_text = '') {
		print '<table>';
	}
}

if (!function_exists('html_end_box')) {
	function html_end_box($trailing_br = true, $div = true) {
		print '</table>';
	}
}

if (!function_exists('bottom_footer')) {
	function bottom_footer() {
		print '</footer>';
	}
}

$GLOBALS['config'] = [
	'base_path'    => dirname(__DIR__, 3),
	'library_path' => dirname(__DIR__, 3) . '/lib',
	'poller_id'    => 1,
];

$called_by_script_server = true;

require_once dirname(__DIR__) . '/include/autoload.php';
require_once dirname(__DIR__) . '/setup.php';
require_once dirname(__DIR__) . '/scripts/ss_billing_stats.php';
