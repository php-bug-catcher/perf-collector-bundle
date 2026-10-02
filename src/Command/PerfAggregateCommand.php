<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Command;

use BugCatcher\PerfCollector\Aggregator;
use BugCatcher\PerfCollector\Directory;
use BugCatcher\PerfCollector\Exception\InvalidConfiguration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bc-perf-aggregate`, with the command line replaced by `config/packages`. Run it every minute
 * from cron on each monitored machine; the README has the entry.
 *
 * The exit codes are the binary's, deliberately: cron entries and monitoring get the same answer
 * whichever of the two an installation ends up using.
 */
#[AsCommand(
	name: 'bug-catcher:perf-aggregate',
	description: 'Roll the local performance log up into per-minute buckets and ship them to Bug Catcher',
)]
final class PerfAggregateCommand extends Command {

	public function __construct(
		private readonly Aggregator $aggregator,
		private readonly Aggregator $dryRunAggregator,
		private readonly string $stateDir,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->addOption(
			'dry-run',
			null,
			InputOption::VALUE_NONE,
			'Read, group and report. Ship nothing, move no cursor, delete nothing.',
		);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
		$dryRun = (bool) $input->getOption('dry-run');

		try {
			// The state directory is ours to create - FileLock opens its file before anything
			// else happens and will not make directories on the way, so the first run of a fresh
			// install would otherwise fail on a missing parent.
			Directory::ensure($this->stateDir);

			$result = ($dryRun ? $this->dryRunAggregator : $this->aggregator)->run();
		} catch (InvalidConfiguration $e) {
			$errors->writeln($e->getMessage());

			return self::INVALID;
		}

		// A dry run never ships, so it cannot have failed to. Anything else that grouped buckets
		// and did not get a 2xx has left the cursor where it was: cron should say so out loud.
		if (!$dryRun && !$result->shipped && $result->bucketsShipped > 0) {
			$errors->writeln($result->summary());

			return self::FAILURE;
		}

		$output->writeln($result->summary());

		return self::SUCCESS;
	}
}
