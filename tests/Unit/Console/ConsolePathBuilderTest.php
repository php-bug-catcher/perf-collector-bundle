<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollectorBundle\Tests\Unit\Console;

use BugCatcher\PerfCollector\Normalize\PathNormalizer;
use BugCatcher\PerfCollectorBundle\Console\ConsolePathBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The whole naming policy, with no event, no kernel and no container - which is the reason the
 * policy is a class of its own.
 *
 * Two things here are not really about this class and are tested anyway: that the aggregator's
 * normaliser collapses the variable part of a console path, and that it leaves the placeholders
 * alone. The division of labour between the two packages is what the design rests on, and it is
 * duplicated by hand across a repository boundary.
 */
final class ConsolePathBuilderTest extends TestCase {

	public function testACommandIsNamedAfterItsScriptAndItsJob(): void {
		self::assertSame(
			'/console/app:import/customers',
			$this->build('/srv/app/bin/console', 'app:import', ['target' => 'customers']),
		);
	}

	public function testTheInvocationPathDoesNotChangeWhatTheCommandIsCalled(): void {
		$paths = [
			'bin/console',
			'/var/www/cron/current/bin/console',
			'C:\\inetpub\\wwwroot\\bin\\console',
			'c:/inetpub/wwwroot/bin/console',
		];

		foreach ($paths as $script) {
			self::assertSame('/console/cache:clear', $this->build($script, 'cache:clear'), $script);
		}
	}

	/** The extension stays: `execute.php` and `execute.phar` are two scripts. */
	public function testTheScriptKeepsItsExtension(): void {
		self::assertSame('/execute.php/sync', $this->build('C:/app/execute.php', 'sync'));
	}

	/** A blank path is a 422 at the server's edge, and a 422 is a batch that never clears. */
	public function testAScriptWithNoUsableNameIsStillAPath(): void {
		self::assertSame('/{none}/app:sync', $this->build('', 'app:sync'));
	}

	public function testACommandWithNoArgumentsIsJustTheCommand(): void {
		self::assertSame('/console/cache:clear', $this->build('bin/console', 'cache:clear'));
	}

	/**
	 * `app:migrate` and `app:migrate latest` do the same work, so they are one bucket. The input
	 * merges defaults in for us, which is why this is free rather than a special case.
	 */
	public function testADefaultedArgumentCountsLikeAGivenOne(): void {
		self::assertSame(
			'/console/app:migrate/latest',
			$this->build('bin/console', 'app:migrate', ['version' => 'latest']),
		);
	}

	public function testAnUnusedTrailingArgumentIsDropped(): void {
		self::assertSame(
			'/console/app:import',
			$this->build('bin/console', 'app:import', ['file' => null]),
		);
	}

	public function testEveryUnusedTrailingArgumentIsDropped(): void {
		self::assertSame(
			'/console/app:import/customers',
			$this->build('bin/console', 'app:import', ['target' => 'customers', 'file' => null, 'extra' => null]),
		);
	}

	/**
	 * An optional argument with no default can sit in front of one with a default, so absence in
	 * the middle has to hold its place - otherwise two different invocations share a path.
	 */
	public function testAnUnusedArgumentInTheMiddleHoldsItsPosition(): void {
		self::assertSame(
			'/console/app:thing/{none}/y',
			$this->build('bin/console', 'app:thing', ['first' => null, 'second' => 'y']),
		);
	}

	/**
	 * The receiver names *are* the identity of a worker, so they get a segment each. This is the
	 * workload that decides array arguments are spread rather than folded.
	 */
	public function testAnArrayArgumentSpreadsOverSegments(): void {
		self::assertSame(
			'/console/messenger:consume/async/failed',
			$this->build('bin/console', 'messenger:consume', ['receivers' => ['async', 'failed']]),
		);
	}

	public function testAnEmptyArrayArgumentIsAnAbsentOne(): void {
		self::assertSame(
			'/console/messenger:consume',
			$this->build('bin/console', 'messenger:consume', ['receivers' => []]),
		);
	}

