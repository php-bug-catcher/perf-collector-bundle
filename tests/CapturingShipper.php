<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Tests;

use BugCatcher\PerfCollector\Ship\HttpShipper;

/**
 * The seam `HttpShipper` leaves open, used the way the collector's own tests use it: a real
 * aggregator, a real log file, a real cursor - everything but the network.
 */
final class CapturingShipper extends HttpShipper {

	/** @var list<string> */
	public array $bodies = [];

	public function __construct(private readonly int $status = 204) {
		parent::__construct('https://bugcatcher.example.com');
	}

	protected function request(string $url, string $body, array $headers): array {
		$this->bodies[] = $body;

		return [$this->status, ''];
	}
}
