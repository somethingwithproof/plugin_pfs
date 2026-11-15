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

use Somethingwithproof\Pfs\ProcRunner;
use Somethingwithproof\Pfs\Tests\Scratch;

require_once dirname(__DIR__) . '/Support/Scratch.php';

beforeEach(function () {
	$this->scratch = new Scratch();
});

afterEach(function () {
	$this->scratch->remove();
});

it('passes arguments without a shell and returns output and exit status', function () {
	$script = $this->scratch->file('echo', "#!/bin/sh\nprintf '%s|' \"\$@\"\nexit 3\n", 0700);
	$result = (new ProcRunner())->runAll([1 => [$script, 'a b', '$(id)', ';', '*']], 1, 10.0, 1024, microtime(true) + 60)[1];

	expect($result->exitCode)->toBe(3)
		->and($result->stdout)->toBe('a b|$(id)|;|*|')
		->and($result->timedOut)->toBeFalse();
});

it('gives the child only the fixed environment and no stdin', function () {
	putenv('PFS_LEAK=secret');
	$script = $this->scratch->file('env', "#!/bin/sh\nenv | sort\nread line && echo got-stdin\n", 0700);
	$result = (new ProcRunner())->runAll([1 => [$script]], 1, 10.0, 4096, microtime(true) + 60)[1];
	putenv('PFS_LEAK');

	expect($result->stdout)->not->toContain('PFS_LEAK')
		->and($result->stdout)->not->toContain('got-stdin')
		->and($result->stdout)->toContain('LC_ALL=C');
});

it('kills a child that runs past its timeout and keeps none of its output', function () {
	$script  = $this->scratch->file('slow', "#!/bin/sh\necho partial\nexec sleep 30\n", 0700);
	$started = microtime(true);
	$result  = (new ProcRunner())->runAll([1 => [$script]], 1, 1.0, 1024, microtime(true) + 60)[1];

	expect($result->timedOut)->toBeTrue()
		->and($result->exitCode)->toBeNull()
		->and($result->stdout)->toBe('')
		->and(microtime(true) - $started)->toBeLessThan(10.0);
});

it('kills a child whose output passes the limit and keeps none of it', function () {
	$script = $this->scratch->file('big', "#!/bin/sh\nexec head -c 10000000 /dev/zero\n", 0700);
	$result = (new ProcRunner())->runAll([1 => [$script]], 1, 30.0, 65536, microtime(true) + 60)[1];

	expect($result->tooLarge)->toBeTrue()
		->and($result->stdout)->toBe('')
		->and($result->timedOut)->toBeFalse();
});

it('reports a missing binary as not runnable', function () {
	$result = (new ProcRunner())->runAll([1 => [$this->scratch->dir . '/absent']], 1, 5.0, 1024, microtime(true) + 60)[1];

	expect($result->exitCode)->toBe(127)
		->and($result->stdout)->toBe('');
});

it('starts nothing after the deadline and runs the rest in parallel', function () {
	$script = $this->scratch->file('nap', "#!/bin/sh\nexec sleep 1\n", 0700);
	$late   = (new ProcRunner())->runAll([1 => [$script]], 1, 5.0, 1024, microtime(true) - 1)[1];

	$started = microtime(true);
	$results = (new ProcRunner())->runAll([1 => [$script], 2 => [$script], 3 => [$script], 4 => [$script]], 4, 5.0, 1024, microtime(true) + 60);

	expect($late->started)->toBeFalse()
		->and(array_keys($results))->toBe([1, 2, 3, 4])
		->and(array_map(fn ($r) => $r->exitCode, $results))->toBe([1 => 0, 2 => 0, 3 => 0, 4 => 0])
		->and(microtime(true) - $started)->toBeLessThan(3.5);
});

it('keeps only the start of a long error stream', function () {
	$script = $this->scratch->file('noisy', "#!/bin/sh\nhead -c 100000 /dev/zero | tr '\\\\0' e >&2\nexit 255\n", 0700);
	$result = (new ProcRunner())->runAll([1 => [$script]], 1, 10.0, 1024, microtime(true) + 60)[1];

	expect($result->exitCode)->toBe(255)
		->and(strlen($result->stderr))->toBe(2048);
});
