<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Tests\Unit;

use BugCatcher\PerfCollectorBundle\Command\PerfAggregateCommand;
use BugCatcher\PerfCollectorBundle\Console\ConsolePathBuilder;
use BugCatcher\PerfCollectorBundle\PerfCollectorBundle;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The README is the only documentation this package has, and a configuration reference that has
 * fallen behind the configuration is worse than none - so it is checked rather than proofread.
 */
final class ReadmeTest extends TestCase {

	public function testEveryConfigurationKeyIsDocumented(): void {
		$keys = $this->configurationKeys();

		self::assertNotSame([], $keys);
		self::assertSame([], array_diff($keys, $this->documentedKeys()));
	}

	public function testTheReferenceDoesNotInventKeys(): void {
		$documented = $this->documentedKeys();

		self::assertNotSame([], $documented);
		self::assertSame([], array_diff($documented, $this->configurationKeys()));
	}

	public function testTheCronEntryRunsTheCommandThatExists(): void {
		$attributes = (new ReflectionClass(PerfAggregateCommand::class))->getAttributes(AsCommand::class);
		self::assertCount(1, $attributes);

		self::assertStringContainsString((string) $attributes[0]->newInstance()->name, $this->readme());
	}

	/**
	 * An operator has to be able to look up what gets hidden from a path, and a list that has
	 * drifted from the code is worse than no list - the missing name reads as a name that is safe.
	 */
	public function testEveryRedactedArgumentNameIsDocumented(): void {
		$section = $this->section('What a console run is called');

		foreach (ConsolePathBuilder::REDACTED_ARGUMENTS as $name) {
			self::assertStringContainsString('`' . $name . '`', $section, $name);
		}

		self::assertStringContainsString(ConsolePathBuilder::REDACTED, $section);
		self::assertStringContainsString(ConsolePathBuilder::NOTHING, $section);
	}

	/** The one number the collector measured that is big enough to change a decision. */
	public function testTheLogPathAdviceIsThere(): void {
		self::assertStringContainsString('tmpfs', $this->readme());
		self::assertStringContainsString('auto_prepend_file', $this->readme());
	}

	/** @return list<string> */
	private function configurationKeys(): array {
		$extension     = (new PerfCollectorBundle())->getContainerExtension();
		$configuration = $extension->getConfiguration([], new ContainerBuilder());
		self::assertNotNull($configuration);

		$processed = (new Processor())->processConfiguration($configuration, [
			['log' => '/var/log/p.jsonl', 'endpoint' => 'https://bc.test', 'project' => 'app'],
		]);

		$keys = array_keys($processed);
		sort($keys);

		return $keys;
	}

	/** @return list<string> */
	private function documentedKeys(): array {
		$section = $this->section('Configuration');

		preg_match_all('/^\|\s*`([a-z_]+)`\s*\|/m', $section, $matches);
		$keys = array_unique($matches[1]);
		sort($keys);

		return array_values($keys);
	}

	private function section(string $heading): string {
		$readme = $this->readme();
		$start  = strpos($readme, '## ' . $heading);

		if ($start === false) {
			throw new RuntimeException(sprintf('The README has no "## %s" section.', $heading));
		}

		$end = strpos($readme, "\n## ", $start + 1);

		return $end === false ? substr($readme, $start) : substr($readme, $start, $end - $start);
	}

	private function readme(): string {
		return (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
	}
}
