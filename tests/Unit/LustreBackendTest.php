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

use Somethingwithproof\Pfs\Condition;
use Somethingwithproof\Pfs\LustreBackend;
use Somethingwithproof\Pfs\ReadError;
use Somethingwithproof\Pfs\TargetType;
use Somethingwithproof\Pfs\ValueKind;
use Somethingwithproof\Pfs\ValueSpec;

function pfs_fixture(string $name): string {
	return (string) file_get_contents(dirname(__DIR__) . '/fixtures/lustre/' . $name);
}

it('reads capacity, flags, counters, and recovery from an OSS', function () {
	$report = (new LustreBackend())->parse(pfs_fixture('oss-2.15.txt'));

	expect($report->usable())->toBeTrue()
		->and($report->partial)->toBeFalse()
		->and($report->version)->toBe('2.15.5')
		->and(array_keys($report->targets))->toBe(['scratch-OST0000', 'scratch-OST0001'])
		->and($report->unexpectedSections)->toBe(0)
		->and($report->rejectedNames)->toBe(0);

	$ost0 = $report->targets['scratch-OST0000'];

	expect($ost0->values['kbytes_total'])->toBe(1000000000.0)
		->and($ost0->values['kbytes_free'])->toBe(400000000.0)
		->and($ost0->values['files_free'])->toBe(60000000.0)
		->and($ost0->values['read_ops'])->toBe(1200.0)
		->and($ost0->values['read_bytes'])->toBe(734003200.0)
		->and($ost0->values['write_ops'])->toBe(800.0)
		->and($ost0->values['write_bytes'])->toBe(524288000.0)
		->and($ost0->startMarker)->toBe('1731000000.000000001')
		->and($ost0->condition)->toBe(Condition::InService)
		->and($ost0->recovery)->toBe('COMPLETE');

	$ost1 = $report->targets['scratch-OST0001'];

	// A real zero is zero; a counter line the stats file did not print is unknown.
	expect($ost1->values['kbytes_avail'])->toBe(0.0)
		->and($ost1->values['read_ops'])->toBe(10.0)
		->and($ost1->values['write_ops'])->toBeNull()
		->and($ost1->values['write_bytes'])->toBeNull()
		->and($ost1->condition)->toBe(Condition::Degraded)
		->and($ost1->recovery)->toBe('RECOVERING');
});

it('reads MDT operations and leaves unprinted ones unknown', function () {
	$report = (new LustreBackend())->parse(pfs_fixture('mds-2.17.txt'));
	$mdt    = $report->targets['scratch-MDT0000'];

	expect($report->version)->toBe('2.17.0')
		->and($mdt->id->type)->toBe(TargetType::Mdt)
		->and($mdt->values['md_open'])->toBe(50000.0)
		->and($mdt->values['md_par_rename_file'])->toBe(12.0)
		->and($mdt->values['md_mkdir'])->toBeNull()
		->and($mdt->values)->not->toHaveKey('weird_new_op')
		->and($mdt->values)->not->toHaveKey('read_bytes')
		->and($mdt->condition)->toBe(Condition::Unknown);
});

it('gives the same report whatever order the sections come in', function () {
	$backend = new LustreBackend();

	expect($backend->parse(pfs_fixture('oss-reordered.txt')))->toEqual($backend->parse(pfs_fixture('oss-2.15.txt')));
});

it('turns truncated, failed, and cut-off sections into unknown values, not zeros', function () {
	$report = (new LustreBackend())->parse(pfs_fixture('truncated.txt'));
	$ost    = $report->targets['scratch-OST0000'];

	expect($report->usable())->toBeTrue()
		->and($report->partial)->toBeTrue()
		->and($ost->values['kbytes_total'])->toBe(1000000000.0)
		->and($ost->values['kbytes_free'])->toBeNull()
		->and($ost->values['read_bytes'])->toBeNull()
		->and($ost->values['read_ops'])->toBeNull()
		->and($ost->startMarker)->toBeNull();
});

it('rejects an answer without the end marker as incomplete', function () {
	$report = (new LustreBackend())->parse(pfs_fixture('incomplete.txt'));

	expect($report->usable())->toBeFalse()
		->and($report->error())->toBe(ReadError::Incomplete)
		->and($report->targets)->toBe([]);
});

it('rejects output after the end marker as incomplete', function () {
	$report = (new LustreBackend())->parse(pfs_fixture('trailing.txt'));

	expect($report->error())->toBe(ReadError::Incomplete)
		->and($report->targets)->toBe([]);
});

it('rejects an answer that is not the read script as unframed', function (string $output) {
	$report = (new LustreBackend())->parse($output);

	expect($report->error())->toBe(ReadError::Unframed)
		->and($report->targets)->toBe([]);
})->with([
	'shell session' => [pfs_fixture('unframed.txt')],
	'empty'         => [''],
	'leading space' => [' #pfs-read 1' . "\n#pfs-read end\n"],
	'other version' => ["#pfs-read 2\n#pfs-read end\n"],
]);

it('counts sections it does not read and names it rejects, and stores neither', function () {
	$report = (new LustreBackend())->parse(pfs_fixture('hostile.txt'));
	$ost    = $report->targets['scratch-OST0000'];

	expect($report->usable())->toBeTrue()
		->and($report->version)->toBeNull()
		->and($report->unexpectedSections)->toBe(5)
		->and($report->rejectedNames)->toBe(3)
		->and(array_keys($report->targets))->toBe(['other-OST0007', 'scratch-OST0000'])
		->and($ost->values['kbytes_total'])->toBe(1000000000.0)
		->and($ost->values['kbytes_free'])->toBeNull()
		->and($ost->values['kbytes_avail'])->toBeNull()
		->and($ost->condition)->toBe(Condition::Unknown);
});

it('uses neither copy of a section or counter printed twice', function () {
	$report = (new LustreBackend())->parse(pfs_fixture('duplicate.txt'));
	$ost    = $report->targets['scratch-OST0000'];

	expect($report->partial)->toBeTrue()
		->and($ost->values['kbytes_total'])->toBeNull()
		->and($ost->values['read_ops'])->toBeNull()
		->and($ost->values['read_bytes'])->toBeNull()
		->and($ost->values['write_bytes'])->toBe(524288000.0);
});

it('leaves malformed numbers unknown', function () {
	$ost = (new LustreBackend())->parse(pfs_fixture('corrupted.txt'))->targets['scratch-OST0000'];

	expect($ost->values['read_ops'])->toBeNull()
		->and($ost->values['read_bytes'])->toBeNull()
		->and($ost->values['write_ops'])->toBe(800.0)
		->and($ost->values['kbytes_total'])->toBeNull()
		->and($ost->values['files_total'])->toBeNull();
});

it('declares value names that fit an RRD data source name', function () {
	$names = [];

	foreach ((new LustreBackend())->values() as $spec) {
		expect($spec->name)->toMatch(ValueSpec::NAME_PATTERN);
		$names[] = $spec->targetType->value . '/' . $spec->name;
	}

	expect($names)->toBe(array_values(array_unique($names)));
});

it('declares the stats values as counters and capacity as gauges', function () {
	$kinds = [];

	foreach ((new LustreBackend())->values() as $spec) {
		$kinds[$spec->name] = $spec->kind;
	}

	expect($kinds['kbytes_free'])->toBe(ValueKind::Gauge)
		->and($kinds['read_bytes'])->toBe(ValueKind::Counter)
		->and($kinds['md_open'])->toBe(ValueKind::Counter);
});
