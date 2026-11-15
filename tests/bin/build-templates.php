<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_pfs, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_pfs
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
 * Build the Parallel Filesystem templates in a disposable Cacti database with
 * Cacti's own save function, and write Cacti's export of them (get_item_xml
 * with dependencies) to templates/pfs-templates.xml. The plugin ships that
 * export, not this script's data:
 *
 *   PFS_TEST_DATABASE=disposable php plugins/pfs/tests/bin/build-templates.php [export-file]
 *
 * Hashes come from TemplateInstaller::hash(), so a rebuild updates the rows
 * it made and exports the same hashes. Must parse on PHP 8.0.
 *
 * Data source types follow ADR 0005 and ADR 0012: capacity and counts are
 * GAUGE with minimum 0; cumulative counters are DERIVE with minimum 0 and a
 * generous maximum that is a plausibility bound, not a reset filter. The
 * maximums are stated per second: 100 GB/s per OST, 10 million operations
 * per second per target, and 100 times that for filesystem aggregates.
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/functions.php');
require_once($config['base_path'] . '/lib/export.php');
require_once($config['base_path'] . '/plugins/pfs/setup.php');

if (getenv('PFS_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set PFS_TEST_DATABASE=disposable\n");
	exit(2);
}

if (!pfs_boot()) {
	fwrite(STDERR, "PHP 8.2 or later is required\n");
	exit(2);
}

use Somethingwithproof\Pfs\Summary;
use Somethingwithproof\Pfs\TemplateInstaller as T;

const PFS_GAUGE  = 1;
const PFS_DERIVE = 3;

const PFS_MAX_BYTES     = '100000000000';
const PFS_MAX_OPS       = '10000000';
const PFS_MAX_FS_BYTES  = '10000000000000';
const PFS_MAX_FS_OPS    = '1000000000';

/** Core's "Get Script Server Data (Indexed)" input and its three index fields. */
const PFS_INDEXED_INPUT = '332111d8b54ac8ce939af87a7eac0c06';
const PFS_INDEXED_FIELDS = array('172b4b0eacee4948c6479f587b62e512', '30fb5d5bcf3d66bb5abe88596f357c26', '31112c85ae4ff821d3b288336288818c');

function pfs_save($table, array $row) {
	$id = (int) db_fetch_cell_prepared("SELECT id FROM `$table` WHERE hash = ?", array($row['hash']));

	$row['id'] = $id > 0 ? $id : 0;
	$saved     = sql_save($row, $table);

	if (!$saved) {
		fwrite(STDERR, "Saving $table {$row['hash']} failed\n");
		exit(1);
	}

	return (int) $saved;
}

function pfs_color($hex) {
	$hex = strtoupper($hex);
	$id  = (int) db_fetch_cell_prepared('SELECT id FROM colors WHERE hex = ?', array($hex));

	if ($id === 0) {
		db_execute_prepared('INSERT INTO colors (hex) VALUES (?)', array($hex));
		$id = (int) db_fetch_cell_prepared('SELECT id FROM colors WHERE hex = ?', array($hex));
	}

	return $id;
}

function pfs_cdef($name, $rpn) {
	$id = pfs_save('cdef', array('hash' => T::hash('cdef', $name), 'name' => $name, 'system' => 0));

	db_execute_prepared('DELETE FROM cdef_items WHERE cdef_id = ?', array($id));
	pfs_save('cdef_items', array('hash' => T::hash('cdef_item', $name . ':1'), 'cdef_id' => $id, 'sequence' => 1, 'type' => 6, 'value' => $rpn));

	return $id;
}

function pfs_gprint($name, $text) {
	return pfs_save('graph_templates_gprint', array('hash' => T::hash('gprint', $name), 'name' => $name, 'gprint_text' => $text));
}

/**
 * A data template with one RRD item per entry of $items.
 *
 * @param array<string, array{0: int, 1: string, 2: int}> $items name => [type, maximum, input field id]
 */
function pfs_data_template($name, $inputId, $title, array $items, array $indexFields = array(), array $hostFields = array()) {
	$dtId  = pfs_save('data_template', array('hash' => T::hash('data_template', $name), 'name' => $name));
	$dtdId = (int) db_fetch_cell_prepared('SELECT id FROM data_template_data WHERE data_template_id = ? AND local_data_id = 0', array($dtId));
	$dtdId = (int) sql_save(array(
		'id'                          => $dtdId,
		'data_template_id'            => $dtId,
		'local_data_id'               => 0,
		'local_data_template_data_id' => 0,
		'data_input_id'               => $inputId,
		'name'                        => $title,
		'active'                      => 'on',
		'rrd_step'                    => 300,
		'data_source_profile_id'      => 1,
	), 'data_template_data');

	// The index fields are set per data source when Cacti creates one from
	// the data query.
	foreach ($indexFields as $fieldId) {
		db_execute_prepared('REPLACE INTO data_input_data (data_input_field_id, data_template_data_id, t_value, value) VALUES (?, ?, ?, ?)', array($fieldId, $dtdId, 'on', ''));
	}

	// A field with a device type code gets its value from the device when
	// Cacti builds the poller cache, but only when the template has a row
	// for it, left empty and not overridable per data source.
	foreach ($hostFields as $fieldId) {
		db_execute_prepared('REPLACE INTO data_input_data (data_input_field_id, data_template_data_id, t_value, value) VALUES (?, ?, ?, ?)', array($fieldId, $dtdId, '', ''));
	}

	$rrd = array();

	foreach ($items as $item => $spec) {
		$rrd[$item] = pfs_save('data_template_rrd', array(
			'hash'                => T::hash('data_template_item', $name . ':' . $item),
			'data_template_id'    => $dtId,
			'local_data_id'       => 0,
			'rrd_maximum'         => $spec[1],
			'rrd_minimum'         => '0',
			'rrd_heartbeat'       => 600,
			'data_source_type_id' => $spec[0],
			'data_source_name'    => $item,
			'data_input_field_id' => $spec[2],
		));
	}

	return array('id' => $dtId, 'rrd' => $rrd);
}

/**
 * A graph template from a list of drawn items. Each item may carry legends:
 * a list of [GPRINT type, label] printed after it; the last legend ends the
 * line.
 *
 * @param list<array<string, mixed>> $items
 */
function pfs_graph_template($name, $title, $label, $base, array $items, $upper = '', $rigid = false) {
	$gtId  = pfs_save('graph_templates', array('hash' => T::hash('graph_template', $name), 'name' => $name));
	$gtgId = (int) db_fetch_cell_prepared('SELECT id FROM graph_templates_graph WHERE graph_template_id = ? AND local_graph_id = 0', array($gtId));

	sql_save(array(
		'id'                            => $gtgId,
		'graph_template_id'             => $gtId,
		'local_graph_id'                => 0,
		'local_graph_template_graph_id' => 0,
		'image_format_id'               => 1,
		't_title'                       => '',
		'title'                         => $title,
		'height'                        => 150,
		'width'                         => 600,
		'upper_limit'                   => $upper === '' ? '100' : $upper,
		'lower_limit'                   => '0',
		'vertical_label'                => $label,
		'auto_scale'                    => $rigid ? '' : 'on',
		'auto_scale_opts'               => 2,
		'auto_scale_rigid'              => $rigid ? 'on' : '',
		'base_value'                    => $base,
		'slope_mode'                    => 'on',
	), 'graph_templates_graph');

	db_execute_prepared('DELETE FROM graph_templates_item WHERE graph_template_id = ? AND local_graph_id = 0', array($gtId));

	$sequence = 0;
	$first    = 0;

	$add = function (array $row) use (&$sequence, $gtId, $name) {
		$sequence++;

		return pfs_save('graph_templates_item', $row + array(
			'hash'                      => T::hash('graph_template_item', $name . ':' . $sequence),
			'graph_template_id'         => $gtId,
			'local_graph_id'            => 0,
			'task_item_id'              => 0,
			'color_id'                  => 0,
			'alpha'                     => 'FF',
			'graph_type_id'             => GRAPH_ITEM_TYPE_COMMENT,
			'cdef_id'                   => 0,
			'consolidation_function_id' => 1,
			'text_format'               => '',
			'value'                     => '',
			'hard_return'               => '',
			'gprint_id'                 => 0,
			'sequence'                  => $sequence,
		));
	};

	foreach ($items as $item) {
		$legends = isset($item['legends']) ? $item['legends'] : array();
		$id      = $add(array(
			'task_item_id'  => $item['rrd'],
			'graph_type_id' => $item['type'],
			'color_id'      => isset($item['color']) ? pfs_color($item['color']) : 0,
			'cdef_id'       => isset($item['cdef']) ? $item['cdef'] : 0,
			'text_format'   => $item['text'],
			'gprint_id'     => in_array($item['type'], array(GRAPH_ITEM_TYPE_GPRINT_LAST, GRAPH_ITEM_TYPE_GPRINT_AVERAGE, GRAPH_ITEM_TYPE_GPRINT_MAX, GRAPH_ITEM_TYPE_GPRINT_MIN), true) ? $item['gprint'] : 0,
			'hard_return'   => $legends === array() ? 'on' : '',
		));

		$first = $first === 0 && $item['type'] !== GRAPH_ITEM_TYPE_GPRINT_LAST ? $id : $first;

		foreach ($legends as $i => $legend) {
			$add(array(
				'task_item_id'  => $item['rrd'],
				'graph_type_id' => $legend[0],
				'cdef_id'       => isset($item['cdef']) ? $item['cdef'] : 0,
				'text_format'   => $legend[1],
				'gprint_id'     => $item['gprint'],
				'hard_return'   => $i === count($legends) - 1 ? 'on' : '',
			));
		}
	}

	// One graph template input, for the first item's color: Cacti 1.2.32
	// and later refuse a graph template export with no <inputs> element.
	$inputId = pfs_save('graph_template_input', array(
		'hash'              => T::hash('graph_template_input', $name . ':color'),
		'graph_template_id' => $gtId,
		'name'              => 'First Item Color',
		'description'       => 'The color of the first item',
		'column_name'       => 'color_id',
	));

	db_execute_prepared('DELETE FROM graph_template_input_defs WHERE graph_template_input_id = ?', array($inputId));
	db_execute_prepared('INSERT INTO graph_template_input_defs (graph_template_input_id, graph_template_item_id) VALUES (?, ?)', array($inputId, $first));

	return $gtId;
}

$kib    = pfs_cdef('Parallel Filesystem KiB to Bytes', 'CURRENT_DATA_SOURCE,1024,*');
$gNum   = pfs_gprint('Parallel Filesystem Number', '%8.2lf %s');
$gPct   = pfs_gprint('Parallel Filesystem Percent', '%5.0lf %%');
$gCount = pfs_gprint('Parallel Filesystem Count', '%5.0lf');

$last = array(array(GRAPH_ITEM_TYPE_GPRINT_LAST, 'Current:'), array(GRAPH_ITEM_TYPE_GPRINT_AVERAGE, 'Average:'), array(GRAPH_ITEM_TYPE_GPRINT_MAX, 'Maximum:'));
$now  = array(array(GRAPH_ITEM_TYPE_GPRINT_LAST, 'Current:'));

/* ---- summary data input, data template, and graphs ---- */

$inputId = pfs_save('data_input', array(
	'hash'         => T::hash('data_input', T::SUMMARY),
	'name'         => T::SUMMARY,
	'input_string' => '<path_cacti>/plugins/pfs/scripts/ss_pfs_summary.php ss_pfs_summary <host_id>',
	'type_id'      => DATA_INPUT_TYPE_PHP_SCRIPT_SERVER,
));

$field = function ($dataName, $friendly, $inOut, $sequence, $typeCode = '') use ($inputId) {
	return pfs_save('data_input_fields', array(
		'hash'          => T::hash('data_input_field', T::SUMMARY . ':' . $dataName),
		'data_input_id' => $inputId,
		'name'          => $friendly,
		'data_name'     => $dataName,
		'input_output'  => $inOut,
		'update_rra'    => $inOut === 'out' ? 'on' : '',
		'sequence'      => $sequence,
		'type_code'     => $typeCode,
		'regexp_match'  => '',
		'allow_nulls'   => '',
	));
};

$hostField = $field('host_id', 'Device ID', 'in', 0, 'host_id');

$summaryItems = array();

foreach (Summary::names() as $i => $name) {
	$fieldId = $field($name, ucfirst(str_replace('_', ' ', $name)), 'out', $i + 1);

	if (in_array($name, Summary::COUNTERS, true)) {
		$summaryItems[$name] = array(PFS_DERIVE, $name === 'md_ops' ? PFS_MAX_FS_OPS : PFS_MAX_FS_BYTES, $fieldId);
	} else {
		$summaryItems[$name] = array(PFS_GAUGE, substr($name, -4) === '_pct' ? '100' : 'U', $fieldId);
	}
}

$summary = pfs_data_template(T::SUMMARY, $inputId, '|host_description| - Parallel Filesystem Summary', $summaryItems, array(), array($hostField));
$s       = $summary['rrd'];

$overview = array(
	pfs_graph_template('Parallel Filesystem Fill', '|host_description| - Fill (percent used)', 'percent', 1000, array(
		array('rrd' => $s['fill_max_pct'], 'type' => GRAPH_ITEM_TYPE_LINE2, 'color' => 'C0392B', 'text' => 'Fullest OST', 'gprint' => $gPct, 'legends' => $last),
		array('rrd' => $s['fill_mean_pct'], 'type' => GRAPH_ITEM_TYPE_LINE1, 'color' => '2471A3', 'text' => 'Mean of OSTs', 'gprint' => $gPct, 'legends' => $last),
		array('rrd' => $s['fill_min_pct'], 'type' => GRAPH_ITEM_TYPE_LINE1, 'color' => '1E8449', 'text' => 'Emptiest OST', 'gprint' => $gPct, 'legends' => $last),
		array('rrd' => $s['inode_max_pct'], 'type' => GRAPH_ITEM_TYPE_LINE1, 'color' => 'B9770E', 'text' => 'Fullest target, inodes', 'gprint' => $gPct, 'legends' => $last),
		array('rrd' => $s['targets_total'], 'type' => GRAPH_ITEM_TYPE_GPRINT_LAST, 'text' => 'Targets:', 'gprint' => $gCount),
		array('rrd' => $s['targets_missing'], 'type' => GRAPH_ITEM_TYPE_GPRINT_LAST, 'text' => 'Left out of the summary:', 'gprint' => $gCount),
		array('rrd' => $s['targets_unhealthy'], 'type' => GRAPH_ITEM_TYPE_GPRINT_LAST, 'text' => 'Unhealthy:', 'gprint' => $gCount),
		// Cacti stores only the data source items a graph references, so age
		// must appear on a graph for Thold to see it.
		array('rrd' => $s['age'], 'type' => GRAPH_ITEM_TYPE_GPRINT_LAST, 'text' => 'Seconds since the oldest observation:', 'gprint' => $gCount),
	), '100', true),
	pfs_graph_template('Parallel Filesystem Imbalance', '|host_description| - Imbalance (fullest minus emptiest OST)', 'percentage points', 1000, array(
		array('rrd' => $s['fill_spread_pct'], 'type' => GRAPH_ITEM_TYPE_AREA, 'color' => '7D3C98', 'text' => 'Spread', 'gprint' => $gPct, 'legends' => $last),
		array('rrd' => $s['targets_missing'], 'type' => GRAPH_ITEM_TYPE_GPRINT_LAST, 'text' => 'Targets left out of the summary:', 'gprint' => $gCount),
	)),
	pfs_graph_template('Parallel Filesystem Capacity', '|host_description| - Capacity (bytes)', 'bytes', 1024, array(
		array('rrd' => $s['kbytes_total'], 'type' => GRAPH_ITEM_TYPE_AREA, 'color' => 'BFC9CA', 'cdef' => $kib, 'text' => 'Total', 'gprint' => $gNum, 'legends' => $now),
		array('rrd' => $s['kbytes_avail'], 'type' => GRAPH_ITEM_TYPE_AREA, 'color' => '52BE80', 'cdef' => $kib, 'text' => 'Available', 'gprint' => $gNum, 'legends' => $now),
	)),
	pfs_graph_template('Parallel Filesystem Throughput', '|host_description| - OST Throughput (bytes per second)', 'bytes per second', 1024, array(
		array('rrd' => $s['read_bytes'], 'type' => GRAPH_ITEM_TYPE_LINE2, 'color' => '1E8449', 'text' => 'Read', 'gprint' => $gNum, 'legends' => $last),
		array('rrd' => $s['write_bytes'], 'type' => GRAPH_ITEM_TYPE_LINE2, 'color' => '2471A3', 'text' => 'Written', 'gprint' => $gNum, 'legends' => $last),
		array('rrd' => $s['ost_contributors'], 'type' => GRAPH_ITEM_TYPE_GPRINT_LAST, 'text' => 'OSTs summed:', 'gprint' => $gCount),
	)),
	pfs_graph_template('Parallel Filesystem Metadata Operations', '|host_description| - Metadata Operations (operations per second)', 'operations per second', 1000, array(
		array('rrd' => $s['md_ops'], 'type' => GRAPH_ITEM_TYPE_LINE2, 'color' => '7D3C98', 'text' => 'All allowlisted operations', 'gprint' => $gNum, 'legends' => $last),
		array('rrd' => $s['mdt_contributors'], 'type' => GRAPH_ITEM_TYPE_GPRINT_LAST, 'text' => 'MDTs summed:', 'gprint' => $gCount),
	)),
);

/* ---- per-target data templates, data queries, and graphs ---- */

$indexedInput  = (int) db_fetch_cell_prepared('SELECT id FROM data_input WHERE hash = ?', array(PFS_INDEXED_INPUT));
$indexedFields = array();

foreach (PFS_INDEXED_FIELDS as $hash) {
	$indexedFields[] = (int) db_fetch_cell_prepared('SELECT id FROM data_input_fields WHERE hash = ?', array($hash));
}

if ($indexedInput === 0 || in_array(0, $indexedFields, true)) {
	fwrite(STDERR, "Cacti's script server indexed input is missing\n");
	exit(1);
}

$backend = \Somethingwithproof\Pfs\BackendRegistry::production()->get('lustre');
$items   = array('ost' => array(), 'mdt' => array());

foreach ($backend->values() as $spec) {
	$type = $spec->targetType->value;

	if ($spec->kind === \Somethingwithproof\Pfs\ValueKind::Gauge) {
		$items[$type][$spec->name] = array(PFS_GAUGE, 'U', 0);
	} else {
		$items[$type][$spec->name] = array(PFS_DERIVE, $spec->unit === 'bytes' ? PFS_MAX_BYTES : PFS_MAX_OPS, 0);
	}
}

// One data template per per-target graph, holding exactly the values that
// graph draws. Cacti stores in an RRD file only the values a graph draws
// when the file is created, and 1.2 never adds one to an existing file, so a
// data source shared by graphs created at different times would leave the
// later graph empty. Script server calls are one per value either way.
$groups = array(
	'OST Capacity'   => array('ost', array('kbytes_total', 'kbytes_free', 'kbytes_avail')),
	'OST Inodes'     => array('ost', array('files_total', 'files_free')),
	'OST Throughput' => array('ost', array('read_bytes', 'write_bytes', 'read_ops', 'write_ops')),
	'MDT Capacity'   => array('mdt', array('kbytes_total', 'kbytes_free', 'kbytes_avail')),
	'MDT Inodes'     => array('mdt', array('files_total', 'files_free')),
	'MDT Operations' => array('mdt', array_values(array_filter(array_keys($items['mdt']), function ($n) { return strpos($n, 'md_') === 0; }))),
);
$dts     = array();
$covered = array('ost' => array(), 'mdt' => array());

foreach ($groups as $graphName => $group) {
	$subset = array();

	foreach ($group[1] as $name) {
		$subset[$name]               = $items[$group[0]][$name];
		$covered[$group[0]][$name] = true;
	}

	$templateName      = T::QUERY_GRAPHS[$group[0] === 'ost' ? 'Parallel Filesystem OSTs' : 'Parallel Filesystem MDTs'][$graphName];
	$dts[$graphName] = pfs_data_template($templateName, $indexedInput, '|host_description| - |query_pfsName| - ' . substr($graphName, 4), $subset, $indexedFields);
}

foreach ($items as $type => $list) {
	if (array_diff(array_keys($list), array_keys($covered[$type])) !== array()) {
		fwrite(STDERR, "A $type value of the backend is on no per-target graph\n");
		exit(1);
	}
}

$capacity = function ($rrd, $name) use ($kib, $gNum, $now) {
	return pfs_graph_template($name, '|host_description| - |query_pfsName| - Capacity (bytes)', 'bytes', 1024, array(
		array('rrd' => $rrd['kbytes_total'], 'type' => GRAPH_ITEM_TYPE_AREA, 'color' => 'BFC9CA', 'cdef' => $kib, 'text' => 'Total', 'gprint' => $gNum, 'legends' => $now),
		array('rrd' => $rrd['kbytes_free'], 'type' => GRAPH_ITEM_TYPE_AREA, 'color' => 'A9DFBF', 'cdef' => $kib, 'text' => 'Free', 'gprint' => $gNum, 'legends' => $now),
		array('rrd' => $rrd['kbytes_avail'], 'type' => GRAPH_ITEM_TYPE_LINE2, 'color' => '1E8449', 'cdef' => $kib, 'text' => 'Available', 'gprint' => $gNum, 'legends' => $now),
	));
};

$inodes = function ($rrd, $name) use ($gNum, $now) {
	return pfs_graph_template($name, '|host_description| - |query_pfsName| - Inodes', 'inodes', 1000, array(
		array('rrd' => $rrd['files_total'], 'type' => GRAPH_ITEM_TYPE_AREA, 'color' => 'BFC9CA', 'text' => 'Total', 'gprint' => $gNum, 'legends' => $now),
		array('rrd' => $rrd['files_free'], 'type' => GRAPH_ITEM_TYPE_AREA, 'color' => 'A9DFBF', 'text' => 'Free', 'gprint' => $gNum, 'legends' => $now),
	));
};

$o = $dts['OST Throughput']['rrd'];

$queryGraphs = array(
	'OST Capacity'   => $capacity($dts['OST Capacity']['rrd'], 'Parallel Filesystem OST Capacity'),
	'OST Inodes'     => $inodes($dts['OST Inodes']['rrd'], 'Parallel Filesystem OST Inodes'),
	'OST Throughput' => pfs_graph_template('Parallel Filesystem OST Throughput', '|host_description| - |query_pfsName| - Throughput (bytes per second)', 'bytes per second', 1024, array(
		array('rrd' => $o['read_bytes'], 'type' => GRAPH_ITEM_TYPE_LINE2, 'color' => '1E8449', 'text' => 'Read', 'gprint' => $gNum, 'legends' => $last),
		array('rrd' => $o['write_bytes'], 'type' => GRAPH_ITEM_TYPE_LINE2, 'color' => '2471A3', 'text' => 'Written', 'gprint' => $gNum, 'legends' => $last),
		array('rrd' => $o['read_ops'], 'type' => GRAPH_ITEM_TYPE_GPRINT_AVERAGE, 'text' => 'Read operations per second, average:', 'gprint' => $gNum),
		array('rrd' => $o['write_ops'], 'type' => GRAPH_ITEM_TYPE_GPRINT_AVERAGE, 'text' => 'Write operations per second, average:', 'gprint' => $gNum),
	)),
	'MDT Capacity'   => $capacity($dts['MDT Capacity']['rrd'], 'Parallel Filesystem MDT Capacity'),
	'MDT Inodes'     => $inodes($dts['MDT Inodes']['rrd'], 'Parallel Filesystem MDT Inodes'),
);

$palette = array('1F77B4', 'FF7F0E', '2CA02C', 'D62728', '9467BD', '8C564B', 'E377C2', '7F7F7F', 'BCBD22', '17BECF', '393B79', '637939', '8C6D31', '843C39', '7B4173', '3182BD', 'E6550D', '31A354', '756BB1', '636363');
$opItems = array();
$i       = 0;

foreach ($dts['MDT Operations']['rrd'] as $name => $rrdId) {
	$opItems[] = array('rrd' => $rrdId, 'type' => GRAPH_ITEM_TYPE_LINE1, 'color' => $palette[$i % count($palette)], 'text' => substr($name, 3), 'gprint' => $gNum, 'legends' => array(array(GRAPH_ITEM_TYPE_GPRINT_AVERAGE, 'Average:')));
	$i++;
}

$queryGraphs['MDT Operations'] = pfs_graph_template('Parallel Filesystem MDT Operations', '|host_description| - |query_pfsName| - Operations (operations per second)', 'operations per second', 1000, $opItems);

$queries = array();

foreach (T::QUERIES as $queryName => $query) {
	$type     = $query[0];
	$queryId  = pfs_save('snmp_query', array(
		'hash'          => T::hash('data_query', $queryName),
		'xml_path'      => '<path_cacti>/resource/script_server/' . $query[1],
		'name'          => $queryName,
		'description'   => $type === 'ost' ? 'Object storage targets of a parallel filesystem' : 'Metadata targets of a parallel filesystem',
		'data_input_id' => $indexedInput,
	));

	$queries[] = $queryId;

	foreach (T::QUERY_GRAPHS[$queryName] as $graphName => $templateName) {
		$sqgId = pfs_save('snmp_query_graph', array(
			'hash'              => T::hash('data_query_graph', $queryName . ':' . $graphName),
			'snmp_query_id'     => $queryId,
			'name'              => $graphName,
			'graph_template_id' => $queryGraphs[$graphName],
		));

		$template = $dts[$graphName];

		// Every item of the graph's data template maps to the query field
		// of the same name.
		db_execute_prepared('DELETE FROM snmp_query_graph_rrd WHERE snmp_query_graph_id = ?', array($sqgId));

		foreach ($template['rrd'] as $item => $rrdId) {
			db_execute_prepared('INSERT INTO snmp_query_graph_rrd (snmp_query_graph_id, data_template_id, data_template_rrd_id, snmp_field_name) VALUES (?, ?, ?, ?)', array($sqgId, $template['id'], $rrdId, $item));
		}

		pfs_save('snmp_query_graph_sv', array(
			'hash'                => T::hash('data_query_sv_graph', $queryName . ':' . $graphName . ':title'),
			'snmp_query_graph_id' => $sqgId,
			'sequence'            => 1,
			'field_name'          => 'title',
			'text'                => '|host_description| - |query_pfsName| - ' . substr($graphName, 4),
		));

		pfs_save('snmp_query_graph_rrd_sv', array(
			'hash'                => T::hash('data_query_sv_data_source', $queryName . ':' . $graphName . ':name'),
			'snmp_query_graph_id' => $sqgId,
			'data_template_id'    => $template['id'],
			'sequence'            => 1,
			'field_name'          => 'name',
			'text'                => '|host_description| - |query_pfsName|',
		));
	}
}

/* ---- device template ---- */

$htId = pfs_save('host_template', array('hash' => T::hash('host_template', T::DEVICE_TEMPLATE), 'name' => T::DEVICE_TEMPLATE));

db_execute_prepared('DELETE FROM host_template_graph WHERE host_template_id = ?', array($htId));
db_execute_prepared('DELETE FROM host_template_snmp_query WHERE host_template_id = ?', array($htId));

foreach ($overview as $gtId) {
	db_execute_prepared('INSERT INTO host_template_graph (host_template_id, graph_template_id) VALUES (?, ?)', array($htId, $gtId));
}

foreach ($queries as $queryId) {
	db_execute_prepared('INSERT INTO host_template_snmp_query (host_template_id, snmp_query_id) VALUES (?, ?)', array($htId, $queryId));
}

$xml = get_item_xml('host_template', $htId, true);

// The export follows the data source profile the data templates use. The
// plugin must not overwrite a site's profile, so the shipped file leaves it
// out and import resolves the profile by its hash, which is Cacti's stock one.
$xml = preg_replace('#\s*<hash_2001[0-9a-f]{34}>.*?</hash_2001[0-9a-f]{34}>#s', '', $xml, -1, $removed);

if ($removed !== 1) {
	fwrite(STDERR, "Expected one data source profile in the export, found $removed\n");
	exit(1);
}

$file = isset($argv[1]) ? $argv[1] : $config['base_path'] . '/plugins/pfs/templates/pfs-templates.xml';

file_put_contents($file, $xml);
print "Wrote $file\n";
