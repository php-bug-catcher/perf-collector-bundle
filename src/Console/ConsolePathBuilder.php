<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Console;

/**
 * What to call a console run, built from what Symfony resolved rather than from argv.
 *
 * The collector's hook names a command-line run itself - `/<script>/<first non-option token>` -
 * and says why it takes only that one token: "folding every argument in would mean a path per
 * invocation". That argument is right about argv and wrong about a Symfony application, because
 * argv is all the hook has. It cannot tell `--env prod` from a job name (so
 * `bin/console --env prod app:sync` is recorded as `/console/prod`), it cannot resolve an alias or
 * an abbreviation, and it cannot know that one of those tokens is called `password`. An
 * application that has booted its kernel knows all three, which is the whole reason this class
 * exists in the bundle and not in the hook.
 *
 * So: **positional arguments in, options out.** `app:import customers --full -vv` is
 * `/console/app:import/customers`, because `customers` is the job and `--full` is how it was asked
 * to run. That keeps the thing the hook was protecting - one path per job, not per invocation -
 * while still telling `app:import customers` apart from `app:import orders`, which is the
 * distinction a dashboard is for.
 *
 * Deliberately free of every Symfony type: it takes the arguments as a name-to-value map and
 * nothing else, which is what makes the whole policy testable without an event, a kernel or a
 * command.
 */
