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

namespace Somethingwithproof\Pfs;

/**
 * Runs argument vectors through proc_open() with an array command, so no
 * shell parses anything (REQ-SRC-002). Each child gets /dev/null on stdin
 * and a fixed environment, its stdout is cut off and the child killed at
 * $maxBytes, and it is killed at $timeout (REQ-SRC-003).
 */
final class ProcRunner implements ProcessRunner {
	/** Chunk constant. */
	private const CHUNK       = 65536;
	/** Stderr keep constant. */
	private const STDERR_KEEP = 2048;

	/**
	 * @param array<string, string> $env the whole environment of each child
	 */
	public function __construct(private readonly array $env = ['PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C']) {
	}

	/**
	 * Run all.
	 *
	 * @param array<int, list<string>> $commands
	 * @param int $concurrency
	 * @param float $timeout
	 * @param int $maxBytes
	 * @param float $deadline
	 * @return array<int, ProcessResult>
	 */
	public function runAll(array $commands, int $concurrency, float $timeout, int $maxBytes, float $deadline): array {
		$pending  = $commands;
		$running  = [];
		$results  = [];
		$parallel = max(1, $concurrency);

		while ($pending !== [] || $running !== []) {
			while ($pending !== [] && count($running) < $parallel) {
				$key  = (int) array_key_first($pending);
				$argv = $pending[$key];
				unset($pending[$key]);

				if (microtime(true) >= $deadline) {
					$results[$key] = ProcessResult::notStarted();

					continue;
				}

				$job = $this->start($argv);

				if ($job === null) {
					// The command could not be started at all, as a shell's 127.
					$results[$key] = new ProcessResult(127, '', '', false, false, true, 0.0);

					continue;
				}

				$running[$key] = $job;
			}

			if ($running === []) {
				continue;
			}

			$this->pump($running, $maxBytes);

			foreach ($running as $key => $job) {
				$elapsed = microtime(true) - $job->started;

				if ($job->tooLarge || $elapsed > $timeout) {
					$results[$key] = $this->kill($job, $elapsed > $timeout && !$job->tooLarge);
					unset($running[$key]);

					continue;
				}

				if ($job->exit === null) {
					$status = proc_get_status($job->process);

					if (!$status['running']) {
						$job->exit = $status['exitcode'];
					}
				}

				if ($job->exit !== null && $job->stdoutEof && $job->stderrEof) {
					fclose($job->stdout);
					fclose($job->stderr);
					proc_close($job->process);

					$results[$key] = new ProcessResult($job->exit, $job->out, $job->err, false, false, true, microtime(true) - $job->started);
					unset($running[$key]);
				}
			}
		}

		ksort($results);

		return $results;
	}

	/**
	 * @param list<string> $argv
	 */
	private function start(array $argv): ?ProcJob {
		if ($argv === [] || !is_file($argv[0]) || !is_executable($argv[0])) {
			return null;
		}

		$pipes   = [];
		$process = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $this->env);

		if (!is_resource($process) || !isset($pipes[1], $pipes[2]) || !is_resource($pipes[1]) || !is_resource($pipes[2])) {
			return null;
		}

		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		return new ProcJob($process, $pipes[1], $pipes[2], microtime(true));
	}

	/**
	 * Read whatever the running children have written, waiting at most a
	 * tenth of a second for any of them.
	 *
	 * @param array<int, ProcJob> $running
	 */
	private function pump(array $running, int $maxBytes): void {
		$read  = [];
		$owner = [];

		foreach ($running as $key => $job) {
			if (!$job->stdoutEof) {
				$read[]                              = $job->stdout;
				$owner[get_resource_id($job->stdout)] = [$job, 1];
			}

			if (!$job->stderrEof) {
				$read[]                              = $job->stderr;
				$owner[get_resource_id($job->stderr)] = [$job, 2];
			}
		}

		if ($read === []) {
			usleep(20000);

			return;
		}

		$write  = null;
		$except = null;

		if (@stream_select($read, $write, $except, 0, 100000) === false) {
			usleep(20000);

			return;
		}

		foreach ($read as $stream) {
			[$job, $fd] = $owner[get_resource_id($stream)];
			$chunk      = fread($stream, self::CHUNK);

			if ($chunk === false || ($chunk === '' && feof($stream))) {
				if ($fd === 1) {
					$job->stdoutEof = true;
				} else {
					$job->stderrEof = true;
				}

				continue;
			}

			if ($fd === 1) {
				$job->out .= $chunk;

				if (strlen($job->out) > $maxBytes) {
					$job->tooLarge = true;
				}
			} elseif (strlen($job->err) < self::STDERR_KEEP) {
				$job->err .= substr($chunk, 0, self::STDERR_KEEP - strlen($job->err));
			}
		}
	}

	/**
	 * Kill.
	 *
	 * @param ProcJob $job
	 * @param bool $timedOut
	 * @return ProcessResult
	 */
	private function kill(ProcJob $job, bool $timedOut): ProcessResult {
		proc_terminate($job->process, 9);
		fclose($job->stdout);
		fclose($job->stderr);
		proc_close($job->process);

		// A cut-off answer is never handed on as if it were whole.
		return new ProcessResult(null, '', $job->err, $timedOut, $job->tooLarge, true, microtime(true) - $job->started);
	}
}
