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

use Somethingwithproof\Pfs\BeegfsBackend;
use Somethingwithproof\Pfs\Condition;
use Somethingwithproof\Pfs\TargetType;
use Somethingwithproof\Pfs\ValueKind;

it('declares BeeGFS per-interval I/O metrics as gauges rather than cumulative counters', function () {
	$backend = new BeegfsBackend();

	expect($backend->id())->toBe('beegfs')
		->and($backend->label())->toBe('BeeGFS');

	foreach ($backend->values() as $spec) {
		expect($spec->kind)->toBe(ValueKind::Gauge, $spec->name . ' must be a gauge in BeeGFS');
	}
});

it('parses BeeGFS 7.4 and 8.x storage and metadata targets with byte-to-KiB conversion and conditions', function (string $versionLine, string $expectedVersion) {
	$fixture = implode("\n", [
		BeegfsBackend::PROTOCOL,
		'== version',
		$versionLine,
		'== storage_targets',
		'target fs=beegfs1 id=1 reachability=online consistency=GOOD disk_space_total=10737418240 disk_space_free=2147483648 inodes_total=1000000 inodes_free=750000 work_requests=420 queued_requests=3 disk_read_bytes=52428800 disk_write_bytes=26214400',
		'target fs=beegfs1 id=2 reachability=probably-offline consistency=NEEDS_RESYNC disk_space_total=10737418240 disk_space_free=5368709120 inodes_total=1000000 inodes_free=900000 work_requests=10 queued_requests=12',
		'target fs=beegfs1 id=3 reachability=offline consistency=BAD disk_space_total=10737418240 disk_space_free=0 inodes_total=1000000 inodes_free=0',
		'== meta_targets',
		'target fs=beegfs1 id=10 reachability=online consistency=GOOD disk_space_total=2147483648 disk_space_free=1073741824 inodes_total=50000000 inodes_free=45000000 work_requests=900 queued_requests=1',
		BeegfsBackend::END,
	]) . "\n";

	$report = (new BeegfsBackend())->parse($fixture);

	expect($report->framed)->toBeTrue()
		->and($report->complete)->toBeTrue()
		->and($report->partial)->toBeFalse()
		->and($report->version)->toBe($expectedVersion)
		->and($report->rejectedNames)->toBe(0)
		->and(array_keys($report->targets))->toBe([
			'beegfs1-MDT000a',
			'beegfs1-OST0001',
			'beegfs1-OST0002',
			'beegfs1-OST0003',
		]);

	$ost1 = $report->targets['beegfs1-OST0001'];
	expect($ost1->id->type)->toBe(TargetType::Ost)
		->and($ost1->condition)->toBe(Condition::InService)
		->and($ost1->values['kbytes_total'])->toBe(10485760.0)
		->and($ost1->values['kbytes_free'])->toBe(2097152.0)
		->and($ost1->values['kbytes_avail'])->toBe(2097152.0)
		->and($ost1->values['files_total'])->toBe(1000000.0)
		->and($ost1->values['files_free'])->toBe(750000.0)
		->and($ost1->values['work_requests'])->toBe(420.0)
		->and($ost1->values['queued_requests'])->toBe(3.0)
		->and($ost1->values['read_bytes_int'])->toBe(52428800.0)
		->and($ost1->values['write_bytes_int'])->toBe(26214400.0);

	expect($report->targets['beegfs1-OST0002']->condition)->toBe(Condition::Degraded)
		->and($report->targets['beegfs1-OST0003']->condition)->toBe(Condition::NoCreate);

	$mdt = $report->targets['beegfs1-MDT000a'];
	expect($mdt->id->type)->toBe(TargetType::Mdt)
		->and($mdt->condition)->toBe(Condition::InService)
		->and($mdt->values['kbytes_total'])->toBe(2097152.0)
		->and($mdt->values['work_requests'])->toBe(900.0)
		->and($mdt->values)->not->toHaveKey('read_bytes_int');
})->with([
	'BeeGFS 7.4' => ['beegfs: 7.4.6', '7.4.6'],
	'BeeGFS 8.x' => ['8.1.0', '8.1.0'],
]);

it('flags duplicate targets within a section as ambiguous and partial', function () {
	$fixture = implode("\n", [
		BeegfsBackend::PROTOCOL,
		'== storage_targets',
		'target fs=beegfs1 id=1 reachability=online consistency=GOOD disk_space_total=102400 disk_space_free=51200',
		'target fs=beegfs1 id=1 reachability=online consistency=GOOD disk_space_total=102400 disk_space_free=10240',
		BeegfsBackend::END,
	]) . "\n";

	$report = (new BeegfsBackend())->parse($fixture);

	expect($report->partial)->toBeTrue()
		->and($report->targets['beegfs1-OST0001']->condition)->toBe(Condition::Unknown)
		->and($report->targets['beegfs1-OST0001']->values['kbytes_total'])->toBeNull();
});

it('rejects hostile target names, negative values, and framing errors', function () {
	$backend = new BeegfsBackend();
	$fixture = implode("\n", [
		BeegfsBackend::PROTOCOL,
		'== storage_targets',
		'target fs=../evil id=1 disk_space_total=1024',
		'target fs=beegfs1 id=99999 disk_space_total=1024',
		'target fs=beegfs1 id=5 reachability=online consistency=GOOD disk_space_total=-10 disk_space_free=NaN',
		'== bogus',
		'line',
		BeegfsBackend::END,
	]) . "\n";

	$report = $backend->parse($fixture);

	expect($report->rejectedNames)->toBe(2)
		->and($report->unexpectedSections)->toBe(1)
		->and($report->targets['beegfs1-OST0005']->values['kbytes_total'])->toBeNull()
		->and($report->targets['beegfs1-OST0005']->values['kbytes_free'])->toBeNull()
		->and($backend->parse('raw')->framed)->toBeFalse()
		->and($backend->parse(BeegfsBackend::PROTOCOL . "\n" . BeegfsBackend::END . "\nextra")->complete)->toBeFalse();
});