	/**
	 * A trailing argument comes after the one under test throughout: a value that reduces to
	 * {@see ConsolePathBuilder::NOTHING} is dropped when it is last - which is the point of
	 * dropping it - and this is the shape where the placeholder is observable.
	 */
	#[DataProvider('scalarValues')]
	public function testAValueIsRenderedAsTheTextItStandsFor(mixed $value, string $expected): void {
		self::assertSame(
			'/console/app:thing/' . $expected . '/x',
			$this->build('bin/console', 'app:thing', ['value' => $value, 'mode' => 'x']),
		);
	}

	/**
	 * Numbers are not collapsed here. `/42` becoming `/{id}` is the aggregator's rule, applied on
	 * the monitored machine after the hook has written the line - see
	 * {@see self::testTheAggregatorCollapsesTheVariablePartOfAConsolePath()}.
	 *
	 * @return iterable<string,array{mixed,string}>
	 */
	public static function scalarValues(): iterable {
		yield 'a string' => ['customers', 'customers'];
		yield 'an int' => [42, '42'];
		yield 'a float' => [1.5, '1.5'];
		yield 'true' => [true, 'true'];
		yield 'false' => [false, 'false'];
		// `__toString()` is the application's code, and this runs inside a monitoring listener on
		// a value that becomes a permanent identity.
		yield 'an object' => [new stdClass(), ConsolePathBuilder::NOTHING];
	}

	#[DataProvider('redactedNames')]
	public function testASensitiveArgumentNameHidesItsValue(string $name): void {
		self::assertSame(
			'/console/app:thing/{redacted}',
			$this->build('bin/console', 'app:thing', [$name => 'hunter2']),
			$name,
		);
	}

	/** @return iterable<string,array{string}> */
	public static function redactedNames(): iterable {
		yield 'the name itself' => ['password'];
		yield 'upper case' => ['DB_PASSWORD'];
		yield 'camel case' => ['userPassword'];
		yield 'a prefix' => ['password-file'];
		yield 'an underscored api key' => ['api_key'];
		yield 'a dashed api key' => ['api-key'];
		yield 'a run-on api key' => ['apikey'];
		yield 'a token' => ['refreshToken'];
		yield 'a secret' => ['client_secret'];
	}

	/**
	 * `author` is not `auth` and `keyword` is not `key`: a monitoring path that silently lost one
	 * is worse than a list the operator had to extend.
	 */
	#[DataProvider('innocentNames')]
	public function testAnInnocentNameKeepsItsValue(string $name): void {
		self::assertSame(
			'/console/app:thing/sheridan',
			$this->build('bin/console', 'app:thing', [$name => 'sheridan']),
			$name,
		);
	}

	/** @return iterable<string,array{string}> */
	public static function innocentNames(): iterable {
		yield 'author' => ['author'];
		yield 'keyword' => ['keyword'];
		yield 'pinned' => ['pinned'];
		yield 'hash' => ['hash'];
		yield 'authority' => ['authority'];
	}

	/** Replaced, not removed: removing it would move `full` into the position of a password. */
	public function testRedactionDoesNotShiftTheArgumentsAfterIt(): void {
		self::assertSame(
			'/console/app:user:create/bob/{redacted}/full',
			$this->build('bin/console', 'app:user:create', [
				'username' => 'bob',
				'password' => 'hunter2',
				'mode'     => 'full',
			]),
		);
	}

	public function testASensitiveArgumentThatWasNotGivenReadsAsAbsentRatherThanHidden(): void {
		self::assertSame(
			'/console/app:thing/{none}/x',
			$this->build('bin/console', 'app:thing', ['password' => null, 'mode' => 'x']),
		);
	}

	/**
	 * One placeholder, not one per element. Symfony puts an array argument last, so no position
	 * can shift - and collapsing it is what stops the length of a hidden list leaking.
	 */
	public function testARedactedArrayArgumentDoesNotLeakHowManyThereWere(): void {
		self::assertSame(
			'/console/app:thing/{redacted}',
			$this->build('bin/console', 'app:thing', ['tokens' => ['a', 'b', 'c']]),
		);
	}