final readonly class ConsolePathBuilder {

	/**
	 * Argument names whose value never reaches the wire, matched case-insensitively as substrings
	 * of the argument's *definition* name - so `--db-password` and `userPassword` are both caught.
	 *
	 * A path is the one field here nobody can scrub later: it is the bucket's identity, it lives
	 * two years in the server's day buckets and for ever in a regression record, and it is read
	 * out again by notifier e-mails and over MCP. There is no second chance at this list.
	 *
	 * Short, ambiguous words are missing on purpose. A bare `key` redacts `keyword`, `auth`
	 * redacts `author`, `pin` redacts `pinned`, `hash` redacts a legitimate `hash` argument. A
	 * monitoring path that silently lost `author` is worse than one an operator had to extend, and
	 * `console_path_redact` is where that decision is visible to the application that made it.
	 *
	 * @var list<string>
	 */
	public const array REDACTED_ARGUMENTS = [
		'password', 'passwd', 'passphrase', 'secret', 'token', 'credential',
		'apikey', 'api_key', 'api-key', 'privatekey', 'private_key', 'private-key',
		'signature', 'webhook',
	];

	/** A denied argument is replaced rather than left out, so that positions cannot shift. */
	public const string REDACTED = '{redacted}';

	/** An argument that was not given, or whose value had nothing left after sanitisation. */
	public const string NOTHING = '{none}';

	/**
	 * What a segment may be made of. Letters and digits are the identity of a job; `:` because
	 * that is how Symfony spells a command name and no normalisation rule touches it; `.` because
	 * the collector's `DefaultRules` match with `(?=[/.]|$)` precisely so that `/image/42.jpg`
	 * keeps its extension, and stripping dots would defeat that lookahead; `-` and `_` because
	 * command and argument names use both.
	 *
	 * Everything else goes, and four of the exclusions are load-bearing rather than tidy:
	 *
	 * - `?` is the one that matters most. The hook splits the URI on the first `?` and puts the
	 *   remainder in the line's `q` field - which the aggregator decodes and then reads nowhere at
	 *   all. A question mark in a value would therefore not be "a field the server ignores", it
	 *   would silently delete the rest of the name.
	 * - `/` and `\`, so that one argument is always exactly one segment. Otherwise a value could
	 *   add segments and {@see self::REDACTED} could be made to shift a position.
	 * - `{` and `}`, which are the aggregator's own output alphabet (`{id}`, `{uuid}`, `{hash}`).
	 *   Excluding them is what makes this class's own placeholders unforgeable by an argument.
	 * - `\x1f`, which is the separator the aggregator joins a bucket key with.
	 *
	 * Whitespace and newlines go because the log is JSON *lines*. Everything from `\x80` up goes
	 * so that the alphabet is ASCII, which has a quieter benefit: sanitise-then-truncate can never
	 * cut a multi-byte sequence in half, so one cap is a byte cap and a character cap at once and
	 * the hook's `substr($path, 0, 1024)` can never disagree with the server's
	 * `Assert\Length(max: 1024)`. The price is that a wholly non-ASCII argument reads as
	 * {@see self::NOTHING}.
	 *
	 * No `u` modifier, deliberately: with it `preg_replace()` returns null on an invalid UTF-8
	 * subject, which is exactly the input being defended against. Bytewise it simply does not
	 * match and gets replaced.
	 */
	private const string DISALLOWED = '/[^A-Za-z0-9._:-]+/';

	/** One run of disallowed bytes becomes one of these, rather than vanishing. */
	private const string JOIN = '-';

	/** Stripped from both ends, so `{id}` cannot arrive as `-id-` and `..` is nothing at all. */
	private const string TRIM = '-.';

	/**
	 * The longest a segment can be and still be a name rather than data. Symfony's own longest
	 * command name is around thirty characters, a migration version is fourteen, a messenger
	 * receiver is one word. Past sixty-four the thing being measured is a payload - a serialised
	 * filter, a URL, a file path - and what gets cut is exactly the part that differed per
	 * invocation, which is the part that must not become a bucket of its own.
	 */
	private const int MAX_SEGMENT_BYTES = 64;

	/**
	 * Most argument segments. No core Symfony command declares more than three positional
	 * arguments, and the array arguments that turn up in practice - `messenger:consume async
	 * failed` - are two or three. A ninth token is a bulk list, and a bulk list is data.
	 */
	private const int MAX_ARGUMENT_SEGMENTS = 8;

	/**
	 * The hook named this number itself: when a line will not fit in the 4096 bytes that keep an
	 * appending write atomic, its last resort is `$row['q'] = ''; $row['p'] = substr($row['p'], 0,
	 * 512);`. So 512 is the length past which the collector has already declared a path
	 * expendable - and a console path that routinely ran to a kilobyte would be the reason a worker
	 * lost its `e` block, which is where the SQL numbers live.
	 */
	private const int MAX_BYTES = 512;

	/**
	 * Both knobs are constructor arguments rather than configuration read in here, because this
	 * class is also the one place that can be tested without a container.
	 *
	 * @param list<string> $redacted the built-in list plus whatever the application added
	 */
	public function __construct(
		private bool $withArguments = true,
		private array $redacted = self::REDACTED_ARGUMENTS,
	) {
	}

	/**
	 * @param string              $script    `$argv[0]`, as typed - the directory and the slashes
	 *                                       are dropped here
	 * @param string              $command   the name Symfony resolved, not the token the user typed
	 * @param array<string,mixed> $arguments positional arguments in definition order, values as
	 *                                       the input holds them - defaults already merged in
	 */
	public function build(string $script, string $command, array $arguments): string {
		$segments = [
			$this->or(self::NOTHING, $this->segment($this->basename($script))),
			$this->or(self::NOTHING, $this->segment($command)),
		];

		if ($this->withArguments) {
			$segments = [...$segments, ...$this->arguments($arguments)];
		}

		return $this->join($segments);
	}

	/**
	 * The script's own name, reduced the way the hook reduces it: the same task is written
	 * `C:\app\execute.php` in one scheduled job and `c:/app/execute.php` in the next, and as paths
	 * those are two rows for one script - so the directory, the drive letter and the slashes go.
	 * PHP's `basename()` is not used because it does not split on a backslash outside Windows.
	 */
	private function basename(string $script): string {
		$script = strtr($script, '\\', '/');
		$slash  = strrpos($script, '/');

		return $slash === false ? $script : substr($script, $slash + 1);
	}

	/**
	 * One segment list for the whole argument map.
	 *
	 * Trailing absences are dropped, so a command with an unused optional argument keeps the plain
	 * `/console/app:import` it would have had without one. Interior absences are kept: an optional
	 * argument with no default *can* sit in front of one with a default, and collapsing that would
	 * put two different invocations on one path.
	 *
	 * @param  array<string,mixed> $arguments
	 * @return list<string>
	 */
	private function arguments(array $arguments): array {
		/** @var list<list<string>> $each one entry per argument, so absence can be popped as a unit */
		$each = [];
		foreach ($arguments as $name => $value) {
			$each[] = $this->argument((string) $name, $value);
		}

		while ($each !== [] && end($each) === [self::NOTHING]) {
			array_pop($each);
		}

		return array_slice(array_merge(...$each), 0, self::MAX_ARGUMENT_SEGMENTS);
	}

	/**
	 * One argument as the segments it contributes. Never empty: an argument that contributes
	 * nothing still has to hold its position, and the server rejects a blank path outright.
	 *
	 * @return non-empty-list<string>
	 */
	private function argument(string $name, mixed $value): array {
		$given = $value !== null && $value !== [];

		if ($this->isRedacted($name)) {
			// One placeholder for an array argument rather than one per element: Symfony's
			// InputDefinition guarantees an array argument is the last one, so no later position
			// can be shifted - and collapsing it is what stops the arity of a redacted list
			// leaking the very thing being hidden.
			return [$given ? self::REDACTED : self::NOTHING];
		}

		if (is_array($value)) {
			// An element per segment, not a folded count. The workload that decides this is
			// `messenger:consume async failed`, where the receiver names *are* the identity of the
			// worker; `{list}` would throw away the single most useful distinction here.
			$segments = array_map(
				fn(mixed $element): string => $this->or(self::NOTHING, $this->segment($this->scalar($element))),
				array_values($value),
			);

			return $segments === [] ? [self::NOTHING] : $segments;
		}

		return [$this->or(self::NOTHING, $this->segment($this->scalar($value)))];
	}

	/**
	 * A value as the text it stands for. An object is not rendered even when it is Stringable: its
	 * `__toString()` is the application's code, running inside a monitoring listener, on a path
	 * that then becomes a permanent identity.
	 */
	private function scalar(mixed $value): string {
		return match (true) {
			is_bool($value)                 => $value ? 'true' : 'false',
			is_string($value)               => $value,
			is_int($value), is_float($value) => (string) $value,
			default                         => '',
		};
	}

	/** @param list<string> $segments */
	private function join(array $segments): string {
		$path = '/' . implode('/', $segments);

		// Whole segments from the tail, never half of one: a cut segment is a new name for the
		// same job, which is the one thing a bucket key must not invent. Two segments of at most
		// MAX_SEGMENT_BYTES plus their slashes is 130 bytes, so the script and the command are
		// provably never reached.
		while (strlen($path) > self::MAX_BYTES && count($segments) > 2) {
			array_pop($segments);
			$path = '/' . implode('/', $segments);
		}

		return $path;
	}

	private function isRedacted(string $name): bool {
		$name = strtolower($name);

		foreach ($this->redacted as $needle) {
			if ($needle !== '' && str_contains($name, strtolower($needle))) {
				return true;
			}
		}

		return false;
	}

	private function segment(string $raw): string {
		$clean = trim((string) preg_replace(self::DISALLOWED, self::JOIN, $raw), self::TRIM);

		return strlen($clean) > self::MAX_SEGMENT_BYTES
			? rtrim(substr($clean, 0, self::MAX_SEGMENT_BYTES), self::TRIM)
			: $clean;
	}

	/** Sanitising can leave nothing behind, and nothing is not a segment. */
	private function or(string $fallback, string $segment): string {
		return $segment === '' ? $fallback : $segment;
	}
}
