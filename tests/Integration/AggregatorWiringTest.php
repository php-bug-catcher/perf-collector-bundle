<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Tests\Integration;

use BugCatcher\PerfCollector\Aggregate\SampleGrouper;
use BugCatcher\PerfCollector\Aggregator;
use BugCatcher\PerfCollector\Clock;
use BugCatcher\PerfCollector\Exception\InvalidConfiguration;
use BugCatcher\PerfCollector\FileLock;
use BugCatcher\PerfCollector\Log\CursorStore;
use BugCatcher\PerfCollector\Log\FileCursorStore;
use BugCatcher\PerfCollector\Log\JsonLinesReader;
use BugCatcher\PerfCollector\Log\LogPathResolver;
use BugCatcher\PerfCollector\Normalize\PathNormalizer;
use BugCatcher\PerfCollector\Sample\SampleDecoder;
use BugCatcher\PerfCollector\Ship\BatchPayloadBuilder;
use BugCatcher\PerfCollector\Ship\HttpShipper;
use BugCatcher\PerfCollector\SystemClock;
use BugCatcher\PerfCollectorBundle\PerfCollectorBundle;
use BugCatcher\PerfCollectorBundle\Tests\ContainerFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * `bin/bc-perf-aggregate` composes the collector by hand in `AggregateCli`; this bundle composes
 * the same graph out of the container. These tests are what keeps the two from drifting: every
 * collaborator the CLI passes has to be here, built from configuration rather than from argv.
 */
final class AggregatorWiringTest extends TestCase {

	public function testTheAggregatorIsThereAndEveryArgumentResolves(): void {
		self::assertInstanceOf(Aggregator::class, ContainerFactory::compiled()->get(Aggregator::class));
	}

	#[DataProvider('collaborators')]
	public function testEveryCollaboratorTheCliBuildsIsAService(string $id, string $expected): void {
		self::assertInstanceOf($expected, ContainerFactory::compiled()->get($id));
	}

	/** @return iterable<string,array{string,string}> */
	public static function collaborators(): iterable {
		yield 'path resolver' => [LogPathResolver::class, LogPathResolver::class];
		yield 'cursor store' => [FileCursorStore::class, FileCursorStore::class];
		yield 'cursor store interface' => [CursorStore::class, FileCursorStore::class];
		yield 'reader' => [JsonLinesReader::class, JsonLinesReader::class];
		yield 'decoder' => [SampleDecoder::class, SampleDecoder::class];
		yield 'grouper' => [SampleGrouper::class, SampleGrouper::class];
		yield 'normalizer' => [PathNormalizer::class, PathNormalizer::class];
		yield 'payload builder' => [BatchPayloadBuilder::class, BatchPayloadBuilder::class];
		yield 'shipper' => [HttpShipper::class, HttpShipper::class];
		yield 'lock' => [FileLock::class, FileLock::class];
		yield 'clock' => [Clock::class, SystemClock::class];
	}

	#[DataProvider('configuredArguments')]
	public function testAConfiguredValueReachesTheObjectThatNeedsIt(
		array $config,
		string $service,
		string $argument,
		mixed $expected,
	): void {
		$definition = ContainerFactory::loaded($config)->getDefinition($service);

		self::assertSame($expected, $definition->getArgument($argument));
	}

	/** @return iterable<string,array{array<string,mixed>,string,string,mixed}> */
	public static function configuredArguments(): iterable {
		yield 'the log pattern' => [
			['log' => '/tmp/other.jsonl'], Aggregator::class, '$logPattern', '/tmp/other.jsonl',
		];
		yield 'the project code' => [
			['project' => 'shop'], Aggregator::class, '$projectCode', 'shop',
		];
		yield 'the read budget' => [
			['max_bytes' => 4096], Aggregator::class, '$maxBytesPerRun', 4096,
		];
		yield 'the endpoint' => [
			['endpoint' => 'https://bc.test'], HttpShipper::class, '$endpoint', 'https://bc.test',
		];
		yield 'the token' => [
			['token' => 's3cret'], HttpShipper::class, '$token', 's3cret',
		];
		yield 'no token at all' => [
			[], HttpShipper::class, '$token', null,
		];
		yield 'the state directory' => [
			['state_dir' => '/var/lib/bcperf'], FileCursorStore::class, '$stateDir', '/var/lib/bcperf',
		];
	}

	/** The real run is not a dry run; that is a flag on the command, not on the installation. */
	public function testTheAggregatorIsNotADryRun(): void {
		self::assertFalse(ContainerFactory::loaded()->getDefinition(Aggregator::class)->getArgument('$dryRun'));
	}

	public function testThereIsASecondAggregatorForDryRuns(): void {
		$container = ContainerFactory::loaded();

		self::assertTrue($container->getDefinition(PerfCollectorBundle::DRY_RUN_AGGREGATOR)->getArgument('$dryRun'));
	}

