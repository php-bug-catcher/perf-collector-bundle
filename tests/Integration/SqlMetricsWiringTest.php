<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Tests\Integration;

use BugCatcher\PerfCollector\Aggregator;
use BugCatcher\PerfCollectorBundle\EventListener\PublishSqlMetricsListener;
use BugCatcher\PerfCollectorBundle\Sql\SqlMetrics;
use BugCatcher\PerfCollectorBundle\Sql\SqlMetricsMiddleware;
use BugCatcher\PerfCollectorBundle\Tests\ContainerFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use BugCatcher\PerfCollectorBundle\Tests\NeedsDoctrineDbal;
use PHPUnit\Framework\TestCase;

/**
 * doctrine/dbal is a suggestion, not a requirement, and `sql_metrics` is a switch. Between them
 * they decide whether any of the SQL side exists at all - a middleware that wraps every database
 * connection in the application is not something to register on a maybe.
 *
 * The other half of this, the half that cannot be tested in a process where Doctrine is
 * installed, is `Sql\WithoutDoctrineTest`.
 */
final class SqlMetricsWiringTest extends TestCase {

	use NeedsDoctrineDbal;

	#[DataProvider('sqlServices')]
	public function testTheSqlSideIsThereWhenDoctrineIsAndTheSwitchIsOn(string $id): void {
		self::assertTrue(ContainerFactory::loaded()->hasDefinition($id));
	}

	#[DataProvider('sqlServices')]
	public function testNothingIsRegisteredWhenTheSwitchIsOff(string $id): void {
		self::assertFalse(ContainerFactory::loaded(['sql_metrics' => false])->hasDefinition($id));
	}

	/** @return iterable<string,array{string}> */
	public static function sqlServices(): iterable {
		yield 'the counter' => [SqlMetrics::class];
		yield 'the middleware' => [SqlMetricsMiddleware::class];
		yield 'the listener' => [PublishSqlMetricsListener::class];
	}

	/** Without the tag, DoctrineBundle never puts the middleware on a connection. */
	public function testTheMiddlewareIsTaggedForDoctrine(): void {
		$definition = ContainerFactory::loaded()->getDefinition(SqlMetricsMiddleware::class);

		self::assertArrayHasKey('doctrine.middleware', $definition->getTags());
	}

	/** Turning the SQL side off leaves the aggregator exactly where it was. */
	public function testTheRestOfTheBundleDoesNotCare(): void {
		self::assertTrue(ContainerFactory::loaded(['sql_metrics' => false])->hasDefinition(Aggregator::class));
	}
}
