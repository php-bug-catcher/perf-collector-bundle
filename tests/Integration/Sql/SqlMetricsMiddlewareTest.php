<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Tests\Integration\Sql;

use BugCatcher\PerfCollectorBundle\Sql\SqlMetrics;
use BugCatcher\PerfCollectorBundle\Sql\SqlMetricsMiddleware;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use BugCatcher\PerfCollectorBundle\Tests\NeedsDoctrineDbal;
use PHPUnit\Framework\TestCase;

/**
 * Against a real DBAL connection, because what is under test is that the decorators sit where
 * DBAL actually sends queries. A mocked `Driver` would only prove that the middleware calls the
 * methods I happened to think of.
 */
final class SqlMetricsMiddlewareTest extends TestCase {

	use NeedsDoctrineDbal;

	private SqlMetrics $metrics;
	private Connection $connection;

	protected function setUp(): void {
		$this->metrics    = new SqlMetrics();
		$this->connection = $this->connect(new SqlMetricsMiddleware($this->metrics));
	}

	public function testAStatementWithoutParametersIsCounted(): void {
		$this->connection->executeQuery('SELECT 1');

		self::assertSame(1, $this->metrics->queryCount());
	}

	public function testAPreparedStatementCountsWhenItRunsAndNotWhenItIsPrepared(): void {
		$statement = $this->connection->prepare('SELECT ?');
		self::assertSame(0, $this->metrics->queryCount());

		$statement->bindValue(1, 1);
		$statement->executeQuery();

		self::assertSame(1, $this->metrics->queryCount());
	}

	/** Reads, writes, DDL, with parameters and without: DBAL has four ways down and all count. */
	public function testEveryKindOfStatementGoesThroughTheCounter(): void {
		$this->connection->executeStatement('CREATE TABLE widget (id INTEGER PRIMARY KEY, name TEXT)');
		$this->connection->executeStatement('INSERT INTO widget (id, name) VALUES (1, ?)', ['sprocket']);
		$this->connection->executeStatement("INSERT INTO widget (id, name) VALUES (2, 'flange')");
		$this->connection->executeQuery('SELECT * FROM widget WHERE id > ?', [0]);

		self::assertSame(4, $this->metrics->queryCount());
	}

	public function testTheTimeIsRecorded(): void {
		$this->connection->executeQuery('SELECT 1');

		self::assertGreaterThan(0.0, $this->metrics->totalSeconds());
	}

	/** It cost the database the same work, and hiding failures hides exactly the slow ones. */
	public function testAQueryThatFailsIsCountedToo(): void {
		$this->expectException(Exception::class);

		try {
			$this->connection->executeQuery('SELECT * FROM nothing_like_that');
		} finally {
			self::assertSame(1, $this->metrics->queryCount());
		}
	}

	public function testWithoutTheMiddlewareNothingIsCounted(): void {
		$this->connect()->executeQuery('SELECT 1');

		self::assertSame(0, $this->metrics->queryCount());
	}

	private function connect(?SqlMetricsMiddleware $middleware = null): Connection {
		$configuration = new Configuration();
		if ($middleware !== null) {
			$configuration->setMiddlewares([$middleware]);
		}

		return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
	}
}
