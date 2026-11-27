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
use Somethingwithproof\Pfs\Continuity;
use Somethingwithproof\Pfs\GpfsBackend;
use Somethingwithproof\Pfs\Observation;
use Somethingwithproof\Pfs\ResetDetector;
use Somethingwithproof\Pfs\TargetType;
use Somethingwithproof\Pfs\ValueKind;

it('declares GPFS identity, privilege model, and counter/gauge specs', function () {
	$backend = new GpfsBackend();

	expect($backend->id())->toBe('gpfs')
		->and($backend->label())->toBe('IBM Storage Scale (GPFS)')
		->and(GpfsBackend::PRIVILEGE_MODEL['cacti_host_root_required'])->toBeFalse()
		->and(GpfsBackend::PRIVILEGE_MODEL['node_wrapper_only'])->toBeTrue()
		->and(GpfsBackend::PRIVILEGE_MODEL['allowed_mmpmon_requests'])->toContain('fs_io_s', 'io_s')
		->and(GpfsBackend::PRIVILEGE_MODEL['mutating_requests_banned'])->toContain('reset', 'vio_s_reset');

	$kinds = [];

	foreach ($backend->values() as $spec) {
		$kinds[$spec->targetType->value . ':' . $spec->name] = $spec->kind;
	}

	expect($kinds['ost:read_bytes'])->toBe(ValueKind::Counter)
		->and($kinds['ost:write_bytes'])->toBe(ValueKind::Counter)
		->and($kinds['ost:kbytes_total'])->toBe(ValueKind::Gauge)
		->and($kinds['ost:waiters_count'])->toBe(ValueKind::Gauge)
		->and($kinds['mdt:md_open'])->toBe(ValueKind::Counter);
});

it('parses GPFS mmpmon fs_io_s, mmdf capacity, and waiter summaries into unified targets', function () {
	$fixture = implode("\n", [
		GpfsBackend::PROTOCOL,
		'== version',
		'scale: 5.2.1.0',
		'== mmpmon_fs_io_s',
		'_fs_io_s_ _n_ 10.20.0.10 _nn_ nsd01 _rc_ 0 _t_ 1760000000 _tu_ 123456 _cl_ prod.cluster _fs_ gpfs01 _d_ 4 _br_ 104857600 _bw_ 52428800 _oc_ 1200 _cc_ 1150 _rdc_ 4000 _wc_ 2000 _dir_ 300 _iu_ 900',
		'== mmdf',
		'pool fs=gpfs01 type=ost index=0 kbytes_total=1000000 kbytes_free=250000 kbytes_avail=200000 files_total=500000 files_free=400000 state=up',
		'pool fs=gpfs01 type=ost index=1 kbytes_total=1000000 kbytes_free=100000 kbytes_avail=100000 files_total=500000 files_free=100000 state=degraded',
		'pool fs=gpfs01 type=mdt index=0 kbytes_total=200000 kbytes_free=120000 kbytes_avail=120000 files_total=10000000 files_free=8000000 state=up',
		'== waiters',
		'waiters fs=gpfs01 index=0 count=2 max_sec=4.5',
		'waiters fs=gpfs01 index=1 count=9 max_sec=42.75',
		GpfsBackend::END,
	]) . "\n";

	$report = (new GpfsBackend())->parse($fixture);

	expect($report->framed)->toBeTrue()
		->and($report->complete)->toBeTrue()
		->and($report->partial)->toBeFalse()
		->and($report->version)->toBe('5.2.1.0')
		->and($report->rejectedNames)->toBe(0)
		->and($report->unexpectedSections)->toBe(0)
		->and(array_keys($report->targets))->toBe(['gpfs01-MDT0000', 'gpfs01-OST0000', 'gpfs01-OST0001']);

	$ost0 = $report->targets['gpfs01-OST0000'];
	expect($ost0->id->type)->toBe(TargetType::Ost)
		->and($ost0->condition)->toBe(Condition::InService)
		->and($ost0->values['read_bytes'])->toBe(104857600.0)
		->and($ost0->values['write_bytes'])->toBe(52428800.0)
		->and($ost0->values['read_ops'])->toBe(4000.0)
		->and($ost0->values['write_ops'])->toBe(2000.0)
		->and($ost0->values['kbytes_total'])->toBe(1000000.0)
		->and($ost0->values['kbytes_avail'])->toBe(200000.0)
		->and($ost0->values['waiters_count'])->toBe(2.0)
		->and($ost0->values['waiters_max_sec'])->toBe(4.5);

	$ost1 = $report->targets['gpfs01-OST0001'];
	expect($ost1->condition)->toBe(Condition::Degraded)
		->and($ost1->values['waiters_count'])->toBe(9.0)
		->and($ost1->values['waiters_max_sec'])->toBe(42.75);

	$mdt0 = $report->targets['gpfs01-MDT0000'];
	expect($mdt0->id->type)->toBe(TargetType::Mdt)
		->and($mdt0->values['md_open'])->toBe(1200.0)
		->and($mdt0->values['md_close'])->toBe(1150.0)
		->and($mdt0->values['md_readdir'])->toBe(300.0)
		->and($mdt0->values['md_inode_upd'])->toBe(900.0)
		->and($mdt0->values['files_total'])->toBe(10000000.0);
});