	public function testAnApplicationCanAddToTheDenylistWithoutLosingTheBuiltInOnes(): void {
		$builder = new ConsolePathBuilder(
			redacted: [...ConsolePathBuilder::REDACTED_ARGUMENTS, 'ssn'],
		);

		self::assertSame(
			'/console/app:thing/{redacted}/{redacted}',
			$builder->build('bin/console', 'app:thing', ['ssn' => '123-45-6789', 'password' => 'hunter2']),
		);
	}

	public function testArgumentsCanBeTurnedOffAltogether(): void {
		$builder = new ConsolePathBuilder(withArguments: false);

		self::assertSame(
			'/console/app:import',
			$builder->build('bin/console', 'app:import', ['target' => 'customers', 'mode' => 'full']),
		);
	}

	/** A trailing argument again, so that a value reduced to nothing is still visible as such. */
	#[DataProvider('hostileValues')]
	public function testAValueCannotBreakOutOfItsSegment(string $value, string $expected): void {
		self::assertSame(
			'/console/app:thing/' . $expected . '/x',
			$this->build('bin/console', 'app:thing', ['value' => $value, 'mode' => 'x']),
		);
	}

	/** @return iterable<string,array{string,string}> */
	public static function hostileValues(): iterable {
		// The hook splits the URI on the first `?` and files the rest under a field nothing reads,
		// so a question mark would silently delete the rest of the name.
		yield 'a query string' => ['customers?page=2', 'customers-page-2'];
		yield 'a bare question mark' => ['?a=1', 'a-1'];
		// One argument is always exactly one segment, or {redacted} could be made to shift.
		yield 'a slash' => ['a/b', 'a-b'];
		yield 'a backslash' => ['a\\b', 'a-b'];
		yield 'a leading slash' => ['/etc/passwd', 'etc-passwd'];
		yield 'a space' => ['two words', 'two-words'];
		yield 'a newline' => ["a\nb", 'a-b'];
		yield 'a tab' => ["a\tb", 'a-b'];
		// The separator the aggregator joins a bucket key with.
		yield 'the bucket key separator' => ["a\x1fb", 'a-b'];
		// The aggregator's own output alphabet, which is what makes the placeholders unforgeable.
		yield 'a forged id' => ['{id}', 'id'];
		yield 'a forged redaction' => ['{redacted}', 'redacted'];
		yield 'traversal' => ['..', ConsolePathBuilder::NOTHING];
		yield 'traversal with a slash' => ['../..', ConsolePathBuilder::NOTHING];
		yield 'invalid utf-8' => ["\xc3\x28\xa0", ConsolePathBuilder::NOTHING];
		yield 'cyrillic' => ['синхронизация', ConsolePathBuilder::NOTHING];
		yield 'an emoji' => ['🙂', ConsolePathBuilder::NOTHING];
		yield 'nothing at all' => ['', ConsolePathBuilder::NOTHING];
	}

	/** A command name is only validated against empty colon-separated parts, so it is not safe either. */
	public function testACommandNameGoesThroughTheSameSanitiserAsAValue(): void {
		self::assertSame('/console/app:do-something', $this->build('bin/console', 'app:do something'));
	}

	public function testALongValueIsCutToASegmentRatherThanAPayload(): void {
		$path = $this->build('bin/console', 'app:thing', ['filter' => str_repeat('a', 300)]);

		self::assertSame('/console/app:thing/' . str_repeat('a', 64), $path);
	}

	/** A cut must not leave the joining character dangling, or two cuts read as two jobs. */
	public function testACutSegmentDoesNotEndInTheJoiningCharacter(): void {
		$path = $this->build('bin/console', 'app:thing', ['filter' => str_repeat('a', 64) . ' tail']);

		self::assertSame('/console/app:thing/' . str_repeat('a', 64), $path);
	}

	public function testTheNumberOfArgumentSegmentsIsCapped(): void {
		$path = $this->build('bin/console', 'app:thing', ['items' => range(1, 11)]);

		self::assertSame('/console/app:thing/1/2/3/4/5/6/7/8', $path);
	}

