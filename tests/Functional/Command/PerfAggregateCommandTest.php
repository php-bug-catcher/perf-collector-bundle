<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Tests\Functional\Command;

use BugCatcher\PerfCollector\Aggregate\SampleGrouper;
use BugCatcher\PerfCollector\Aggregator;
use BugCatcher\PerfCollector\FileLock;
use BugCatcher\PerfCollector\Log\FileCursorStore;
use BugCatcher\PerfCollector\Log\JsonLinesReader;
use BugCatcher\PerfCollector\Log\LogPathResolver;
use BugCatcher\PerfCollector\Normalize\PathNormalizer;
use BugCatcher\PerfCollector\Sample\SampleDecoder;
use BugCatcher\PerfCollector\Ship\BatchPayloadBuilder;
use BugCatcher\PerfCollector\Ship\HttpShipper;
use BugCatcher\PerfCollector\SystemClock;
use BugCatcher\PerfCollectorBundle\Command\PerfAggregateCommand;
use BugCatcher\PerfCollectorBundle\Tests\CapturingShipper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command is a shell over `Aggregator`, so it is tested over a real one: a real log file, a
 * real cursor, a real lock, and only the HTTP request replaced. What is actually under test is
 * the translation from `AggregateResult` into an exit code, because that is what cron reads.
 */
final class PerfAggregateCommandTest extends TestCase {

	private string $workDir;
	private string $log;
	private string $stateDir;

	protected function setUp(): void {
		$this->workDir  = sys_get_temp_dir() . '/bcperf-command-' . bin2hex(random_bytes(6));
		$this->log      = $this->workDir . '/bcperf.jsonl';
		$this->stateDir = $this->workDir . '/state';

		mkdir($this->workDir, 0o777, true);
	}

	protected function tearDown(): void {
		self::remove($this->workDir);
	}

	private static function remove(string $path): void {
		if (is_dir($path)) {
			foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
				self::remove($path . '/' . $entry);
			}
			@rmdir($path);

			return;
		}

		@unlink($path);
	}

	public function testASuccessfulRunSaysWhatItDidAndSucceeds(): void {
		$this->writeSamples(['/user/1', '/user/2']);
		$shipper = new CapturingShipper();

		$tester = $this->runCommand($shipper);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertStringContainsString('2 samples read, 0 malformed, 1 buckets shipped', $tester->getDisplay());
		self::assertCount(1, $shipper->bodies);
		self::assertStringContainsString('"path":"/user/{id}"', $shipper->bodies[0]);
	}

	public function testNothingToDoIsNotAFailure(): void {
		$tester = $this->runCommand(new CapturingShipper());

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertStringContainsString('0 samples read', $tester->getDisplay());
	}

	/**
	 * The cursor has not moved, so the next minute retries the same window - but cron has to hear
	 * about it, which is what a non-zero exit code is for.
	 */
	public function testARefusedBatchFails(): void {
		$this->writeSamples(['/user/1']);

		$tester = $this->runCommand(new CapturingShipper(500));

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertStringContainsString('not shipped', $tester->getDisplay());
		self::assertStringContainsString('HTTP 500', $tester->getDisplay());
		self::assertStringEqualsFile($this->log, $this->sampleLines(['/user/1']));
	}

	public function testADryRunReadsEverythingAndShipsNothing(): void {
		$this->writeSamples(['/user/1']);
		$shipper = new CapturingShipper();

		$tester = $this->runCommand($shipper, ['--dry-run' => true]);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertStringContainsString('1 samples read, 0 malformed, 1 buckets not shipped', $tester->getDisplay());
		self::assertSame([], $shipper->bodies);
		self::assertStringEqualsFile($this->log, $this->sampleLines(['/user/1']));
	}

	/** A dry run that would have shipped nothing is still a success; there is nothing to report. */
	public function testADryRunDoesNotFailTheWayARefusedBatchDoes(): void {
		$this->writeSamples(['/user/1']);

		$tester = $this->runCommand(new CapturingShipper(500), ['--dry-run' => true]);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
	}

	/** A minute is a short wait. Cron should not be told about an overlap it causes itself. */
	public function testARunThatCannotGetTheLockIsQuietAndSucceeds(): void {
		$this->writeSamples(['/user/1']);
		mkdir($this->stateDir, 0o777, true);

		$held = new FileLock($this->stateDir . '/aggregate.lock');
		self::assertTrue($held->acquire());

		try {
			$tester = $this->runCommand(new CapturingShipper());

			self::assertSame(Command::SUCCESS, $tester->getStatusCode());
			self::assertStringContainsString('another run holds the lock', $tester->getDisplay());
		} finally {
			$held->release();
		}
	}

	/**
	 * `FileLock` opens its file the moment the run starts and refuses to create directories on
	 * the way, so the first run of a fresh install would fail if the command did not make the
	 * state directory first.
	 */
	public function testTheStateDirectoryIsMadeBeforeAnythingNeedsIt(): void {
		$this->stateDir = $this->workDir . '/state/deeper';
		self::assertDirectoryDoesNotExist($this->stateDir);

		$tester = $this->runCommand(new CapturingShipper());

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertDirectoryExists($this->stateDir);
	}

	/** Retrying will not help, so the exit code says so rather than pretending it is transient. */
	public function testAStateDirectoryThatCannotExistIsAConfigurationError(): void {
		touch($this->workDir . '/in-the-way');
		$this->stateDir = $this->workDir . '/in-the-way/state';

		$tester = $this->runCommand(new CapturingShipper());

		self::assertSame(Command::INVALID, $tester->getStatusCode());
		self::assertStringContainsString('in-the-way/state', $tester->getDisplay());
	}

	public function testTheCommandIsCalledWhatTheCronEntrySaysItIs(): void {
		self::assertSame('bug-catcher:perf-aggregate', $this->command(new CapturingShipper())->getName());
	}

	/** @param array<string,mixed> $input */
	private function runCommand(HttpShipper $shipper, array $input = []): CommandTester {
		$tester = new CommandTester($this->command($shipper));
		$tester->execute($input);

		return $tester;
	}

	private function command(HttpShipper $shipper): PerfAggregateCommand {
		return new PerfAggregateCommand(
			$this->aggregator($shipper, false),
			$this->aggregator($shipper, true),
			$this->stateDir,
		);
	}

	private function aggregator(HttpShipper $shipper, bool $dryRun): Aggregator {
		return new Aggregator(
			paths:       new LogPathResolver(new SystemClock()),
			cursors:     new FileCursorStore($this->stateDir),
			reader:      new JsonLinesReader(),
			decoder:     new SampleDecoder(),
			grouper:     new SampleGrouper(PathNormalizer::withDefaults()),
			payloads:    new BatchPayloadBuilder(),
			shipper:     $shipper,
			lock:        new FileLock($this->stateDir . '/aggregate.lock'),
			logPattern:  $this->log,
			projectCode: 'myapp',
			dryRun:      $dryRun,
		);
	}

	/** @param list<string> $paths */
	private function writeSamples(array $paths): void {
		file_put_contents($this->log, $this->sampleLines($paths));
	}

	/** @param list<string> $paths */
	private function sampleLines(array $paths): string {
		$lines = '';
		foreach ($paths as $index => $path) {
			$lines .= json_encode([
				't' => 1759400000.0 + $index,
				'd' => 0.25,
				'u' => 0.2,
				's' => 0.01,
				'm' => 2_097_152,
				'c' => 200,
				'h' => 'www.example.com',
				'p' => $path,
				'x' => 'GET',
				'w' => 1,
				'n' => 'web-01',
			], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		}

		return $lines;
	}
}