it('escalates target condition to Degraded when waiter max_sec reaches 30 seconds', function () {
	$fixture = implode("\n", [
		GpfsBackend::PROTOCOL,
		'== mmdf',
		'pool fs=gpfs01 type=ost index=0 kbytes_total=1000 kbytes_free=500 kbytes_avail=500 state=up',
		'== waiters',
		'waiters fs=gpfs01 index=0 count=5 max_sec=35.0',
		GpfsBackend::END,
	]) . "\n";

	$report = (new GpfsBackend())->parse($fixture);

	expect($report->targets['gpfs01-OST0000']->condition)->toBe(Condition::Degraded);
});

it('rejects mmpmon reset lines, non-zero _rc_, and hostile strings', function () {
	$fixture = implode("\n", [
		GpfsBackend::PROTOCOL,
		'== version',
		'5.2.1; rm -rf /',
		'== mmpmon_fs_io_s',
		'_reset_ _rc_ 0 _fs_ gpfs01',
		'_fs_io_s_ _rc_ 1 _fs_ gpfs01 _br_ 100 _bw_ 200',
		'_fs_io_s_ _rc_ 0 _fs_ ../bad! _br_ 100 _bw_ 200',
		'== mmdf',
		'pool fs=gpfs01 type=ost index=70000 kbytes_total=1000 state=up',
		'pool fs=gpfs01 type=bad index=0 kbytes_total=1000 state=up',
		'== unknown_section',
		'ignored',
		GpfsBackend::END,
	]) . "\n";

	$report = (new GpfsBackend())->parse($fixture);

	expect($report->framed)->toBeTrue()
		->and($report->complete)->toBeTrue()
		->and($report->partial)->toBeTrue()
		->and($report->version)->toBeNull()
		->and($report->targets)->toBe([])
		->and($report->rejectedNames)->toBe(4)
		->and($report->unexpectedSections)->toBe(1);
});

it('flags unframed, incomplete, and trailing-garbage payloads', function () {
	$backend = new GpfsBackend();

	expect($backend->parse('not framed')->framed)->toBeFalse()
		->and($backend->parse(GpfsBackend::PROTOCOL . "\n== mmdf\n")->complete)->toBeFalse()
		->and($backend->parse(GpfsBackend::PROTOCOL . "\n" . GpfsBackend::END . "\nextra\n")->complete)->toBeFalse();
});

it('detects GPFS counter resets cleanly through ResetDetector', function () {
	$previous = new Observation(1, 'read_bytes', 100000.0, 1760000000, 1760000000, 1, 300);
	$dropped  = ResetDetector::continuity($previous, 500.0, null, 1, ValueKind::Counter);
	$grown    = ResetDetector::continuity($previous, 150000.0, null, 1, ValueKind::Counter);

	expect($dropped)->toBe(Continuity::Reset)
		->and($grown)->toBe(Continuity::Continuous);
});