	/**
	 * The cap takes whole segments off the tail, so the script and the command - at most 130 bytes
	 * between them - are provably never reached.
	 */
	public function testTheWholePathIsCappedByDroppingSegmentsNotByCuttingOne(): void {
		$long = [];
		foreach (range(1, 8) as $i) {
			$long['arg' . $i] = str_repeat((string) chr(96 + $i), 64);
		}

		$path     = $this->build('bin/console', 'app:thing', $long);
		$segments = explode('/', ltrim($path, '/'));

		self::assertLessThanOrEqual(512, strlen($path));
		self::assertSame(['console', 'app:thing'], array_slice($segments, 0, 2));
		foreach (array_slice($segments, 2) as $segment) {
			self::assertSame(64, strlen($segment), 'a dropped tail, never a cut segment');
		}
	}

	public function testEvenTheWorstScriptAndCommandSurviveTheWholePathCap(): void {
		$path = $this->build(str_repeat('s', 300), str_repeat('c', 300));

		self::assertSame('/' . str_repeat('s', 64) . '/' . str_repeat('c', 64), $path);
	}

	/**
	 * The invariants every caller downstream relies on, swept over every case above: the hook
	 * writes the path into a JSON line, the aggregator makes it part of a bucket key and the
	 * server stores it as a non-blank string of at most a kilobyte.
	 */
	#[DataProvider('everyShape')]
	public function testThePathIsAlwaysSomethingTheWireCanCarry(string $command, array $arguments): void {
		$path = $this->build('/srv/app/bin/console', $command, $arguments);

		self::assertNotSame('', $path);
		self::assertStringStartsWith('/', $path);
		self::assertStringNotContainsString('//', $path);
		self::assertStringNotContainsString('?', $path);
		self::assertStringNotContainsString("\x1f", $path);
		self::assertDoesNotMatchRegularExpression('/\s/', $path);
		self::assertSame($path, rtrim($path, '/'));
		self::assertLessThanOrEqual(512, strlen($path));
		self::assertSame(strlen($path), mb_strlen($path), 'ASCII only, so bytes and characters agree');
	}

	/** @return iterable<string,array{string,array<string,mixed>}> */
	public static function everyShape(): iterable {
		yield 'nothing' => ['app:thing', []];
		yield 'a command that is not safe' => ['app:do something?x', []];
		foreach (self::hostileValues() as $name => [$value]) {
			yield 'a value that is ' . $name => ['app:thing', ['value' => $value]];
		}
		foreach (self::scalarValues() as $name => [$value]) {
			yield 'a value that is ' . $name => ['app:thing', ['value' => $value]];
		}
		yield 'a hostile array' => ['app:thing', ['items' => ['a/b', '', '..', "\x1f"]]];
		yield 'everything at once' => ['app:thing', [
			'target'   => 'customers?page=2',
			'password' => 'hunter2',
			'items'    => range(1, 20),
		]];
	}

	/**
	 * The variable part of a console path is the aggregator's job, not this class's - which is the
	 * reason numeric arguments are not special-cased here.
	 */
	public function testTheAggregatorCollapsesTheVariablePartOfAConsolePath(): void {
		$normalizer = PathNormalizer::withDefaults();

		self::assertSame(
			'/console/app:user:delete/{id}',
			$normalizer->normalize($this->build('bin/console', 'app:user:delete', ['id' => 4711])),
		);
		self::assertSame(
			'/console/app:cache:drop/{hash}',
			$normalizer->normalize($this->build('bin/console', 'app:cache:drop', ['key' => 'a1b2c3d4e5f60718'])),
		);
	}

	/** And it has to leave the placeholders alone, or `{none}` becomes `{id}`-shaped noise. */
	public function testTheAggregatorLeavesThePlaceholdersAlone(): void {
		$path = $this->build('bin/console', 'app:thing', ['password' => 'hunter2', 'missing' => null, 'mode' => 'x']);

		self::assertSame($path, PathNormalizer::withDefaults()->normalize($path));
	}

	/** @param array<string,mixed> $arguments */
	private function build(string $script, string $command, array $arguments = []): string {
		return (new ConsolePathBuilder())->build($script, $command, $arguments);
	}
}
