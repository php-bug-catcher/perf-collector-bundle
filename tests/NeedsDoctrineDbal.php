<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Tests;

use Doctrine\DBAL\Driver;

/**
 * For the tests that need the thing this package only *suggests*.
 *
 * `doctrine/dbal` is a suggestion: an application that does not use Doctrine installs this bundle
 * for the aggregate command alone, and the bundle has to boot without the SQL metrics. The suite
 * therefore has to be runnable in that configuration too - otherwise the promise is only tested
 * in the one direction where it cannot fail.
 *
 * `WithoutDoctrineTest` proves the container builds; these skip, because what they exercise is
 * the decorators themselves and there is nothing to decorate.
 */
trait NeedsDoctrineDbal {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if (!interface_exists(Driver::class)) {
			self::markTestSkipped('doctrine/dbal is not installed; the SQL metrics are a suggestion.');
		}
	}
}
