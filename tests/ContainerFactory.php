<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Tests;

use BugCatcher\PerfCollectorBundle\PerfCollectorBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Builds the bundle's container the way a kernel would, minus the kernel.
 *
 * The extension is what every test here is really about: the configuration is a published
 * contract and the object graph behind it is the whole package.
 */
final class ContainerFactory {

	/** Anything under %kernel.project_dir% is only ever a path here, never written to. */
	public const string PROJECT_DIR = '/srv/app';

	/**
	 * Extension loaded, nothing else: definitions are there with their arguments still spelled
	 * the way the extension wrote them.
	 *
	 * @param array<string,mixed> $config
	 */
	public static function loaded(array $config = [], ?PerfCollectorBundle $bundle = null): ContainerBuilder {
		$container = self::create($config, $bundle);
		(new MergeExtensionConfigurationPass())->process($container);

		return $container;
	}

	/**
	 * Compiled, with every service left public and in place so a test can actually ask for one -
	 * instantiating a service is the only honest proof that its arguments resolve.
	 *
	 * @param array<string,mixed> $config
	 */
	public static function compiled(array $config = [], ?PerfCollectorBundle $bundle = null): ContainerBuilder {
		$container = self::create($config, $bundle);
		$container->addCompilerPass(self::publicize(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -1024);
		// Env placeholders resolved, because a ContainerBuilder used as a runtime container never
		// resolves them on its own and a kernel's dumped container always has.
		$container->compile(true);

		return $container;
	}

	/** @return array<string,mixed> */
	public static function required(): array {
		return [
			'log'      => '/var/log/bcperf.jsonl',
			'endpoint' => 'https://bugcatcher.example.com',
			'project'  => 'myapp',
		];
	}

	/** @param array<string,mixed> $config */
	private static function create(array $config, ?PerfCollectorBundle $bundle): ContainerBuilder {
		// The default bag on purpose: a kernel uses EnvPlaceholderParameterBag, and %env()% only
		// behaves the way an application will see it when the test container has one too.
		$container = new ContainerBuilder();
		$container->getParameterBag()->add([
			'kernel.project_dir' => self::PROJECT_DIR,
			'kernel.cache_dir'   => self::PROJECT_DIR . '/var/cache/test',
			'kernel.build_dir'   => self::PROJECT_DIR . '/var/cache/test',
			'kernel.environment' => 'test',
			'kernel.debug'       => false,
			'kernel.bundles'     => [],
		]);

		$extension = ($bundle ?? new PerfCollectorBundle())->getContainerExtension();
		$container->registerExtension($extension);
		$container->loadFromExtension($extension->getAlias(), $config + self::required());

		return $container;
	}

	private static function publicize(): CompilerPassInterface {
		return new class implements CompilerPassInterface {
			public function process(ContainerBuilder $container): void {
				foreach ($container->getDefinitions() as $definition) {
					$definition->setPublic(true);
				}
				foreach ($container->getAliases() as $alias) {
					$alias->setPublic(true);
				}
			}
		};
	}
}
