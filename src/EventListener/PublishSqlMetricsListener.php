<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\EventListener;

use BugCatcher\PerfCollectorBundle\Sql\SqlMetrics;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Hands the SQL numbers to the collector's hook.
 *
 * `$GLOBALS['_bcperf_extra']` is the collector's documented extension point: the hook reads it
 * while building the line and keeps every numeric entry, so anything an application can count -
 * a query count, a cache hit rate, a custom timer - rides along with the request's own
 * measurements. This listener is the reference implementation of that hook, and the Doctrine
 * numbers are what an application most often wants beside its wallclock.
 *
 * **Why terminate.** The hook writes its line from a `register_shutdown_function()` handler and
 * reads the global there, not when it is registered. Shutdown handlers run after the script's
 * last statement, which is after `$kernel->terminate()`; `fastcgi_finish_request()`, which
 * `Response::send()` has already called by then, closes the connection to the client but does
 * not end the process. So `kernel.terminate` is both the last moment the application controls
 * and still early enough - and it is after the response, so this costs the visitor nothing.
 * `Tests\Functional\HookContractTest` runs that sequence in a real process rather than taking
 * it on trust.
 *
 * `console.terminate` as well, for a CLI process recorded with `BCPERF_CLI=1`: a worker is one
 * sample for the whole run, and that run ends there.
 */
final readonly class PublishSqlMetricsListener {

	/** The global the hook merges into every line, under the key `e`. */
	public const string EXTRA_GLOBAL = '_bcperf_extra';

	/** Short, because the hook's line has 4096 bytes and the server stores the name per bucket. */
	public const string QUERY_COUNT = 'sq';

	/** Seconds. Every other duration the collector ships is in seconds too. */
	public const string QUERY_SECONDS = 'st';

	public function __construct(private SqlMetrics $metrics) {
	}

	#[AsEventListener(KernelEvents::TERMINATE)]
	#[AsEventListener(ConsoleEvents::TERMINATE)]
	public function publish(): void {
		$extra = $GLOBALS[self::EXTRA_GLOBAL] ?? null;
		// The hook ignores a global that is not an array, so replacing one loses nothing it
		// would have read anyway.
		if (!is_array($extra)) {
			$extra = [];
		}

		// Zero queries is a measurement: it is what tells a cached route apart from one nobody
		// has instrumented. The rounding the hook applies is its own business.
		$extra[self::QUERY_COUNT]   = $this->metrics->queryCount();
		$extra[self::QUERY_SECONDS] = $this->metrics->totalSeconds();

		$GLOBALS[self::EXTRA_GLOBAL] = $extra;
	}
}
