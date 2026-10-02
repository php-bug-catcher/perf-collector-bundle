<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Tests\Functional;

use BugCatcher\PerfCollectorBundle\EventListener\PublishSqlMetricsListener;
use BugCatcher\PerfCollectorBundle\Sql\SqlMetrics;
use BugCatcher\PerfCollectorBundle\Tests\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * That `#[AsEventListener]` on the listener's method turns into the tags that make it run - the
 * question the hand-built container cannot answer.
 */
final class SqlMetricsOnTerminateTest extends TestCase {

	private TestKernel $kernel;

	protected function setUp(): void {
		unset($GLOBALS[PublishSqlMetricsListener::EXTRA_GLOBAL]);
	}

	protected function tearDown(): void {
		unset($GLOBALS[PublishSqlMetricsListener::EXTRA_GLOBAL]);
		$this->kernel->shutdown();
		$this->kernel->remove();
	}

	public function testAWebRequestPublishesItsQueriesOnTerminate(): void {
		$this->boot();
		$this->metrics()->addQuery(0.125);

		$this->dispatcher()->dispatch(
			new TerminateEvent($this->kernel, Request::create('/checkout'), new Response()),
			KernelEvents::TERMINATE,
		);

		self::assertSame(['sq' => 1, 'st' => 0.125], $GLOBALS[PublishSqlMetricsListener::EXTRA_GLOBAL]);
	}

	/**
	 * Over and above the plan, which names `kernel.terminate` only. A worker started with
	 * `BCPERF_CLI=1` is one sample for the whole process, and `console.terminate` is where that
	 * process ends - without it the one thing a messenger consumer is usually slow at would be
	 * the one thing not measured.
	 */
	public function testAConsoleRunPublishesItsQueriesOnTerminateToo(): void {
		$this->boot();
		$this->metrics()->addQuery(0.125);

		$this->dispatcher()->dispatch(
			new ConsoleTerminateEvent(new Command('app:thing'), new ArrayInput([]), new NullOutput(), 0),
			ConsoleEvents::TERMINATE,
		);

		self::assertSame(['sq' => 1, 'st' => 0.125], $GLOBALS[PublishSqlMetricsListener::EXTRA_GLOBAL]);
	}

	public function testWithTheSwitchOffNothingTouchesTheGlobal(): void {
		$this->boot(['sql_metrics' => false]);

		$this->dispatcher()->dispatch(
			new TerminateEvent($this->kernel, Request::create('/checkout'), new Response()),
			KernelEvents::TERMINATE,
		);

		self::assertArrayNotHasKey(PublishSqlMetricsListener::EXTRA_GLOBAL, $GLOBALS);
	}

	/** @param array<string,mixed> $config */
	private function boot(array $config = []): void {
		$this->kernel = new TestKernel($config);
		$this->kernel->boot();
	}

	private function dispatcher(): EventDispatcherInterface {
		$dispatcher = $this->kernel->getContainer()->get('event_dispatcher');
		self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

		return $dispatcher;
	}

	private function metrics(): SqlMetrics {
		$metrics = $this->kernel->getContainer()->get(SqlMetrics::class);
		self::assertInstanceOf(SqlMetrics::class, $metrics);

		return $metrics;
	}
}
