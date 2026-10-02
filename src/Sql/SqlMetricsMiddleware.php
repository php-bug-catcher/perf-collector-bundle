<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Sql;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Puts the counter on a DBAL connection. Registered by `PerfCollectorBundle` with the
 * `doctrine.middleware` tag, and only when doctrine/dbal is installed and `sql_metrics` is on.
 */
final readonly class SqlMetricsMiddleware implements Middleware {

	public function __construct(private SqlMetrics $metrics) {
	}

	public function wrap(Driver $driver): Driver {
		return new SqlMetricsDriver($driver, $this->metrics);
	}
}
