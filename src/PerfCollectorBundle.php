<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle;

use BugCatcher\PerfCollector\Aggregate\SampleGrouper;
use BugCatcher\PerfCollector\Aggregator;
use BugCatcher\PerfCollector\FileLock;
use BugCatcher\PerfCollector\Log\CursorStore;
use BugCatcher\PerfCollector\Log\FileCursorStore;
use BugCatcher\PerfCollector\Log\JsonLinesReader;
use BugCatcher\PerfCollector\Log\LogPathResolver;
use BugCatcher\PerfCollector\Normalize\PathNormalizer;
use BugCatcher\PerfCollector\Sample\SampleDecoder;
use BugCatcher\PerfCollector\Ship\BatchPayloadBuilder;
use BugCatcher\PerfCollector\Ship\HttpShipper;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\AbstractServiceConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Symfony's way of running the Bug Catcher performance collector: the aggregator becomes a
 * console command configured once instead of a cron line full of options.
 *
 * @link https://symfony.com/doc/current/bundles/best_practices.html
 */
final class PerfCollectorBundle extends AbstractBundle {

	/**
	 * The same graph as the aggregator service, with `dryRun` turned on. A dry run is a thing you
	 * do once, not a thing you install, so it cannot be a configuration flag - and `Aggregator` is
	 * readonly, so it cannot be a setter either.
	 */
	public const string DRY_RUN_AGGREGATOR = 'bug_catcher_perf_collector.aggregator.dry_run';

	/** `bc-perf-aggregate` calls it this too; a cursor directory shared with it keeps working. */
	private const string LOCK_FILE_NAME = 'aggregate.lock';

	/** PerfCollector would give us "perf_collector", which says nothing about whose it is. */
	protected string $extensionAlias = 'bug_catcher_perf_collector';

	public function configure(DefinitionConfigurator $definition): void {
		$definition->import('../config/definition.php');
	}

	public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void {
		$container->import('../config/services.php');

		$services = $container->services()
			->defaults()
			->autowire()
			->autoconfigure();

		$stateDir = rtrim((string) $config['state_dir'], '/');

		$services->set(FileCursorStore::class)
			->arg('$stateDir', $stateDir);
		$services->alias(CursorStore::class, FileCursorStore::class);

		$services->set(FileLock::class)
			->arg('$path', $config['lock_file'] ?? $stateDir . '/' . self::LOCK_FILE_NAME);

		$services->set(HttpShipper::class)
			->arg('$endpoint', $config['endpoint'])
			->arg('$token', $config['token']);

		$this->registerNormalizer($services, $config);

		// Configured values are handed over as they come: the container unescapes `%%` once, on
		// the way into the service, which is what makes `%%Y%%m%%d` in YAML arrive as the
		// rotation pattern the hook was given. Escaping here as well would undo that.
		$arguments = static fn (bool $dryRun): array => [
			'$paths'          => service(LogPathResolver::class),
			'$cursors'        => service(CursorStore::class),
			'$reader'         => service(JsonLinesReader::class),
			'$decoder'        => service(SampleDecoder::class),
			'$grouper'        => service(SampleGrouper::class),
			'$payloads'       => service(BatchPayloadBuilder::class),
			'$shipper'        => service(HttpShipper::class),
			'$lock'           => service(FileLock::class),
			'$logPattern'     => $config['log'],
			'$projectCode'    => $config['project'],
			'$maxBytesPerRun' => $config['max_bytes'],
			'$dryRun'         => $dryRun,
		];

		$services->set(Aggregator::class)->args($arguments(false));
		$services->set(self::DRY_RUN_AGGREGATOR, Aggregator::class)->args($arguments(true));
	}

	/**
	 * Two static factories, and which one applies is a question only the configuration can
	 * answer - hence a branch here rather than an argument.
	 *
	 * @param array<string,mixed> $config
	 */
	private function registerNormalizer(AbstractServiceConfigurator $services, array $config): void {
		$normalizer = $services->set(PathNormalizer::class);

		if ($config['rules_file'] === null) {
			$normalizer->factory([PathNormalizer::class, 'withDefaults']);

			return;
		}

		$normalizer
			->factory([PathNormalizer::class, 'fromRulesFile'])
			->args([$config['rules_file'], $config['default_rules']]);
	}
}
