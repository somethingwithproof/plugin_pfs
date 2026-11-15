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

use Somethingwithproof\Pfs\LustreBackend;
use Somethingwithproof\Pfs\ProcRunner;
use Somethingwithproof\Pfs\Tests\Scratch;

require_once dirname(__DIR__) . '/Support/Scratch.php';

/*
 * Runs the shipped node/pfs-read.sh against a fixture tree, with only its
 * three root paths rewritten, and parses the answer with the real backend.
 * No Lustre is needed.
 */

beforeEach(function () {
	$this->scratch = new Scratch();
	$this->sys     = $this->scratch->dir . '/sys';
	$this->proc    = $this->scratch->dir . '/proc';
	$this->debug   = $this->scratch->dir . '/debug';

	$s = $this->scratch;
	$s->file('sys/version', "2.17.0\n");
	$s->file('sys/osd-ldiskfs/scratch-OST0000/kbytestotal', "1000000\n");
	$s->file('sys/osd-ldiskfs/scratch-OST0000/kbytesfree', "400000\n");
	$s->file('sys/obdfilter/scratch-OST0000/degraded', "0\n");
	$s->file('sys/obdfilter/scratch-OST0000/readonly', "0\n");
	$s->file('debug/obdfilter/scratch-OST0000/stats', "snapshot_time 1731600000.000000001 secs.nsecs\nstart_time 1731000000.000000001 secs.nsecs\nread_bytes 3 samples [bytes] 1 4096 9000\n");
	$s->file('debug/obdfilter/scratch-OST0000/recovery_status', "status: COMPLETE\n");
	$s->file('proc/mdt/scratch-MDT0000/md_stats', str_repeat('x', 200000));
	$s->file('sys/obdfilter/scratch-OST0000/evict_client', "do-not-read\n");
	$s->file('outside', "secret\n");
	symlink($this->scratch->dir . '/outside', $this->sys . '/osd-ldiskfs/scratch-OST0000/kbytesavail');
	$s->file('sys/osd-ldiskfs/scratch-OST0000/filesfree', "no newline");

	$script = (string) file_get_contents(dirname(__DIR__, 2) . '/node/pfs-read.sh');
	$script = (string) preg_replace(['/^SYS_ROOT=.*$/m', '/^PROC_ROOT=.*$/m', '/^DEBUG_ROOT=.*$/m'], ['SYS_ROOT=' . $this->sys, 'PROC_ROOT=' . $this->proc, 'DEBUG_ROOT=' . $this->debug], $script);

	$this->script = $s->file('pfs-read.sh', $script, 0700);
});

afterEach(function () {
	$this->scratch->remove();
});

function pfs_run_script(string $script, array $args = [], array $env = ['PATH' => '/usr/bin:/bin']): \Somethingwithproof\Pfs\ProcessResult {
	return (new ProcRunner($env))->runAll([1 => array_merge(['/bin/sh', $script], $args)], 1, 60.0, 16 * 1024 * 1024, microtime(true) + 120)[1];
}

it('prints only allowlisted regular files, framed for the parser', function () {
	$run = pfs_run_script($this->script);

	expect($run->exitCode)->toBe(0)
		->and($run->stdout)->toStartWith("#pfs-read 1\n")
		->and($run->stdout)->toEndWith("#pfs-read end\n")
		->and($run->stdout)->not->toContain('secret')
		->and($run->stdout)->not->toContain('do-not-read')
		->and($run->stdout)->not->toContain('evict_client');

	$report = (new LustreBackend())->parse($run->stdout);
	$ost    = $report->targets['scratch-OST0000'];

	expect($report->usable())->toBeTrue()
		->and($report->unexpectedSections)->toBe(0)
		->and($report->version)->toBe('2.17.0')
		->and($ost->values['kbytes_total'])->toBe(1000000.0)
		->and($ost->values['kbytes_avail'])->toBeNull()
		->and($ost->values['read_bytes'])->toBe(9000.0)
		->and($ost->values['files_free'])->toBeNull()
		->and($ost->recovery)->toBe('COMPLETE');
});

it('marks an oversized file truncated so its values stay unknown', function () {
	$run = pfs_run_script($this->script);

	expect(substr_count($run->stdout, '== mdt/scratch-MDT0000/md_stats'))->toBe(1)
		->and($run->stdout)->toContain(str_repeat('x', 65536) . "\n!! truncated\n")
		->and($run->stdout)->not->toContain(str_repeat('x', 65537));

	$report = (new LustreBackend())->parse($run->stdout);

	expect($report->partial)->toBeTrue()
		->and($report->targets['scratch-MDT0000']->values['md_open'])->toBeNull();
});

it('stops at the total limit and says so', function () {
	for ($i = 1; $i <= 70; $i++) {
		$this->scratch->file(sprintf('debug/obdfilter/scratch-OST%04x/stats', $i), str_repeat('y', 65536));
	}

	$run = pfs_run_script($this->script);

	expect($run->stdout)->toContain("!! total-limit\n#pfs-read end\n")
		->and(strlen($run->stdout))->toBeLessThan(4194304 + 70000)
		->and((new LustreBackend())->parse($run->stdout)->partial)->toBeTrue();
});

it('refuses arguments and ignores the command the client asked for', function () {
	$refused = pfs_run_script($this->script, ['cat', '/etc/shadow']);
	$asked   = pfs_run_script($this->script, [], ['PATH' => '/usr/bin:/bin', 'SSH_ORIGINAL_COMMAND' => 'cat /etc/passwd']);

	expect($refused->exitCode)->toBe(64)
		->and($refused->stdout)->toBe('')
		->and($asked->exitCode)->toBe(0)
		->and($asked->stdout)->not->toContain('root:')
		->and($asked->stdout)->toStartWith("#pfs-read 1\n");
});

it('ships the script with the same allowlist roots the documentation names', function () {
	$script = (string) file_get_contents(dirname(__DIR__, 2) . '/node/pfs-read.sh');

	expect($script)->toContain("SYS_ROOT=/sys/fs/lustre\n")
		->and($script)->toContain("PROC_ROOT=/proc/fs/lustre\n")
		->and($script)->toContain("DEBUG_ROOT=/sys/kernel/debug/lustre\n")
		->and($script)->not->toContain('SSH_ORIGINAL_COMMAND"')
		->and($script)->not->toMatch('/\beval\b/');
});
