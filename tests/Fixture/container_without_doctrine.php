<?php

declare(strict_types=1);

/**
 * Builds the bundle's container in a process where doctrine/dbal cannot be loaded, and reports
 * what came out. Run by `Tests\Integration\Sql\WithoutDoctrineTest`.
 *
 * Composer's autoloader is loaded normally - its `files` entries are what make half of Symfony
 * work - and then replaced by one that refuses `Doctrine\DBAL\`. Refusing in a wrapper rather
 * than unsetting the PSR-4 prefix means an optimised classmap cannot quietly undo it.
 */

use BugCatcher\PerfCollectorBundle\EventListener\PublishSqlMetricsListener;
use BugCatcher\PerfCollectorBundle\Sql\SqlMetrics;
use BugCatcher\PerfCollectorBundle\Sql\SqlMetricsMiddleware;
use BugCatcher\PerfCollectorBundle\Tests\ContainerFactory;
use Composer\Autoload\ClassLoader;

/** @var ClassLoader $loader */
$loader = require dirname(__DIR__, 2) . '/vendor/autoload.php';

$loader->unregister();
spl_autoload_register(static function (string $class) use ($loader): void {
	if (str_starts_with($class, 'Doctrine\\DBAL\\')) {
		return;
	}

	$loader->loadClass($class);
});

$container = ContainerFactory::loaded();

echo json_encode([
	'dbal'       => interface_exists('Doctrine\DBAL\Driver\Middleware'),
	'aggregator' => $container->hasDefinition('BugCatcher\PerfCollector\Aggregator'),
	'registered' => array_values(array_filter(
		[SqlMetrics::class, SqlMetricsMiddleware::class, PublishSqlMetricsListener::class],
		static fn (string $id): bool => $container->hasDefinition($id),
	)),
], JSON_THROW_ON_ERROR);
