<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Tests\Functional;

use BugCatcher\PerfCollectorBundle\EventListener\PublishConsolePathListener;
use BugCatcher\PerfCollectorBundle\Tests\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * That `#[AsEventListener(ConsoleEvents::COMMAND)]` turns into the tag that makes it run - the
 * question the hand-built container cannot answer, and the one thing between a correct policy and
 * a policy nobody ever calls.
 */
final class ConsolePathOnCommandTest extends TestCase {

	private TestKernel $kernel;

	protected function tearDown(): void {
		$this->kernel->shutdown();
		$this->kernel->remove();
	}

	public function testTheListenerIsSubscribedToConsoleCommand(): void {
		self::assertTrue($this->isListening());
	}

	public function testItIsNotSubscribedWithTheSwitchOff(): void {
		self::assertFalse($this->isListening(['console_path' => false]));
	}

	/** @param array<string,mixed> $config */
	private function isListening(array $config = []): bool {
		$this->kernel = new TestKernel($config);
		$this->kernel->boot();

		$dispatcher = $this->kernel->getContainer()->get('event_dispatcher');
		self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

		foreach ($dispatcher->getListeners(ConsoleEvents::COMMAND) as $listener) {
			if (is_array($listener) && $listener[0] instanceof PublishConsolePathListener) {
				return true;
			}
		}

		return false;
	}
}
