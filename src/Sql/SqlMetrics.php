<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Sql;

use Throwable;

/**
 * How many queries this process ran and how long they took.
 *
 * Timing lives here rather than in the decorators so that what counts as a query, and what
 * counts as its duration, is decided once - the connection and the statement decorators then
 * have nothing in them but the call they wrap.
 *
 * Deliberately not reset between requests and deliberately not tagged `kernel.reset`: the hook
 * writes one sample per *process*, so a counter that outlived the request would disagree with
 * the duration measured beside it. Under PHP-FPM a process is a request and the question does
 * not arise; under a worker runtime both numbers cover the worker's whole life, which is at
 * least consistent.
 */
final class SqlMetrics {

	private int $queries = 0;
	private float $seconds = 0.0;

	public function addQuery(float $seconds): void {
		$this->queries++;
		$this->seconds += $seconds;
	}

	/**
	 * Runs a query and counts it. A query that throws is counted as well: it cost the database
	 * the same work, and leaving failures out would leave out exactly the slow ones.
	 *
	 * @template T
	 * @param  callable():T $query
	 * @return T
	 * @throws Throwable whatever the query threw, untouched
	 */
	public function measure(callable $query): mixed {
		$started = microtime(true);

		try {
			return $query();
		} finally {
			$this->addQuery(microtime(true) - $started);
		}
	}

	public function queryCount(): int {
		return $this->queries;
	}

	/** Seconds, like every other duration on this wire. */
	public function totalSeconds(): float {
		return $this->seconds;
	}
}
