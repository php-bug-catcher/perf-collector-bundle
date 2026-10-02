<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Sql;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * The three ways a query leaves DBAL: `query()`, `exec()` and a prepared statement. Preparing is
 * not timed - nothing has run yet - so `prepare()` only passes the counter down.
 */
final class SqlMetricsConnection extends AbstractConnectionMiddleware {

	public function __construct(DriverConnection $connection, private readonly SqlMetrics $metrics) {
		parent::__construct($connection);
	}

	public function prepare(string $sql): Statement {
		return new SqlMetricsStatement(parent::prepare($sql), $this->metrics);
	}

	public function query(string $sql): Result {
		return $this->metrics->measure(fn (): Result => parent::query($sql));
	}

	public function exec(string $sql): int|string {
		return $this->metrics->measure(fn (): int|string => parent::exec($sql));
	}
}
