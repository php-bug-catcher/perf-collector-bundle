<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Tests\Unit\EventListener;

use BugCatcher\PerfCollectorBundle\EventListener\PublishSqlMetricsListener;
use BugCatcher\PerfCollectorBundle\Sql\SqlMetrics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The handover to the hook. `$GLOBALS['_bcperf_extra']` is a contract with a file that cannot be
 * autoloaded and knows nothing about Symfony, so everything about it is pinned here: the name of
 * the global, the two keys, the types, and the fact that whatever else is in there survives.
 *
 * The values have to be what the hook keeps (int or float - it drops everything else), what the
 * server's `PerfBucket::EXTRA_NAME_PATTERN` accepts, and - for the time - seconds, which is the
 * unit every other duration on this wire is in.
 */
final class PublishSqlMetricsListenerTest extends TestCase {

	protected function setUp(): void {
		unset($GLOBALS[PublishSqlMetricsListener::EXTRA_GLOBAL]);
	}

	protected function tearDown(): void {
		unset($GLOBALS[PublishSqlMetricsListener::EXTRA_GLOBAL]);
	}

	public function testTheCountAndTheTimeEndUpWhereTheHookLooks(): void {
		$metrics = new SqlMetrics();
		$metrics->addQuery(0.25);
		$metrics->addQuery(0.75);

		(new PublishSqlMetricsListener($metrics))->publish();

		self::assertSame(['sq' => 2, 'st' => 1.0], $GLOBALS['_bcperf_extra']);
	}

	public function testTheCountIsAnIntegerAndTheTimeAFloat(): void {
		(new PublishSqlMetricsListener(new SqlMetrics()))->publish();

		self::assertIsInt($GLOBALS[PublishSqlMetricsListener::EXTRA_GLOBAL][PublishSqlMetricsListener::QUERY_COUNT]);
		self::assertIsFloat($GLOBALS[PublishSqlMetricsListener::EXTRA_GLOBAL][PublishSqlMetricsListener::QUERY_SECONDS]);
	}

	/**
	 * A route that queries nothing is a measurement, not an absence of one - and the bucket's
	 * average per hit is only right if the zeroes are in it.
	 */
	public function testARequestWithoutQueriesStillPublishesZero(): void {
		(new PublishSqlMetricsListener(new SqlMetrics()))->publish();

		self::assertSame(['sq' => 0, 'st' => 0.0], $GLOBALS[PublishSqlMetricsListener::EXTRA_GLOBAL]);
	}

	/** The global is a shared space: WordPress, a custom timer, anybody. It is added to. */
	public function testWhateverElseIsInTheGlobalIsLeftAlone(): void {
		$GLOBALS[PublishSqlMetricsListener::EXTRA_GLOBAL] = ['cache_hits' => 7];

		(new PublishSqlMetricsListener(new SqlMetrics()))->publish();

		self::assertSame(['cache_hits' => 7, 'sq' => 0, 'st' => 0.0], $GLOBALS[PublishSqlMetricsListener::EXTRA_GLOBAL]);
	}

	public function testPublishingTwiceInOneProcessOverwritesRatherThanAccumulates(): void {
		$metrics  = new SqlMetrics();
		$listener = new PublishSqlMetricsListener($metrics);

		$metrics->addQuery(0.5);
		$listener->publish();
		$metrics->addQuery(0.5);
		$listener->publish();

		self::assertSame(['sq' => 2, 'st' => 1.0], $GLOBALS[PublishSqlMetricsListener::EXTRA_GLOBAL]);
	}

	/**
	 * The hook ignores a global that is not an array, so overwriting one is not destroying
	 * anything it would have read.
	 */
	#[DataProvider('nonsense')]
	public function testAGlobalThatIsNotAnArrayIsReplaced(mixed $value): void {
		$GLOBALS[PublishSqlMetricsListener::EXTRA_GLOBAL] = $value;

		(new PublishSqlMetricsListener(new SqlMetrics()))->publish();

		self::assertSame(['sq' => 0, 'st' => 0.0], $GLOBALS[PublishSqlMetricsListener::EXTRA_GLOBAL]);
	}

	/** @return iterable<string,array{mixed}> */
	public static function nonsense(): iterable {
		yield 'a string' => ['queries=3'];
		yield 'null' => [null];
		yield 'an object' => [new \stdClass()];
	}

	/** Both keys have to survive the server's own validation of an extra metric name. */
	#[DataProvider('keys')]
	public function testTheKeysAreNamesTheServerWillStore(string $key): void {
		self::assertSame(1, preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.\-]{0,31}$/', $key));
	}

	/** @return iterable<string,array{string}> */
	public static function keys(): iterable {
		yield 'query count' => [PublishSqlMetricsListener::QUERY_COUNT];
		yield 'query seconds' => [PublishSqlMetricsListener::QUERY_SECONDS];
	}
}
