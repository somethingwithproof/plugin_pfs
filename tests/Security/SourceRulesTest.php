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
 * Source rules from docs/PLAN.md "Code structure" and ADR 0004 that a parser
 * can check. The PHP 8.0 parse of the entry points themselves is proven by the
 * CI lint job on a real PHP 8.0; these checks catch the same mistakes earlier
 * and cover the rules a lint cannot.
 */

function pfs_root(): string {
	return dirname(__DIR__, 2);
}

/**
 * @return list<string> plugin PHP files outside tests/, relative to the root
 */
function pfs_sources(): array {
	$files = [];
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(pfs_root(), FilesystemIterator::SKIP_DOTS));

	foreach ($iterator as $file) {
		$relative = substr($file->getPathname(), strlen(pfs_root()) + 1);

		if ($file->getExtension() === 'php' && !str_starts_with($relative, 'tests/') && !str_starts_with($relative, '.git/')) {
			$files[] = $relative;
		}
	}

	sort($files);

	return $files;
}

/**
 * @return list<string> files Cacti may include before the PHP version check
 */
function pfs_entry_points(): array {
	return array_values(array_filter(pfs_sources(), fn (string $f) => !str_starts_with($f, 'lib/')));
}

it('keeps every PHP source file within the gettext scan depth', function () {
	foreach (pfs_sources() as $file) {
		expect(substr_count($file, '/'))->toBeLessThanOrEqual(1, $file);
	}
});

it('keeps entry points free of PHP 8.1 and later syntax', function () {
	$banned = [T_ENUM => 'enum', T_READONLY => 'readonly'];

	foreach (pfs_entry_points() as $file) {
		$tokens = PhpToken::tokenize((string) file_get_contents(pfs_root() . '/' . $file));

		foreach ($tokens as $i => $token) {
			expect(isset($banned[$token->id]))->toBeFalse("$file:{$token->line} uses {$token->text}");

			// "never" as a return type and "new" in an initializer are 8.1.
			if ($token->is(T_STRING) && strtolower($token->text) === 'never') {
				$previous = $tokens[$i - 1]->is(T_WHITESPACE) ? $tokens[$i - 2] : $tokens[$i - 1];
				expect($previous->text)->not->toBe(':', "$file:{$token->line} uses never");
			}
		}
	}
});

it('uses no PHP 8.3 or later features anywhere', function () {
	foreach (pfs_sources() as $file) {
		$source = (string) file_get_contents(pfs_root() . '/' . $file);
		$tokens = PhpToken::tokenize($source);

		expect($source)->not->toContain('json_validate(')
			->and($source)->not->toContain('#[\\Override]')
			->and($source)->not->toContain('#[Override]');

		foreach ($tokens as $i => $token) {
			if (!$token->is(T_CONST)) {
				continue;
			}

			// A typed constant has two identifiers between const and "=".
			$names = 0;

			for ($j = $i + 1; $j < count($tokens) && $tokens[$j]->text !== '='; $j++) {
				$names += $tokens[$j]->is(T_STRING) ? 1 : 0;
			}

			expect($names)->toBe(1, "$file:{$token->line} declares a typed constant");
		}
	}
});

it('defines exactly one class, interface, or enum per lib file, named after the file', function () {
	foreach (glob(pfs_root() . '/lib/*.php') as $path) {
		$tokens = PhpToken::tokenize((string) file_get_contents($path));
		$names  = [];

		foreach ($tokens as $i => $token) {
			if ($token->is([T_CLASS, T_INTERFACE, T_ENUM, T_TRAIT]) && !$tokens[$i - 1]->is(T_DOUBLE_COLON)) {
				$next = $tokens[$i + 1]->is(T_WHITESPACE) ? $tokens[$i + 2] : $tokens[$i + 1];

				if ($next->is(T_STRING)) {
					$names[] = $next->text;
				}
			}
		}

		expect($names)->toBe([basename($path, '.php')], basename($path))
			->and((string) file_get_contents($path))->toContain("namespace Somethingwithproof\\Pfs;");
	}
});

