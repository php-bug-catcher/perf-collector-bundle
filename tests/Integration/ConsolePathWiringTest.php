<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Tests\Integration;

use BugCatcher\PerfCollector\Aggregator;
use BugCatcher\PerfCollectorBundle\Console\ConsolePathBuilder;
use BugCatcher\PerfCollectorBundle\EventListener\PublishConsolePathListener;
use BugCatcher\PerfCollectorBundle\Tests\ContainerFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `console_path` is a switch over two services, and the denylist is the one configured value here
 * that an operator can only make longer. Both are assertions about the extension, which is this
 * package's published contract.
 */
final class ConsolePathWiringTest extends TestCase {

	#[DataProvider('consoleServices')]
	public function testTheConsoleSideIsThereByDefault(string $id): void {
		self::assertTrue(ContainerFactory::loaded()->hasDefinition($id));
	}

	#[DataProvider('consoleServices')]
	public function testNothingIsRegisteredWhenTheSwitchIsOff(string $id): void {
		self::assertFalse(ContainerFactory::loaded(['console_path' => false])->hasDefinition($id));
	}

	/** @return iterable<string,array{string}> */
	public static function consoleServices(): iterable {
		yield 'the builder' => [ConsolePathBuilder::class];
		yield 'the listener' => [PublishConsolePathListener::class];
	}

	/** Turning console naming off leaves the aggregator exactly where it was. */
	public function testTheRestOfTheBundleDoesNotCare(): void {
		self::assertTrue(ContainerFactory::loaded(['console_path' => false])->hasDefinition(Aggregator::class));
	}

	/** Instantiating it is the only honest proof that its arguments resolve. */
	public function testTheBuilderCanActuallyBeBuilt(): void {
		self::assertInstanceOf(
			ConsolePathBuilder::class,
			ContainerFactory::compiled()->get(ConsolePathBuilder::class),
		);
	}

	/**
	 * A path cannot be scrubbed after the fact, so the only safe direction for this list is
	 * longer. The built-in names come first on purpose: `getArgument('$redacted')` is then what
	 * shows an operator exactly what their own addition did.
	 */
	public function testAnApplicationAddsToTheDenylistRatherThanReplacingIt(): void {
		$redacted = $this->argument(['console_path_redact' => ['ssn', 'iban']], '$redacted');

		self::assertSame([...ConsolePathBuilder::REDACTED_ARGUMENTS, 'ssn', 'iban'], $redacted);
	}

	public function testTheBuiltInDenylistIsWhatArrivesWithNoConfiguration(): void {
		self::assertSame(ConsolePathBuilder::REDACTED_ARGUMENTS, $this->argument([], '$redacted'));
	}

	/** One name is what an operator writes, and a scalar is not a list. */
	public function testASingleNameDoesNotHaveToBeWrittenAsAList(): void {
		$redacted = $this->argument(['console_path_redact' => 'ssn'], '$redacted');

		self::assertSame([...ConsolePathBuilder::REDACTED_ARGUMENTS, 'ssn'], $redacted);
	}

	public function testArgumentsCanBeLeftOutOfThePath(): void {
		self::assertFalse($this->argument(['console_path_arguments' => false], '$withArguments'));
	}

	public function testArgumentsAreInThePathByDefault(): void {
		self::assertTrue($this->argument([], '$withArguments'));
	}

	/** @param array<string,mixed> $config */
	private function argument(array $config, string $name): mixed {
		return ContainerFactory::loaded($config)
			->getDefinition(ConsolePathBuilder::class)
			->getArgument($name);
	}
}
