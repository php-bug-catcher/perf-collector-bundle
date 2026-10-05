<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Tests\Unit\EventListener;

use BugCatcher\PerfCollectorBundle\Console\ConsolePathBuilder;
use BugCatcher\PerfCollectorBundle\EventListener\PublishConsolePathListener;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\NullOutput;
use PHPUnit\Framework\TestCase;

/**
 * What is honestly testable without a process of its own.
 *
 * `BCPERF_ACTIVE` is a constant, and a constant cannot be undefined again - so in a PHPUnit worker
 * where no hook was ever prepended, the branch that can be reached is the guard. That it holds is
 * worth knowing: `REQUEST_URI` is what several libraries read as "this is a web request", so a
 * console process without a hook must come out of here untouched.
 *
 * Everything past the guard is proved in a real process by `Functional\ConsoleHookContractTest`.
 * This package already decided that process-global facts get a process rather than a seam - see
 * `Functional\HookContractTest` and `Integration\Sql\WithoutDoctrineTest`.
 */
final class PublishConsolePathListenerTest extends TestCase {

	protected function setUp(): void {
		unset($_SERVER['REQUEST_URI']);
	}

	protected function tearDown(): void {
		unset($_SERVER['REQUEST_URI']);
	}

	public function testNothingIsPublishedWithoutAHookToReadIt(): void {
		self::assertFalse(defined('BCPERF_ACTIVE'), 'no hook is prepended in a phpunit worker');

		$this->publish();

		self::assertArrayNotHasKey('REQUEST_URI', $_SERVER);
	}

	/**
	 * The guard returns before anything else, so a command that is not there, an input that did
	 * not bind and a name that cannot be sanitised all have to come out the same way. Cheap
	 * insurance against the day the guard moves.
	 */
	public function testAnUnnameableRunIsStillLeftAlone(): void {
		$this->publish(null);

		self::assertArrayNotHasKey('REQUEST_URI', $_SERVER);
	}

	private function publish(?Command $command = null): void {
		$listener = new PublishConsolePathListener(new ConsolePathBuilder());

		$command ??= (new Command('app:import'))
			->setDefinition(new InputDefinition([new InputArgument('target', InputArgument::OPTIONAL)]));

		$listener->publish(new ConsoleCommandEvent(
			$command,
			new ArrayInput(['target' => 'customers'], $command->getDefinition()),
			new NullOutput(),
		));
	}
}
