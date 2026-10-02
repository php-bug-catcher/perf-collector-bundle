<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Tests;

use BugCatcher\PerfCollectorBundle\PerfCollectorBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The smallest application that can host this bundle.
 *
 * The container tests build the extension by hand; this one exists for what only a real kernel
 * does - turning `#[AsCommand]` and `#[AsEventListener]` into tags, and dispatching
 * `kernel.terminate`.
 */
final class TestKernel extends Kernel {

	use MicroKernelTrait;

	private readonly string $workDir;

	/** @param array<string,mixed> $config */
	public function __construct(private readonly array $config = []) {
		parent::__construct('test', true);

		$this->workDir = sys_get_temp_dir() . '/bcperf-kernel/' . substr(md5(serialize($this->config)), 0, 12);
	}

	public function registerBundles(): iterable {
		yield new FrameworkBundle();
		yield new PerfCollectorBundle();
	}

	public function getProjectDir(): string {
		return $this->workDir;
	}

	public function getCacheDir(): string {
		return $this->workDir . '/cache';
	}

	public function getLogDir(): string {
		return $this->workDir . '/log';
	}

	public function remove(): void {
		self::removePath($this->workDir);
	}

	protected function configureContainer(ContainerConfigurator $container): void {
		$container->extension('framework', [
			'secret'               => 'bcperf',
			'http_method_override' => false,
			'php_errors'           => ['log' => true],
			'router'               => ['utf8' => true],
		]);
		$container->extension('bug_catcher_perf_collector', $this->config + ContainerFactory::required());
	}

	protected function configureRoutes(RoutingConfigurator $routes): void {
	}

	protected function build(ContainerBuilder $container): void {
		// Everything the bundle registers is private, which is right for an application and
		// unreachable from a test. Only this package's own services are opened up.
		$container->addCompilerPass(new class implements CompilerPassInterface {
			public function process(ContainerBuilder $container): void {
				foreach ($container->getDefinitions() as $id => $definition) {
					if (str_starts_with($id, 'BugCatcher\\') || str_starts_with($id, 'bug_catcher_perf_collector.')) {
						$definition->setPublic(true);
					}
				}
			}
		}, PassConfig::TYPE_BEFORE_OPTIMIZATION, -1024);
	}

	private static function removePath(string $path): void {
		if (is_dir($path) && !is_link($path)) {
			foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
				self::removePath($path . '/' . $entry);
			}
			@rmdir($path);

			return;
		}

		@unlink($path);
	}
}
