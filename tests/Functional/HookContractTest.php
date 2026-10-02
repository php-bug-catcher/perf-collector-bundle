<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The one thing that makes `kernel.terminate` the right place: the hook reads
 * `$GLOBALS['_bcperf_extra']` inside a `register_shutdown_function()` handler, and shutdown
 * handlers run after the last line of the script - after `$kernel->terminate()`, and after
 * `fastcgi_finish_request()`, which closes the connection to the client but does not end the
 * process.
 *
 * Arguing that from the source is not the same as seeing it, so this runs a real process with
 * the collector's real hook prepended and reads the line it wrote.
 */
final class HookContractTest extends TestCase {

	/** @var array<string,mixed> */
	private static array $line;

	public static function setUpBeforeClass(): void {
		$log = sys_get_temp_dir() . '/bcperf-hook-contract-' . bin2hex(random_bytes(6)) . '.jsonl';

		$output = [];
		$status = 0;
		exec(
			escapeshellarg(PHP_BINARY) . ' '
			. escapeshellarg(dirname(__DIR__) . '/Fixture/terminate_then_shutdown.php') . ' '
			. escapeshellarg($log) . ' 2>&1',
			$output,
			$status,
		);

		if ($status !== 0) {
			throw new RuntimeException('The request process failed: ' . implode("\n", $output));
		}

		// Nothing at all on stdout or stderr: a monitoring hook that prints is a broken response.
		if ($output !== []) {
			throw new RuntimeException('The request produced output: ' . implode("\n", $output));
		}

		$contents = (string) file_get_contents($log);
		unlink($log);

		/** @var array<string,mixed> $line */
		$line = json_decode(trim($contents), true, 8, JSON_THROW_ON_ERROR);

		self::$line = $line;
	}

	public function testTheHookWroteItsLineAfterKernelTerminate(): void {
		self::assertArrayHasKey('e', self::$line);
	}

	public function testTheMetricsArrivedUnderTheKeysTheServerStores(): void {
		self::assertSame(['sq' => 2, 'st' => 0.75], self::$line['e']);
	}

	/**
	 * The hook keeps integers and floats and silently drops anything else, so the types the
	 * listener publishes are not a detail.
	 */
	public function testTheTypesSurvivedTheHooksFilter(): void {
		self::assertIsInt(self::$line['e']['sq']);
		self::assertIsFloat(self::$line['e']['st']);
	}
}