	public function testTheDryRunAggregatorIsTheSameGraphOtherwise(): void {
		$container = ContainerFactory::loaded();

		$live   = $container->getDefinition(Aggregator::class)->getArguments();
		$dryRun = $container->getDefinition(PerfCollectorBundle::DRY_RUN_AGGREGATOR)->getArguments();

		unset($live['$dryRun'], $dryRun['$dryRun']);
		self::assertEquals($live, $dryRun);
	}

	/**
	 * `FileLock` opens its file before anything else happens, so the default has to be somewhere
	 * the command has already made sure exists - the state directory it owns.
	 */
	public function testTheLockFileSitsInTheStateDirectoryByDefault(): void {
		$definition = ContainerFactory::loaded(['state_dir' => '/var/lib/bcperf'])->getDefinition(FileLock::class);

		self::assertSame('/var/lib/bcperf/aggregate.lock', $definition->getArgument('$path'));
	}

	public function testATrailingSlashOnTheStateDirectoryDoesNotDoubleUp(): void {
		$definition = ContainerFactory::loaded(['state_dir' => '/var/lib/bcperf/'])->getDefinition(FileLock::class);

		self::assertSame('/var/lib/bcperf/aggregate.lock', $definition->getArgument('$path'));
	}

	public function testTheLockFileCanBePutSomewhereElse(): void {
		$definition = ContainerFactory::loaded(['lock_file' => '/run/lock/bcperf.lock'])->getDefinition(FileLock::class);

		self::assertSame('/run/lock/bcperf.lock', $definition->getArgument('$path'));
	}

	public function testTheNormalizerShipsWithTheDefaultRules(): void {
		$normalizer = ContainerFactory::compiled()->get(PathNormalizer::class);

		self::assertSame('/user/{id}', $normalizer->normalize('/user/4711'));
	}

	public function testARulesFileIsReadAndAddsToTheDefaults(): void {
		$rules = $this->rulesFile([['pattern' => '~/sku/[a-z]+~', 'replacement' => '/sku/{sku}']]);

		$normalizer = ContainerFactory::compiled(['rules_file' => $rules])->get(PathNormalizer::class);

		self::assertSame('/sku/{sku}', $normalizer->normalize('/sku/widget'));
		self::assertSame('/user/{id}', $normalizer->normalize('/user/4711'));
	}

	public function testTheDefaultRulesCanBeTurnedOff(): void {
		$rules = $this->rulesFile([['pattern' => '~/sku/[a-z]+~', 'replacement' => '/sku/{sku}']]);

		$normalizer = ContainerFactory::compiled(['rules_file' => $rules, 'default_rules' => false])
			->get(PathNormalizer::class);

		self::assertSame('/sku/{sku}', $normalizer->normalize('/sku/widget'));
		self::assertSame('/user/4711', $normalizer->normalize('/user/4711'));
	}

	public function testATypoInTheRulesFileStopsTheRunWithAnExplanation(): void {
		$this->expectException(InvalidConfiguration::class);

		ContainerFactory::compiled(['rules_file' => '/nowhere/rules.json'])->get(PathNormalizer::class);
	}

	/**
	 * A rotation pattern is full of per cent signs and the container resolves those as parameters,
	 * so `%%Y%%m%%d` in YAML is the only spelling that reaches the collector intact. Pinned here
	 * because the README promises it.
	 */
	public function testPerCentSignsInTheLogPatternSurviveTheContainer(): void {
		$aggregator = ContainerFactory::compiled(['log' => '/var/log/bcperf-%%Y%%m%%d.jsonl'])->get(Aggregator::class);

		self::assertSame(
			'/var/log/bcperf-%Y%m%d.jsonl',
			(new ReflectionProperty(Aggregator::class, 'logPattern'))->getValue($aggregator),
		);
	}

	/**
	 * The hook reads BCPERF_LOG and so, if you ask it to, does this - one value, two readers, no
	 * way for them to disagree. It is also the spelling that sidesteps the per cent signs above,
	 * which is why the README leads with it.
	 */
	public function testTheLogPathCanComeFromTheEnvironmentTheHookAlreadyReads(): void {
		$_ENV['BCPERF_LOG'] = '/dev/shm/bcperf-%Y%m%d.jsonl';

		try {
			$aggregator = ContainerFactory::compiled(['log' => '%env(BCPERF_LOG)%'])->get(Aggregator::class);

			self::assertSame(
				'/dev/shm/bcperf-%Y%m%d.jsonl',
				(new ReflectionProperty(Aggregator::class, 'logPattern'))->getValue($aggregator),
			);
		} finally {
			unset($_ENV['BCPERF_LOG']);
		}
	}

	/** @param list<array{pattern:string,replacement:string}> $rules */
	private function rulesFile(array $rules): string {
		$path = tempnam(sys_get_temp_dir(), 'bcperf-rules-');
		self::assertIsString($path);
		file_put_contents($path, json_encode($rules, JSON_THROW_ON_ERROR));

		return $path;
	}
}
