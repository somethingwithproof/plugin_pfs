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
 * The shipped Cacti objects: templates/pfs-templates.xml (Cacti's export,
 * see tests/bin/build-templates.php) and the data query files under
 * resource/script_server/. These checks read the files as data; the
 * container run imports them into a real Cacti.
 */

use Somethingwithproof\Pfs\BackendRegistry;
use Somethingwithproof\Pfs\Summary;
use Somethingwithproof\Pfs\TemplateInstaller;
use Somethingwithproof\Pfs\ValueKind;

function tpl_root(): string {
	return dirname(__DIR__, 2);
}

function tpl_xml(): string {
	return (string) file_get_contents(tpl_root() . '/templates/pfs-templates.xml');
}

/**
 * Every data source item in the export: name => [type id, minimum, maximum].
 *
 * @return list<array{hash: string, name: string, type: string, min: string, max: string}>
 */
function tpl_items(): array {
	// Export tags are hash_, a two digit object type (08 is a data source
	// item), a four digit Cacti version, and the object's hash.
	preg_match_all('#<(hash_08\d{4}[0-9a-f]{32})>\s*<data_source_name>(.*?)</data_source_name>(.*?)</\1>#s', tpl_xml(), $m, PREG_SET_ORDER);
	$items = [];

	foreach ($m as $match) {
		preg_match('#<rrd_minimum>(.*?)</rrd_minimum>#', $match[3], $min);
		preg_match('#<rrd_maximum>(.*?)</rrd_maximum>#', $match[3], $max);
		preg_match('#<data_source_type_id>(.*?)</data_source_type_id>#', $match[3], $type);
		$items[] = ['hash' => $match[1], 'name' => $match[2], 'type' => $type[1] ?? '', 'min' => $min[1] ?? '', 'max' => $max[1] ?? ''];
	}

	return $items;
}

it('exports every object the installer looks for, by hash', function () {
	$xml    = tpl_xml();
	$hashes = [TemplateInstaller::hash('host_template', TemplateInstaller::DEVICE_TEMPLATE), TemplateInstaller::hash('data_input', TemplateInstaller::SUMMARY), TemplateInstaller::hash('data_template', TemplateInstaller::SUMMARY)];

	foreach (TemplateInstaller::OVERVIEW as $name) {
		$hashes[] = TemplateInstaller::hash('graph_template', $name);
	}

	foreach (TemplateInstaller::QUERY_GRAPHS as $graphs) {
		foreach ($graphs as $name) {
			$hashes[] = TemplateInstaller::hash('graph_template', $name);
			$hashes[] = TemplateInstaller::hash('data_template', $name);
		}
	}

	foreach (array_keys(TemplateInstaller::QUERIES) as $name) {
		$hashes[] = TemplateInstaller::hash('data_query', $name);
	}

	foreach ($hashes as $hash) {
		expect($xml)->toMatch('/<hash_\d{6}' . $hash . '>/');
	}
});

it('stores counters as DERIVE with minimum 0 and everything else as GAUGE with minimum 0', function () {
	$counters = Summary::COUNTERS;

	foreach (BackendRegistry::production()->get('lustre')->values() as $spec) {
		if ($spec->kind === ValueKind::Counter) {
			$counters[] = $spec->name;
		}
	}

	$items = tpl_items();
	expect($items)->not->toBeEmpty();

	foreach ($items as $item) {
		expect($item['type'])->toBe(in_array($item['name'], $counters, true) ? '3' : '1', $item['name'])
			->and($item['min'])->toBe('0', $item['name'])
			->and(strlen($item['name']))->toBeLessThanOrEqual(19, $item['name']);
	}
});

it('stores every backend value on some per-target data template and every summary value', function () {
	$names = array_count_values(array_column(tpl_items(), 'name'));

	foreach (BackendRegistry::production()->get('lustre')->values() as $spec) {
		expect($names)->toHaveKey($spec->name);
	}

	foreach (Summary::names() as $name) {
		expect($names)->toHaveKey($name);
	}
});

// Cacti creates and updates only the data source items some graph item
// references, so a summary value no graph draws never reaches its RRD or
// Thold.
it('draws every summary value on some graph', function () {
	preg_match_all('#<task_item_id>(hash_08\d{4}[0-9a-f]{32})</task_item_id>#', tpl_xml(), $m);
	$drawn = array_flip($m[1]);

	foreach (tpl_items() as $item) {
		if (in_array($item['name'], Summary::names(), true)) {
			expect(isset($drawn[$item['hash']]))->toBeTrue($item['name'] . ' is not on any graph');
		}
	}
});

it('ships no profile, no core attribution, and the query paths under the resource tree', function () {
	$xml = tpl_xml();

	expect($xml)->not->toMatch('/<hash_20\d{4}/')
		->and($xml)->not->toContain('Cacti Group')
		->and($xml)->not->toContain('cacti.net');

	preg_match_all('#<xml_path>(.*?)</xml_path>#', $xml, $m);
	expect($m[1])->toBe(array_map(static fn (array $q): string => '&lt;path_cacti&gt;/resource/script_server/' . $q[1], array_values(TemplateInstaller::QUERIES)));
});

it('lists each backend value of a target type as an output of that type\'s data query', function () {
	foreach (TemplateInstaller::QUERIES as [$type, $file]) {
		$query = simplexml_load_file(tpl_root() . '/resource/script_server/' . $file);
		expect($query)->not->toBeFalse();

		expect((string) $query->script_path)->toBe('|path_cacti|/plugins/pfs/scripts/ss_pfs_targets.php')
			->and((string) $query->script_function)->toBe('ss_pfs_targets')
			->and((string) $query->arg_prepend)->toBe('|host_id| ' . $type);

		$outputs = [];

		foreach ($query->fields->children() as $name => $field) {
			if ((string) $field->direction === 'output') {
				$outputs[] = $name;
				expect((string) $field->query_name)->toBe($name);
			}
		}

		$expected = [];

		foreach (BackendRegistry::production()->get('lustre')->values() as $spec) {
			if ($spec->targetType->value === $type) {
				$expected[] = $spec->name;
			}
		}

		sort($outputs);
		sort($expected);
		expect($outputs)->toBe($expected);
	}
});
