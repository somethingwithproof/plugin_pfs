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
 * Enforces 100% PHPDoc coverage across all production PHP files using PHP's
 * token_get_all(). Every class, interface, trait, enum, enum case, constant,
 * property, method, and named function outside tests/ must carry an
 * immediately preceding DocBlock.
 */

/**
 * Scan a production PHP file with token_get_all() and return symbols missing a DocBlock.
 *
 * @param string $path absolute path to a PHP file
 *
 * @return array{total: int, missing: list<string>}
 */
function pfs_scan_phpdoc_file(string $path, string $relative): array {
	$tokens          = token_get_all((string) file_get_contents($path));
	$count           = count($tokens);
	$braceDepth      = 0;
	$parenDepth      = 0;
	$bracketDepth    = 0;
	$typeStack       = [];
	$pendingTypeKind = null;
	$docComment      = null;
	$inConstExpr     = false;
	$inPropInit      = false;
	$missing         = [];
	$total           = 0;

	for ($i = 0; $i < $count; $i++) {
		$token = $tokens[$i];
		$isArr = is_array($token);
		$id    = $isArr ? $token[0] : null;
		$text  = $isArr ? $token[1] : $token;
		$line  = $isArr ? $token[2] : 0;

		if ($id === T_WHITESPACE || $id === T_COMMENT) {
			continue;
		}

		if ($id === T_DOC_COMMENT) {
			$docComment = $text;
			continue;
		}

		if (!$isArr && $text === '(') {
			$parenDepth++;
			continue;
		}

		if (!$isArr && $text === ')') {
			$parenDepth--;
			continue;
		}

		if ((!$isArr && $text === '[') || $id === T_ATTRIBUTE) {
			$bracketDepth++;
			continue;
		}

		if (!$isArr && $text === ']') {
			$bracketDepth--;
			continue;
		}

		if ((!$isArr && $text === '{') || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
			$braceDepth++;

			if ($pendingTypeKind !== null && $parenDepth === 0 && $bracketDepth === 0) {
				$typeStack[]     = [$braceDepth, $pendingTypeKind];
				$pendingTypeKind = null;
			}

			$docComment  = null;
			$inConstExpr = false;
			$inPropInit  = false;
			continue;
		}

		if (!$isArr && $text === '}') {
			if ($typeStack !== [] && end($typeStack)[0] === $braceDepth) {
				array_pop($typeStack);
			}

			$braceDepth--;
			$docComment  = null;
			$inConstExpr = false;
			$inPropInit  = false;
			continue;
		}

		if (!$isArr && $text === ';') {
			if ($parenDepth === 0 && $bracketDepth === 0) {
				$docComment  = null;
				$inConstExpr = false;
				$inPropInit  = false;
			}
			continue;
		}

		if (!$isArr && $text === '=' && $parenDepth === 0 && $bracketDepth === 0) {
			$inPropInit  = true;
			$inConstExpr = true;
			continue;
		}

		if ($parenDepth > 0 || $bracketDepth > 0) {
			continue;
		}

		$atTopLevel   = ($typeStack === [] && $braceDepth === 0);
		$currentType  = ($typeStack !== [] && end($typeStack)[0] === $braceDepth) ? end($typeStack)[1] : null;
		$atClassLevel = ($currentType !== null && $currentType !== 'anon');

		if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
			$prevIdx = $i - 1;

			while ($prevIdx >= 0 && is_array($tokens[$prevIdx]) && in_array($tokens[$prevIdx][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				$prevIdx--;
			}

			$prevId = ($prevIdx >= 0 && is_array($tokens[$prevIdx])) ? $tokens[$prevIdx][0] : null;

			if ($prevId === T_DOUBLE_COLON) {
				continue;
			}

			if ($prevId === T_NEW) {
				$pendingTypeKind = 'anon';
				$docComment      = null;
				continue;
			}

			$pendingTypeKind = $id;
			$next            = $i + 1;

			while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
				$next++;
			}

			$name = ($next < $count && is_array($tokens[$next])) ? $tokens[$next][1] : '?';
			$total++;

			if ($docComment === null) {
				$missing[] = sprintf('%s:%d type %s', $relative, $line, $name);
			}

			$docComment = null;
			continue;
		}

		if ($id === T_CONST && ($atTopLevel || $atClassLevel) && !$inConstExpr) {
			$next = $i + 1;

			while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
				$next++;
			}

			$name = ($next < $count && is_array($tokens[$next])) ? $tokens[$next][1] : '?';
			$total++;

			if ($docComment === null) {
				$missing[] = sprintf('%s:%d const %s', $relative, $line, $name);
			}

			$docComment = null;
			continue;
		}

		if ($id === T_CASE && $currentType === T_ENUM) {
			$next = $i + 1;

			while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
				$next++;
			}

			$name = ($next < $count && is_array($tokens[$next])) ? $tokens[$next][1] : '?';
			$total++;

			if ($docComment === null) {
				$missing[] = sprintf('%s:%d enum case %s', $relative, $line, $name);
			}

			$docComment = null;
			continue;
		}

		if ($id === T_FUNCTION && ($atTopLevel || $atClassLevel)) {
			$next = $i + 1;

			while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
				$next++;
			}

			if ($next < $count && $tokens[$next] === '&') {
				$next++;

				while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
					$next++;
				}
			}

			if ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
				$name = $tokens[$next][1];
				$total++;

				if ($docComment === null) {
					$missing[] = sprintf('%s:%d %s %s', $relative, $line, $atClassLevel ? 'method' : 'function', $name);
				}
			}

			$docComment = null;
			continue;
		}

		if ($id === T_VARIABLE && $atClassLevel && !$inPropInit && !$inConstExpr) {
			$total++;

			if ($docComment === null) {
				$missing[] = sprintf('%s:%d property %s', $relative, $line, $text);
			}

			$docComment = null;
			continue;
		}
	}

	return ['total' => $total, 'missing' => $missing];
}

it('enforces 100% PHPDoc coverage across every production PHP symbol', function () {
	$root     = dirname(__DIR__, 2);
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
	$files    = [];

	foreach ($iterator as $file) {
		$relative = substr($file->getPathname(), strlen($root) + 1);

		if ($file->getExtension() === 'php' && !str_starts_with($relative, 'tests/') && !str_starts_with($relative, '.git/')) {
			$files[] = $relative;
		}
	}

	sort($files);

	expect($files)->not->toBeEmpty();

	$total   = 0;
	$missing = [];

	foreach ($files as $relative) {
		$scan    = pfs_scan_phpdoc_file($root . '/' . $relative, $relative);
		$total  += $scan['total'];
		$missing = array_merge($missing, $scan['missing']);
	}

	expect($total)->toBeGreaterThan(0)
		->and($missing)->toBe([], "Missing PHPDoc blocks:\n" . implode("\n", $missing));
});
