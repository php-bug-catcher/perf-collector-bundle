<?php

declare(strict_types=1);

/**
 * One request, the way PHP really runs it: the hook is prepended, the application dispatches
 * `kernel.terminate`, and only then does the process end and the hook's shutdown handler write
 * its line. Run by `Tests\Functional\HookContractTest`.
 *
 * Nothing here is a stand-in for the hook - it is the collector's own `hook/collector.php`,
 * required the way `auto_prepend_file` would, before the autoloader exists.
 *
 * @param string $argv[1] the log path to write to
 */

use BugCatcher\PerfCollectorBundle\EventListener\PublishSqlMetricsListener;
use BugCatcher\PerfCollectorBundle\Sql\SqlMetrics;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

putenv('BCPERF_LOG=' . $argv[1]);
putenv('BCPERF_CLI=1');

require dirname(__DIR__, 2) . '/vendor/php-bug-catcher/perf-collector/hook/collector.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$metrics = new SqlMetrics();
$metrics->addQuery(0.5);
$metrics->addQuery(0.25);

$dispatcher = new EventDispatcher();
$dispatcher->addListener(KernelEvents::TERMINATE, (new PublishSqlMetricsListener($metrics))->publish(...));

$kernel = new class implements HttpKernelInterface {
	public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response {
		return new Response();
	}
};

$dispatcher->dispatch(
	new TerminateEvent($kernel, Request::create('/checkout'), new Response()),
	KernelEvents::TERMINATE,
);
