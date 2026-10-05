<?php

declare(strict_types=1);

/**
 * One console run, the way PHP really runs it: the hook is prepended, a real
 * `Console\Application` resolves and runs a command - which is what binds the input and dispatches
 * `console.command` - and only then does the process end and the hook's shutdown handler write its
 * line. Run by `Tests\Functional\ConsoleHookContractTest`.
 *
 * Nothing here stands in for anything. The hook is the collector's own `hook/collector.php`,
 * required the way `auto_prepend_file` would require it, before the autoloader exists; the
 * application is Symfony's, so the binding this feature depends on is Symfony's own code and not a
 * reading of it.
 *
 * @param string $argv[1] the log path to write to
 * @param string $argv[2] which invocation to simulate
 */

use BugCatcher\PerfCollectorBundle\Console\ConsolePathBuilder;
use BugCatcher\PerfCollectorBundle\EventListener\PublishConsolePathListener;
use BugCatcher\PerfCollectorBundle\EventListener\PublishSqlMetricsListener;
use BugCatcher\PerfCollectorBundle\Sql\SqlMetrics;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

$log  = $argv[1];
$mode = $argv[2];

$tokens = match ($mode) {
	// An abbreviation, an application option, two arguments and an array argument - every part of
	// the policy in one command line.
	'resolved' => ['bin/console', 'app:imp', '--no-interaction', 'customers', 'hunter2', 'async', 'failed'],
	// The case the hook's own argv guess gets wrong: it skips `--env` and takes `prod` as the job.
	'env-first' => ['bin/console', '--env', 'prod', 'app:import', 'customers'],
	default => ['bin/console', 'app:import', 'customers'],
};

putenv('BCPERF_LOG=' . $log);
// `no-cli` leaves the command-line switch off, so the hook is loaded and declines to record.
if ($mode !== 'no-cli') {
	putenv('BCPERF_CLI=1');
}

$_SERVER['argv'] = $tokens;

// A worker that named itself, before anything of ours runs.
if ($mode === 'preset') {
	$_SERVER['REQUEST_URI'] = '/queue/email';
}

// `no-hook` is the application whose auto_prepend_file is set in the php-fpm pool: on the command
// line there is no hook at all, and nothing may be published for a reader that does not exist.
if ($mode !== 'no-hook') {
	require dirname(__DIR__, 2) . '/vendor/php-bug-catcher/perf-collector/hook/collector.php';
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$metrics = new SqlMetrics();
$metrics->addQuery(0.5);
$metrics->addQuery(0.25);

$dispatcher = new EventDispatcher();
$dispatcher->addListener(
	ConsoleEvents::COMMAND,
	(new PublishConsolePathListener(new ConsolePathBuilder()))->publish(...),
);
$dispatcher->addListener(ConsoleEvents::TERMINATE, (new PublishSqlMetricsListener($metrics))->publish(...));

$command = new class extends Command {
	protected function configure(): void {
		$this->setName('app:import')
			->setAliases(['app:legacy-import'])
			->addArgument('target', InputArgument::OPTIONAL)
			->addArgument('password', InputArgument::OPTIONAL)
			->addArgument('receivers', InputArgument::IS_ARRAY)
			->addOption('full', null, InputOption::VALUE_NONE);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		return self::SUCCESS;
	}
};

$application = new Application();
$application->setAutoExit(false);
$application->setCatchExceptions(false);
$application->setDispatcher($dispatcher);
// addCommands() rather than add()/addCommand(): the first was removed in Symfony 8 and the second
// does not exist in 6.4, and this fixture runs across the whole CI matrix.
$application->addCommands([$command]);
// What FrameworkBundle adds, and the reason `--env prod app:import` resolves at all: `doRun()`
// binds the application definition first so that `getFirstArgument()` can tell an option's value
// from an argument. The hook, which only has argv, cannot.
$application->getDefinition()->addOption(new InputOption('env', 'e', InputOption::VALUE_REQUIRED));

$application->run(new ArgvInput($tokens), new NullOutput());

if ($mode === 'no-hook' || $mode === 'no-cli') {
	echo json_encode(['uri' => $_SERVER['REQUEST_URI'] ?? null], JSON_THROW_ON_ERROR);
}
