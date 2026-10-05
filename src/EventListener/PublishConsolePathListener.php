<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\EventListener;

use BugCatcher\PerfCollectorBundle\Console\ConsolePathBuilder;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Throwable;

/**
 * Tells the collector's hook what this console run should be called.
 *
 * `$_SERVER['REQUEST_URI']` is the channel, and it is the hook's own idea: it prefers that
 * variable over its argv guess in every 2.x version, with the comment "a CLI worker that sets one
 * is telling us what it is standing in for". So nothing in the hook, the `php.ini` or the
 * aggregator has to change for this to work - which also means an installation already running an
 * older collector gets it by upgrading this bundle alone.
 *
 * **Why `console.command` and not `console.terminate`.** The hook reads the variable in a
 * `register_shutdown_function()` handler, so anywhere before the process ends would do for timing;
 * the reason to be early is that `console.command` is where the input is still guaranteed to be
 * there. Symfony binds the input to the command's definition immediately *before* dispatching this
 * event, on purpose - `Application::doRunCommand()` says "bind before the console.command event,
 * so the listeners have access to input options/arguments" - and swallows a binding failure, after
 * having already assigned the definition. So `getArguments()` here cannot throw and still answers
 * with the definition's defaults merged over whatever parsed.
 *
 * {@see PublishSqlMetricsListener} takes no event argument; this one must, and the asymmetry is
 * the point rather than an oversight - that listener publishes a number it already holds, this one
 * is asking the event what happened.
 *
 * Nothing in here may surface in the command: the body is wrapped in `try/catch (\Throwable)`
 * with an empty body, for the same reason the hook wraps its shutdown handler. Monitoring that can
 * break what it measures is worse than no monitoring.
 */
final readonly class PublishConsolePathListener {

	/**
	 * Defined by the hook, and what it means has moved: in 2.0 it is defined whenever the file is
	 * loaded at all, and from 2.1 only once the hook is switched on for the process (a log path,
	 * plus `bcperf.cli = 1` for a command line). Neither version means "recording this run" - both
	 * define it before the sample rate is applied.
	 *
	 * It is the right guard under either reading, because the question being asked is the weaker
	 * one: is there a hook in this process that will read the variable? An application whose
	 * `auto_prepend_file` is set in the php-fpm pool has none on the command line, and writing
	 * `REQUEST_URI` there would be a side effect with no reader - in a variable several libraries
	 * take as "this is a web request".
	 */
	private const string HOOK_ACTIVE = 'BCPERF_ACTIVE';

	/**
	 * Symfony's own argument, and the first one `mergeApplicationDefinition()` puts in. At this
	 * point it still holds whatever the user typed - an alias, an abbreviation - because
	 * `Command::run()` normalises it to the resolved name only afterwards. The resolved name is
	 * already the second segment of the path, so this one is dropped rather than read.
	 */
	private const string COMMAND_ARGUMENT = 'command';

	public function __construct(private ConsolePathBuilder $paths) {
	}

	#[AsEventListener(ConsoleEvents::COMMAND)]
	public function publish(ConsoleCommandEvent $event): void {
		try {
			// Hook not switched on: there is no reader, and REQUEST_URI in a console process is a
			// variable several libraries treat as "this is a web request". Already set: a worker
			// that named itself is saying what it stands in for, which is the same precedence the
			// hook's own `??` applies.
			if (!defined(self::HOOK_ACTIVE) || isset($_SERVER['REQUEST_URI'])) {
				return;
			}

			$command = $event->getCommand()?->getName() ?? '';
			if ($command === '') {
				// Nothing to name it after. The hook's argv guess is a better answer than a
				// placeholder, and leaving the variable alone is how it gets to make it.
				return;
			}

			$arguments = $event->getInput()->getArguments();
			unset($arguments[self::COMMAND_ARGUMENT]);

			$_SERVER['REQUEST_URI'] = $this->paths->build($this->script(), $command, $arguments);
		} catch (Throwable) {
		}
	}

	/**
	 * The same two sources, in the same order, as the hook's own `bcperf_cli_path()`, so that a
	 * plainly invoked command keeps the name it already had.
	 *
	 * `argv` is guarded rather than cast: `(string) []` is an "Array to string conversion"
	 * warning, not a throwable, so the catch above would not absorb it - and this package's
	 * phpunit configuration fails on a warning.
	 */
	private function script(): string {
		$argv = $_SERVER['argv'] ?? null;

		return is_array($argv) && is_string($argv[0] ?? null)
			? $argv[0]
			: (string) ($_SERVER['SCRIPT_NAME'] ?? '');
	}
}