it('keeps lib classes away from request variables and globals', function () {
	foreach (glob(pfs_root() . '/lib/*.php') as $path) {
		$source = (string) file_get_contents($path);

		expect($source)->not->toMatch('/\$_(REQUEST|GET|POST|SESSION|SERVER|COOKIE)\b/', basename($path))
			->and($source)->not->toMatch('/\bglobal\s+\$/', basename($path))
			->and($source)->not->toContain('$GLOBALS', basename($path));
	}
});

it('issues SQL only from repository and schema classes, through prepared helpers', function () {
	foreach (pfs_sources() as $file) {
		$source = (string) file_get_contents(pfs_root() . '/' . $file);

		expect($source)->not->toMatch('/\bdb_(execute|fetch_cell|fetch_row|fetch_assoc)\s*\(/', $file);

		// include/unbooted-uninstall.php is the one exception: it runs where
		// lib/ cannot load (see its header).
		$repository = str_starts_with($file, 'lib/') && preg_match('/^(Schema|\w+Repository)\.php$/', basename($file)) === 1;

		if (!$repository && $file !== 'include/unbooted-uninstall.php') {
			expect($source)->not->toMatch('/\bdb_\w+_prepared\s*\(/', $file);
		}
	}
});

it('passes the pfs text domain to every translated plugin string', function () {
	// Labels intentionally reused from core keep the core domain.
	$core = ['Management'];

	foreach (pfs_sources() as $file) {
		$tokens = array_values(array_filter(PhpToken::tokenize((string) file_get_contents(pfs_root() . '/' . $file)), fn ($t) => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));

		foreach ($tokens as $i => $token) {
			if (!$token->is(T_STRING) || $token->text !== '__' || ($tokens[$i + 1]->text ?? '') !== '(' || ($tokens[$i - 1]->is(T_FUNCTION))) {
				continue;
			}

			$depth = 0;
			$last  = null;

			for ($j = $i + 1; $j < count($tokens); $j++) {
				$depth += $tokens[$j]->text === '(' ? 1 : ($tokens[$j]->text === ')' ? -1 : 0);

				if ($depth === 0) {
					break;
				}

				if ($depth === 1 && $tokens[$j]->text !== ',' && $tokens[$j]->text !== '(') {
					$last = $tokens[$j];
				}
			}

			$first = $tokens[$i + 2];

			if ($first->is(T_CONSTANT_ENCAPSED_STRING) && in_array(trim($first->text, '\'"'), $core, true)) {
				continue;
			}

			expect($last?->text)->toBe("'pfs'", "$file:{$token->line}");
		}
	}
});

it('ships a translation template containing every plugin string', function () {
	$pot = (string) file_get_contents(pfs_root() . '/locales/po/cacti.pot');

	foreach (['The Parallel Filesystem plugin requires PHP %s or later. This server runs PHP %s, so the plugin was not installed or enabled.'] as $msgid) {
		expect($pot)->toContain('msgid "' . $msgid . '"');
	}
});

it('formats translated strings with __() itself, never sprintf(__())', function () {
	// Core's __() hands the text domain to sprintf(), so a placeholder in
	// sprintf(__("%d", "pfs"), $n) takes "pfs" and prints 0 or the
	// domain. The values go inside the call: __("%d", $n, "pfs").
	foreach (pfs_sources() as $file) {
		expect((string) file_get_contents(pfs_root() . '/' . $file))->not->toContain('sprintf(__(', $file);
	}
});

it('uses plain placeholders in translated strings, because core\'s __() does not format positional ones', function () {
	// __() only formats a string that has %d, %s, and the like. A string with
	// only %1$d placeholders comes back raw.
	foreach (pfs_sources() as $file) {
		$source = (string) file_get_contents(pfs_root() . '/' . $file);

		preg_match_all("/__\\('((?:[^'\\\\]|\\\\.)*)'/", $source, $texts);

		foreach ($texts[1] as $text) {
			expect($text)->not->toMatch('/%[0-9]+\$/', $file);
		}
	}
});

it('logs through cacti_log() under the PFS tag only', function () {
	foreach (pfs_sources() as $file) {
		$code = '';

		foreach (PhpToken::tokenize((string) file_get_contents(pfs_root() . '/' . $file)) as $token) {
			$code .= $token->is([T_COMMENT, T_DOC_COMMENT]) ? '' : $token->text;
		}

		preg_match_all('/\bcacti_log\s*\((.*?)\);/s', $code, $calls);

		foreach ($calls[1] as $arguments) {
			expect(str_contains($arguments, "'PFS'"))->toBeTrue($file);
		}
	}
});

