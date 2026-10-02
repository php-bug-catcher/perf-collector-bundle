<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Sql;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** A prepared statement counts once per execution, which is the number of times it hit the database. */
final class SqlMetricsStatement extends AbstractStatementMiddleware {

	public function __construct(Statement $statement, private readonly SqlMetrics $metrics) {
		parent::__construct($statement);
	}

	public function execute(): Result {
		return $this->metrics->measure(fn (): Result => parent::execute());
	}
}
