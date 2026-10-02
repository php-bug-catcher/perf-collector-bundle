<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Sql;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/** Hands the counter on to every connection the driver opens. Connecting is not a query. */
final class SqlMetricsDriver extends AbstractDriverMiddleware {

	public function __construct(Driver $driver, private readonly SqlMetrics $metrics) {
		parent::__construct($driver);
	}

	public function connect(
		#[SensitiveParameter]
		array $params,
	): DriverConnection {
		return new SqlMetricsConnection(parent::connect($params), $this->metrics);
	}
}
