<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Tests\Integration;

use BugCatcher\PerfCollector\Cli\AggregateOptions;
use BugCatcher\PerfCollectorBundle\PerfCollectorBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The configuration tree is this bundle's published contract, so it is tested as one: every
 * default is pinned, and everything an operator can get wrong is rejected with a message rather
 * than carried into the object graph.
 */
final class ConfigurationTest extends TestCase {

	public function testTheRequiredThreeAreEnough(): void {
		$config = $this->process(self::valid());

		self::assertSame('/var/log/p.jsonl', $config['log']);
		self::assertSame('https://bc.test', $config['endpoint']);
		self::assertSame('app', $config['project']);
	}

	public function testTheDefaults(): void {
		$config = $this->process(self::valid());

		self::assertNull($config['token']);
		self::assertSame('%kernel.project_dir%/var/bcperf', $config['state_dir']);
		self::assertNull($config['lock_file']);
		self::assertNull($config['rules_file']);
		self::assertTrue($config['default_rules']);
		self::assertSame(AggregateOptions::DEFAULT_MAX_BYTES, $config['max_bytes']);
		self::assertTrue($config['sql_metrics']);
		self::assertTrue($config['console_path']);
		self::assertTrue($config['console_path_arguments']);
		self::assertSame([], $config['console_path_redact']);
	}

	/**
	 * The cursor is what keeps a shipped batch from being counted twice, and `cache:clear` is a
	 * deploy step, not an event the monitoring should notice.
	 */
	public function testTheStateDirectoryIsNotUnderTheCacheDirectory(): void {
		self::assertStringNotContainsString('cache', $this->process(self::valid())['state_dir']);
	}

	#[DataProvider('requiredKeys')]
	public function testEachOfTheThreeIsRequired(string $missing): void {
		$given = self::valid();
		unset($given[$missing]);

		$this->expectException(InvalidConfigurationException::class);
		$this->expectExceptionMessageMatches('/"' . $missing . '"/');

		$this->process($given);
	}

	#[DataProvider('requiredKeys')]
	public function testNoneOfTheThreeMayBeEmpty(string $key): void {
		$this->expectException(InvalidConfigurationException::class);

		$this->process(self::valid([$key => '']));
	}

	/** @return iterable<string,array{string}> */
	public static function requiredKeys(): iterable {
		yield 'log' => ['log'];
		yield 'endpoint' => ['endpoint'];
		yield 'project' => ['project'];
	}

	#[DataProvider('refusedValues')]
	public function testAValueThatCannotBeMeantIsRefused(string $key, mixed $value): void {
		$this->expectException(InvalidConfigurationException::class);

		$this->process(self::valid([$key => $value]));
	}

	/** @return iterable<string,array{string,mixed}> */
	public static function refusedValues(): iterable {
		yield 'no budget at all' => ['max_bytes', 0];
		yield 'a negative budget' => ['max_bytes', -1];
		yield 'an empty state directory' => ['state_dir', ''];
		yield 'an empty lock file' => ['lock_file', ''];
		yield 'an empty rules file' => ['rules_file', ''];
		yield 'a key nobody reads' => ['sql_metric', true];
		// A substring of every argument name there is, written as something that looks like a
		// harmless blank line in YAML.
		yield 'an empty needle in the denylist' => ['console_path_redact', ['']];
		yield 'a denylist that is not a list of names' => ['console_path_redact', 7];
	}

	/** @param array<string,mixed> $overrides @return array<string,mixed> */
	private static function valid(array $overrides = []): array {
		return $overrides + ['log' => '/var/log/p.jsonl', 'endpoint' => 'https://bc.test', 'project' => 'app'];
	}

	/** @param array<string,mixed> $config @return array<string,mixed> */
	private function process(array $config): array {
		$extension     = (new PerfCollectorBundle())->getContainerExtension();
		$configuration = $extension->getConfiguration([], new ContainerBuilder());

		self::assertNotNull($configuration);

		return (new Processor())->processConfiguration($configuration, [$config]);
	}
}
