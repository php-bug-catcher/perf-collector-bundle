<?php

declare(strict_types=1);

use BugCatcher\PerfCollector\Aggregate\SampleGrouper;
use BugCatcher\PerfCollector\Clock;
use BugCatcher\PerfCollector\Log\JsonLinesReader;
use BugCatcher\PerfCollector\Log\LogPathResolver;
use BugCatcher\PerfCollector\Sample\SampleDecoder;
use BugCatcher\PerfCollector\Ship\BatchPayloadBuilder;
use BugCatcher\PerfCollector\SystemClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The half of the collector's object graph that has nothing to configure. Everything that takes
 * a value from `bug_catcher_perf_collector` is registered in `PerfCollectorBundle::loadExtension()`,
 * because that is the only place the configuration exists.
 *
 * Between the two of them they build what `BugCatcher\PerfCollector\Cli\AggregateCli` builds by
 * hand for `bin/bc-perf-aggregate`. There is no third composition root.
 *
 * @link https://symfony.com/doc/current/bundles/best_practices.html#services
 */
return static function (ContainerConfigurator $container): void {
	$services = $container->services()
		->defaults()
		->autowire()
		->autoconfigure();

	$services->set(SystemClock::class);
	$services->alias(Clock::class, SystemClock::class);

	$services->set(LogPathResolver::class);
	$services->set(JsonLinesReader::class);
	$services->set(SampleDecoder::class);
	$services->set(SampleGrouper::class);
	$services->set(BatchPayloadBuilder::class);
};