it('ships no Composer runtime dependencies', function () {
	$manifest = pfs_root() . '/composer.json';

	if (is_file($manifest)) {
		$require = json_decode((string) file_get_contents($manifest), true)['require'] ?? [];

		foreach (array_keys($require) as $package) {
			expect($package === 'php' || str_starts_with($package, 'ext-'))->toBeTrue($package);
		}
	}

	expect(is_dir(pfs_root() . '/vendor'))->toBeFalse();
});

/**
 * @return string the file without comments
 */
function pfs_code(string $file): string {
	$code = '';

	foreach (PhpToken::tokenize((string) file_get_contents(pfs_root() . '/' . $file)) as $token) {
		$code .= $token->is([T_COMMENT, T_DOC_COMMENT]) ? '' : $token->text;
	}

	return $code;
}

it('starts processes only from ProcRunner, with an argument array, and never through a shell', function () {
	foreach (pfs_sources() as $file) {
		$code = pfs_code($file);

		expect($code)->not->toMatch('/\b(shell_exec|passthru|system|popen|pcntl_exec)\s*\(/', $file)
			->and($code)->not->toMatch('/(?<![\w>:$])exec\s*\(/', $file)
			->and($code)->not->toContain('`', $file);

		if ($file !== 'lib/ProcRunner.php') {
			expect($code)->not->toMatch('/\bproc_\w+\s*\(/', $file);
		}

		if ($file !== 'setup.php') {
			expect($code)->not->toMatch('/\bexec_background\s*\(/', $file);
		}
	}

	// proc_open() with an array command runs the program directly.
	expect(pfs_code('lib/ProcRunner.php'))->toMatch('/proc_open\(\$argv,/');
});

it('keeps pages away from node access, so a page view never contacts a node', function () {
	$pages = array_merge(Somethingwithproof\Pfs\Pages::files(), ['lib/AdminPage.php', 'lib/HealthPage.php', 'lib/TargetsPage.php', 'lib/HealthWarnings.php', 'lib/NodeInput.php', 'lib/FilesystemInput.php']);

	foreach ($pages as $file) {
		expect(pfs_code($file))->not->toMatch('/\b(Reader|NodeSource|NodeSources|SshNodeSource|FixtureNodeSource|ProcRunner|ProcessRunner|pfs_reader|pfs_reader_with)\b(?!Repository|Run\b)/', $file);
	}
});

it('builds the production node source only in setup.php and tests', function () {
	foreach (pfs_sources() as $file) {
		if ($file !== 'setup.php' && $file !== 'lib/SshNodeSource.php') {
			expect(pfs_code($file))->not->toMatch('/new\s+\\\\?(Somethingwithproof\\\\Pfs\\\\)?SshNodeSource\b/', $file);
		}

		expect(pfs_code($file))->not->toMatch('/new\s+\\\\?(Somethingwithproof\\\\Pfs\\\\)?FixtureNodeSource\b/', $file);
	}
});

it('never logs or prints what a node sent', function () {
	foreach (pfs_sources() as $file) {
		preg_match_all('/\b(?:Log::\w+|cacti_log|print|echo|fwrite)\s*\(?(.*?)\);/s', pfs_code($file), $calls);

		foreach ($calls[1] as $arguments) {
			expect($arguments)->not->toMatch('/->(output|stdout|stderr)\b|\$output\b/', $file);
		}
	}
});

it('ships the node read script with its SPDX header after the shebang', function () {
	$lines = file(pfs_root() . '/node/pfs-read.sh', FILE_IGNORE_NEW_LINES) ?: [];

	// REUSE-IgnoreStart
	expect($lines[0])->toBe('#!/bin/sh')
		->and($lines[1])->toStartWith('# SPDX-FileCopyrightText: 2026 Thomas Vincent')
		->and($lines[2])->toBe('# SPDX-License-Identifier: GPL-2.0-or-later');
	// REUSE-IgnoreEnd
});
