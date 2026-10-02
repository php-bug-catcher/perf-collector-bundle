<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Tests\Unit\Sql;

use BugCatcher\PerfCollectorBundle\Sql\SqlMetrics;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The counter the DBAL decorators feed and the listener reads. It is deliberately the only place
 * that knows what timing a query means, so neither decorator has to.
 */
final class SqlMetricsTest extends TestCase {

	public function testARequestThatQueriedNothingSaysSo(): void {
		$metrics = new SqlMetrics();

		self::assertSame(0, $metrics->queryCount());
		self::assertSame(0.0, $metrics->totalSeconds());
	}

	public function testQueriesAreCountedAndTheirTimeAdded(): void {
		$metrics = new SqlMetrics();

		$metrics->addQuery(0.25);
		$metrics->addQuery(0.5);

		self::assertSame(2, $metrics->queryCount());
		self::assertSame(0.75, $metrics->totalSeconds());
	}

	public function testMeasureGivesBackWhatTheQueryReturned(): void {
		$metrics = new SqlMetrics();

		self::assertSame('rows', $metrics->measure(static fn (): string => 'rows'));
	}

	public function testMeasureTimesTheQuery(): void {
		$metrics = new SqlMetrics();

		$metrics->measure(static fn () => usleep(2_000));

		self::assertSame(1, $metrics->queryCount());
		self::assertGreaterThan(0.001, $metrics->totalSeconds());
	}

	/** A query that failed still took the time it took, and the caller still has to see why. */
	public function testAQueryThatThrowsIsStillCounted(): void {
		$metrics = new SqlMetrics();

		try {
			$metrics->measure(static fn () => throw new RuntimeException('syntax error'));
			self::fail('The exception should have been left alone.');
		} catch (RuntimeException $e) {
			self::assertSame('syntax error', $e->getMessage());
		}

		self::assertSame(1, $metrics->queryCount());
	}
}
