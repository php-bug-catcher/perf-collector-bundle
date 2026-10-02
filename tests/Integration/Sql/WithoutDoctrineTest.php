<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Tests\Integration\Sql;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `suggest: doctrine/dbal` is a promise that the bundle works without it, and that promise
 * cannot be kept by a test suite running in a process where Doctrine is installed. So this one
 * starts a process where it is not: `tests/Fixture/container_without_doctrine.php` takes
 * Composer's autoloader apart far enough that `Doctrine\DBAL\` cannot be resolved at all, and
 * then builds the container.
 */
final class WithoutDoctrineTest extends TestCase {

	/** @var array{dbal:bool,aggregator:bool,registered:list<string>} */
	private static array $result;

	public static function setUpBeforeClass(): void {
		$script = dirname(__DIR__, 2) . '/Fixture/container_without_doctrine.php';
		$output = [];
		$status = 0;

		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $status);

		if ($status !== 0) {
			throw new RuntimeException('The container could not be built without Doctrine: ' . implode("\n", $output));
		}

		/** @var array{dbal:bool,aggregator:bool,registered:list<string>} $decoded */
		$decoded = json_decode(implode('', $output), true, 8, JSON_THROW_ON_ERROR);

		self::$result = $decoded;
	}

	/** If this fails the rest of the class proves nothing, so it is asserted rather than assumed. */
	public function testDoctrineReallyIsOutOfReachInThatProcess(): void {
		self::assertFalse(self::$result['dbal']);
	}

	public function testTheContainerStillBuilds(): void {
		self::assertTrue(self::$result['aggregator']);
	}

	public function testNoneOfTheSqlServicesAreRegistered(): void {
		self::assertSame([], self::$result['registered']);
	}
}
