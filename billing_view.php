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

chdir('../../');
include('./include/auth.php');
include_once('./plugins/billing/setup.php');

if (!billing_page_start()) {
	exit;
}

$billing_repo       = billing_repository();
$billing_forecaster = billing_forecaster();
$billing_counts     = $billing_repo->operationalCounts();
$billing_now        = time();
$billing_start      = (int) gmmktime(0, 0, 0, (int) gmdate('n', $billing_now), 1, (int) gmdate('Y', $billing_now));
$billing_end        = (int) gmmktime(0, 0, 0, (int) gmdate('n', $billing_now) + 1, 1, (int) gmdate('Y', $billing_now));
$billing_forecasts  = array();

foreach ($billing_repo->listContracts(null, 'active') as $contract) {
	$contractId = is_numeric($contract['id'] ?? null) ? (int) $contract['id'] : 0;
	$circuits   = $billing_repo->listCircuitsForContract($contractId);
	$ids        = array();

	foreach ($circuits as $circuit) {
		if (is_numeric($circuit['id'] ?? null)) {
			$ids[] = (int) $circuit['id'];
		}
	}

	$samples             = $billing_repo->fetchSamplesByCircuit($ids, $billing_start, $billing_end);
	$billing_forecasts[] = $billing_forecaster->forecast($contract, $samples, $billing_start, $billing_end, $billing_now);
}

top_header();
html_start_box(__('Billing Usage & Mid-Cycle Burst Forecasts', 'billing'), '100%', false, 3, 'center', '');
print '<tr><td>' . \Somethingwithproof\Billing\Ui\PagesRenderer::renderViewDashboard($billing_forecasts, $billing_counts) . '</td></tr>';
html_end_box();
bottom_footer();
