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

use Somethingwithproof\Pfs\Backend;
use Somethingwithproof\Pfs\BackendRegistry;
use Somethingwithproof\Pfs\BeegfsBackend;
use Somethingwithproof\Pfs\GpfsBackend;
use Somethingwithproof\Pfs\LustreBackend;
use Somethingwithproof\Pfs\TargetId;
use Somethingwithproof\Pfs\Tests\DemoBackend;
use Somethingwithproof\Pfs\UnknownBackend;
use Somethingwithproof\Pfs\ValueSpec;

require_once dirname(__DIR__) . '/Support/DemoBackend.php';

/**
 * Inputs every backend must survive: nothing it parses may throw, invent a
 * target name, or report a value it did not declare.
 *
 * @return array<string, string>
 */
function pfs_hostile_inputs(): array {
	$fixtures = [];

	foreach (glob(dirname(__DIR__) . '/fixtures/lustre/*.txt') ?: [] as $file) {
		$fixtures[basename($file)] = (string) file_get_contents($file);
	}

	return $fixtures + [
		'empty'       => '',
		'nul bytes'   => "#pfs-read 1\n\0\0\0\n#pfs-read end\n",
		'binary'      => random_bytes(4096),
		'long line'   => "#pfs-read 1\n== " . str_repeat('a', 100000) . "\n#pfs-read end\n",
		'demo output' => "#demo 1\nscratch-OST0000 5\nbad name 7\n#demo end\n",
		'crlf'        => str_replace("\n", "\r\n", pfs_fixture('oss-2.15.txt')),
	];
}

dataset('backends', [
	'lustre' => [fn (): Backend => new LustreBackend()],
	'gpfs'   => [fn (): Backend => new GpfsBackend()],
	'beegfs' => [fn (): Backend => new BeegfsBackend()],
	'demo'   => [fn (): Backend => new DemoBackend()],
]);

it('reports only declared values, as numbers or unknown', function (Backend $backend) {
	$declared = [];

	foreach ($backend->values() as $spec) {
		expect($spec->name)->toMatch(ValueSpec::NAME_PATTERN);
		$declared[$spec->targetType->value][] = $spec->name;
	}

	foreach (pfs_hostile_inputs() as $label => $input) {
		$report = $backend->parse($input);

		if (!$report->usable()) {
			expect($report->targets)->toBe([], $label)
				->and($report->error())->not->toBeNull();

			continue;
		}

		foreach ($report->targets as $name => $target) {
			expect($name)->toMatch(TargetId::PATTERN, $label)
				->and($target->id->name())->toBe($name);

			foreach ($target->values as $value => $number) {
				expect(in_array($value, $declared[$target->id->type->value] ?? [], true))->toBeTrue($label . ' ' . $value);

				if ($number !== null) {
					expect($number)->toBeFloat()->toBeGreaterThanOrEqual(0.0);
				}
			}
		}
	}
})->with('backends');

it('rejects answers that are not its own', function (Backend $backend) {
	expect($backend->parse('')->usable())->toBeFalse()
		->and($backend->parse(random_bytes(512))->usable())->toBeFalse()
		->and($backend->parse("Permission denied\n")->usable())->toBeFalse();
})->with('backends');

it('registers backends by unique identifier and refuses unknown ones', function () {
	$registry = BackendRegistry::production();
	$registry->register(new DemoBackend());

	expect($registry->has('lustre'))->toBeTrue()
		->and($registry->get('demo'))->toBeInstanceOf(DemoBackend::class)
		->and(fn () => $registry->register(new DemoBackend()))->toThrow(InvalidArgumentException::class)
		->and(fn () => $registry->get('<gpfs>'))->toThrow(UnknownBackend::class, 'Unknown backend ?gpfs?');
});

it('never invents a value when the answer is cut short at any line', function () {
	$backend = new LustreBackend();
	$full    = $backend->parse(pfs_fixture('oss-2.15.txt'));
	$lines   = explode("\n", pfs_fixture('oss-2.15.txt'));

	for ($n = 0; $n < count($lines); $n++) {
		$cut = implode("\n", array_slice($lines, 0, $n));

		foreach ([$cut, $cut . "\n!! total-limit\n#pfs-read end\n", $cut . "\n!! read-failed\n\n#pfs-read end\n"] as $input) {
			$report = $backend->parse($input);

			foreach ($report->targets as $name => $target) {
				foreach ($target->values as $value => $number) {
					// Either what the whole answer says, or unknown; never a zero
					// or any other number the node did not print.
					expect($number === null || $number === $full->targets[$name]->values[$value])->toBeTrue("$n $name $value");
				}
			}
		}
	}
});
