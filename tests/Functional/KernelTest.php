<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Tests\Functional;

use BugCatcher\PerfCollector\Aggregator;
use BugCatcher\PerfCollectorBundle\Command\PerfAggregateCommand;
use BugCatcher\PerfCollectorBundle\PerfCollectorBundle;
use BugCatcher\PerfCollectorBundle\Tests\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * What only a real kernel can answer: that the attributes on the classes in `src/` turn into the
 * tags that make them do anything.
 */
final class KernelTest extends TestCase {

	private TestKernel $kernel;

	protected function setUp(): void {
		$this->kernel = new TestKernel();
		$this->kernel->boot();
	}

	protected function tearDown(): void {
		$this->kernel->shutdown();
		$this->kernel->remove();
	}

	/** Lazily, which is the point of #[AsCommand] carrying the description. */
	public function testTheCommandIsRegisteredUnderTheNameTheCronEntryUses(): void {
		$command = (new Application($this->kernel))->find('bug-catcher:perf-aggregate');

		self::assertSame('bug-catcher:perf-aggregate', $command->getName());
		self::assertNotSame('', $command->getDescription());
		self::assertInstanceOf(PerfAggregateCommand::class, $this->kernel->getContainer()->get(PerfAggregateCommand::class));
	}

	public function testTheCommandRunsAgainstTheConfiguredAggregator(): void {
		$application = new Application($this->kernel);
		$application->setAutoExit(false);

		self::assertSame(
			Command::SUCCESS,
			$application->run(
				new ArrayInput(['command' => 'bug-catcher:perf-aggregate', '--dry-run' => true]),
				new NullOutput(),
			),
		);
	}

	public function testTheCommandHoldsBothAggregators(): void {
		$container = $this->kernel->getContainer();

		self::assertInstanceOf(Aggregator::class, $container->get(Aggregator::class));
		self::assertInstanceOf(Aggregator::class, $container->get(PerfCollectorBundle::DRY_RUN_AGGREGATOR));
		self::assertNotSame(
			$container->get(Aggregator::class),
			$container->get(PerfCollectorBundle::DRY_RUN_AGGREGATOR),
		);
	}
}
