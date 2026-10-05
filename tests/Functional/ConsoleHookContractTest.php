<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The whole console-naming feature in real processes, because almost everything it depends on is a
 * process-global fact that cannot be arranged in a PHPUnit worker: a prepended hook, `BCPERF_ACTIVE`,
 * `$_SERVER['argv']`, and a shutdown handler that runs after `console.terminate`.
 *
 * Worth knowing which hook this runs against: `composer.lock` pins the collector at **v2.0.0**,
 * whose line is `$_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_NAME'] ?? ''` - it has no argv-based
 * CLI naming at all. So these tests prove the feature against the version installations are
 * actually running, not against the collector's main branch. That is the point of the design:
 * `REQUEST_URI` has been the hook's first choice since 2.0, so nothing on a monitored machine has
 * to change.
 */
final class ConsoleHookContractTest extends TestCase {

	/** The command line every part of the policy appears in - see the fixture. */
	public function testACommandIsRecordedUnderItsNameAndItsArguments(): void {
		$line = $this->line('resolved');

		// An abbreviation resolved to its real name, options dropped, values kept, the password
		// hidden without moving what follows it, and the array argument spread - one assertion.
		self::assertSame('/console/app:import/customers/{redacted}/async/failed', $line['p']);
	}

	/** A `?` in a value would have spilled into this field, where nothing would ever read it. */
	public function testNothingSpilledIntoTheQueryField(): void {
		self::assertSame('', $this->line('resolved')['q']);
	}

	/** The naming listener must not cost the run its SQL numbers. */
	public function testTheSqlMetricsStillRideAlong(): void {
		self::assertSame(['sq' => 2, 'st' => 0.75], $this->line('resolved')['e']);
	}

	/**
	 * The case that argv cannot get right. `bcperf_cli_path()` skips `--env` and returns the next
	 * token, so the hook on its own would file this run under `/console/prod` - a job that does
	 * not exist. Symfony knows `--env` takes a value, so the listener does too.
	 */
	public function testAnOptionValueBeforeTheCommandIsNotMistakenForTheCommand(): void {
		self::assertSame('/console/app:import/customers', $this->line('env-first')['p']);
	}

	/** A worker that set its own REQUEST_URI is saying what it stands in for, and still wins. */
	public function testACommandThatNamedItselfIsLeftAlone(): void {
		self::assertSame('/queue/email', $this->line('preset')['p']);
	}

	/**
	 * The guard, in the configuration it was written for: an application whose
	 * `auto_prepend_file` is set in the php-fpm pool has no hook on the command line, so there is
	 * no reader for `REQUEST_URI` - which is a variable several libraries take as "this is a web
	 * request". Only a real process can show that nothing is published.
	 */
	public function testNothingIsPublishedWhenThereIsNoHookInTheProcess(): void {
		$log    = $this->log();
		$output = $this->execute('no-hook', $log);

		self::assertSame(['uri' => null], json_decode($output, true, 4, JSON_THROW_ON_ERROR));
		self::assertFileDoesNotExist($log);
	}

	/**
	 * With the hook loaded but the command-line switch off, nothing is measured.
	 *
	 * Deliberately no assertion about `REQUEST_URI` here, because `BCPERF_ACTIVE` does not mean
	 * the same thing in both versions: 2.0 defines it whenever the file is loaded, 2.1 only once
	 * the hook is switched on. So on the pinned 2.0 the listener does publish a name that nothing
	 * will read - harmless, and not worth a second guard that would have to duplicate the hook's
	 * three-channel settings lookup to do better.
	 */
	public function testNoLineIsWrittenWhenCommandsAreNotRecorded(): void {
		$log = $this->log();
		$this->execute('no-cli', $log);

		self::assertFileDoesNotExist($log);
	}

	/** @return array<string,mixed> */
	private function line(string $mode): array {
		$log = $this->log();

		$output = $this->execute($mode, $log);
		if ($output !== '') {
			throw new RuntimeException('The command produced output: ' . $output);
		}

		$contents = (string) file_get_contents($log);
		unlink($log);

		/** @var array<string,mixed> $line */
		$line = json_decode(trim($contents), true, 8, JSON_THROW_ON_ERROR);

		return $line;
	}

	private function execute(string $mode, string $log): string {
		$output = [];
		$status = 0;
		exec(
			escapeshellarg(PHP_BINARY) . ' '
			. escapeshellarg(dirname(__DIR__) . '/Fixture/console_command_then_shutdown.php') . ' '
			. escapeshellarg($log) . ' ' . escapeshellarg($mode) . ' 2>&1',
			$output,
			$status,
		);

		if ($status !== 0) {
			throw new RuntimeException('The command process failed: ' . implode("\n", $output));
		}

		return implode("\n", $output);
	}

	private function log(): string {
		return sys_get_temp_dir() . '/bcperf-console-contract-' . bin2hex(random_bytes(6)) . '.jsonl';
	}
}
