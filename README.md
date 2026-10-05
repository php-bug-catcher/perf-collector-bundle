# Bug Catcher performance collector bundle

Symfony integration for
[`php-bug-catcher/perf-collector`](https://github.com/php-bug-catcher/perf-collector): the
aggregator as a console command configured once in `config/packages/`, the Doctrine query count
and query time added to every performance sample, and a console run named after the command it
ran.

```
auto_prepend_file hook ──▶ /dev/shm/bcperf.jsonl ──▶ bug-catcher:perf-aggregate ──HTTP──▶ Bug Catcher
     (one line/request)        (local only)              (cron, every minute)
```

The collector does not need this bundle. A non-Symfony application installs the hook and runs
`bc-perf-aggregate` from cron, and loses nothing but the SQL numbers and the command names. What
the bundle adds is saying the aggregator's eight command-line options once, in configuration; an
`#[AsEventListener]` that puts `sq` (queries) and `st` (seconds in the database) into every
sample; and another that names a console run after the command Symfony resolved rather than after
`bin/console`.

## Install

```
composer require php-bug-catcher/perf-collector-bundle
```

```php
// config/bundles.php
return [
    // ...
    BugCatcher\PerfCollectorBundle\PerfCollectorBundle::class => ['all' => true],
];
```

### 1. The hook, in php.ini

The hook is a plain PHP file that runs before your autoloader; there is nothing to register in
Symfony. Point `auto_prepend_file` at it and tell it where to write:

```ini
; /etc/php.d/90-bcperf.ini  (or your pool's php.ini)
auto_prepend_file = /srv/app/vendor/php-bug-catcher/perf-collector/hook/collector.php
bcperf.log = /dev/shm/bcperf.jsonl
```

**Put the log on tmpfs.** The append is the only part of the hook whose cost is not under its
control: the collector measured the same write at ~2 µs on tmpfs and ~350 µs on a journalling
filesystem - three orders of magnitude, and more than everything else the hook does put
together. `/dev/shm` is the right default; the aggregator empties the file every minute, so
nothing in it is meant to survive a reboot.

`BCPERF_LOG`, `BCPERF_SAMPLE_RATE` and `BCPERF_CLI` do the same job as environment variables, and
win over `php.ini` when both are set. `php-bug-catcher/perf-collector`'s README documents them.

### 2. The configuration

```yaml
# config/packages/bug_catcher_perf_collector.yaml
bug_catcher_perf_collector:
    log: '%env(BCPERF_LOG)%'
    endpoint: 'https://bugcatcher.example.com'
    project: 'myapp'
```

Reading `BCPERF_LOG` here is worth doing on purpose: the hook and the aggregator then cannot
disagree about which file they are talking about, and a rotation pattern inside an environment
variable needs no escaping. Writing the path out in YAML does - see the note under `log`.

### 3. The cron entry

On every monitored machine, every minute:

```cron
* * * * *  php /srv/app/bin/console bug-catcher:perf-aggregate
```

`bc-perf-aggregate --log=… --endpoint=… --project=…` from the collector package does exactly the
same thing without reading your configuration; use whichever suits the deployment. The exit codes
are identical.

Check the setup once before the cron entry goes in - this reads and reports, ships nothing and
moves no cursor:

```
php bin/console bug-catcher:perf-aggregate --dry-run
```

The server side has cron entries of its own (roll-up, retention, regression detection); they are
documented with the Bug Catcher server, not here.

## Configuration

| Key | Default | Meaning |
|---|---|---|
| `log` | **required** | The log the hook writes, which is to say `BCPERF_LOG`. A rotation pattern is spelled `%%Y%%m%%d` in YAML - the container reads a single per cent sign as a parameter reference and will refuse to start. `'%env(BCPERF_LOG)%'` sidesteps the question. |
| `endpoint` | **required** | Base URL of the Bug Catcher server. The ingest path is the collector's business. |
| `project` | **required** | The project code the samples belong to, as Bug Catcher spells it. |
| `token` | `null` | Sent as a bearer credential. |
| `state_dir` | `%kernel.project_dir%/var/bcperf` | Read cursors and, by default, the lock file. Not under `%kernel.cache_dir%`: `cache:clear` is a deploy step, and a lost cursor is how a window gets counted twice. |
| `lock_file` | `aggregate.lock` inside `state_dir` | Two runs never read the same log at once. |
| `rules_file` | `null` | A JSON list of extra path normalisation rules, applied before the shipped ones. |
| `default_rules` | `true` | `false` makes `rules_file` the whole list instead of an addition to it. |
| `max_bytes` | `16777216` | Most bytes read in one run. A backlog from a server outage drains over several runs rather than becoming one request that can never succeed. |
| `sql_metrics` | `true` | Count Doctrine queries and their time into every sample. Needs `doctrine/dbal`; without it nothing is registered. |
| `console_path` | `true` | Name a console run after the command Symfony resolved and its positional arguments. Without it the hook reads the first non-option token of `argv`, so `bin/console --env prod app:sync` is recorded as `/console/prod` and an alias is recorded as itself. |
| `console_path_arguments` | `true` | `false` keeps the command name and drops the argument values. For an application whose arguments are an e-mail address or an order reference, that is the difference between a path per job and a path per invocation. |
| `console_path_redact` | `[]` | Argument names whose value is replaced by `{redacted}`. Added to the built-in list, never replacing it. |

## SQL metrics

With `doctrine/dbal` installed, a DBAL middleware times every query and an `#[AsEventListener]`
on `kernel.terminate` and `console.terminate` writes two numbers into
`$GLOBALS['_bcperf_extra']`:

| Key | Type | Meaning |
|---|---|---|
| `sq` | `int` | Queries this request ran, failed ones included - they cost the database the same work. |
| `st` | `float` | Seconds spent in the database. Seconds, like every other duration the collector ships. |

The hook reads that global from its shutdown handler, which runs after `kernel.terminate` and
after `fastcgi_finish_request()` - the response has already gone to the client, so none of this
is on the visitor's clock. The two numbers are summed per bucket by the aggregator and stored by
the server beside the request counts, so a route's queries per hit are one division away.

Nothing about this is Doctrine-specific. Any numeric value an application assigns to
`$GLOBALS['_bcperf_extra']` before the process ends travels the same way - a cache hit rate, a
template count, time spent in an upstream API:

```php
$GLOBALS['_bcperf_extra']['api_ms'] = $elapsedMilliseconds;
```

Names may be up to 32 characters of letters, digits, underscore, dot and dash, and values must
be `int` or `float`; the hook drops anything else.

## What a console run is called

A request is named by its URI. A command has none, so it is named after the command Symfony
resolved and the positional arguments it was given:

```
php bin/console app:import customers --full -vv
  ->  /console/app:import/customers
```

**Options are dropped, positional arguments are kept.** `--full` says how a job was asked to run;
`customers` says which job it was. Keeping the arguments is what tells `app:import customers` apart
from `app:import orders` - two different amounts of work that would otherwise share one row - and
dropping the options is what stops that becoming a path per invocation.

The hook can name a command-line run on its own, and this is strictly better at it, because a
kernel knows three things `argv` does not:

| | the hook, from `argv` | this bundle, from Symfony |
|---|---|---|
| `bin/console --env prod app:sync` | `/console/prod` - `prod` is the first non-option token | `/console/app:sync` |
| `bin/console app:imp` (an abbreviation) | `/console/app:imp` | `/console/app:import` |
| `bin/console app:legacy-import` (an alias) | `/console/app:legacy-import` | `/console/app:import` |
| `app:user:create bob hunter2` | one argument only, and no idea which | `/console/app:user:create/bob/{redacted}` |

`$_SERVER['REQUEST_URI']` is the channel, which is the hook's own idea - it has preferred that
variable over its own guess since 2.0, so **nothing on the monitored machine has to change**: no
new hook, no `php.ini` edit. By the same rule, a worker that sets `REQUEST_URI` itself is believed
over its command line, because it is saying what it stands in for.

Nothing is published unless the hook is switched on for the process, which for a command line
means `bcperf.cli = 1` (or `BCPERF_CLI=1`) as well as a log path. An application whose
`auto_prepend_file` lives in the php-fpm pool has no hook on the command line at all, and measures
no commands - with or without this bundle.

### What reaches the path, exactly

- **Defaults count.** `app:migrate` and `app:migrate latest` are one path when `latest` is the
  default, because they are one piece of work.
- **An array argument spreads**, one segment per element: `messenger:consume async failed` is
  `/console/messenger:consume/async/failed`. For a worker the receiver names *are* its identity.
- **An argument that was not given reads `{none}`**, unless it is at the end, where it is simply
  dropped - so an unused optional argument leaves `/console/app:import` as it was.
- **A sensitive name reads `{redacted}`**, in place rather than left out, so that nothing after it
  shifts position. The built-in list is matched case-insensitively as a substring of the
  argument's *definition* name, so `--db-password` and `userPassword` are both caught:

  `password`, `passwd`, `passphrase`, `secret`, `token`, `credential`, `apikey`, `api_key`,
  `api-key`, `privatekey`, `private_key`, `private-key`, `signature`, `webhook`

  Short, ambiguous words are missing on purpose: a bare `key` would redact `keyword`, `auth` would
  redact `author`, `pin` would redact `pinned`. Add those with `console_path_redact`, where the
  application can see the decision it made.
- **`{id}`, `{uuid}` and `{hash}` are not this bundle's work.** A numeric argument reaches the log
  as itself and is collapsed by the aggregator's normalisation rules, the same ones that turn
  `/user/4711` into `/user/{id}`.

Think of the path as a name, not as data, because that is what it becomes: it is the identity of a
bucket on the server, it lives two years in the day aggregates and for ever in a regression
record, and it is read back out by notifier e-mails and over MCP. There is no scrubbing it
afterwards. That is the reason for the denylist, and the reason `console_path_arguments: false`
exists for an application whose arguments are nobody's business.

Three caps keep a name a name. A segment is cut at 64 bytes; at most eight argument segments are
kept; and the whole path is held under 512 bytes by dropping whole segments off the end, never by
cutting one - the script and the command are never reached. Characters outside
`A-Za-z0-9._:-` become `-`, which means a wholly non-ASCII argument reads as `{none}`: an
ASCII-only alphabet is what makes one cap a byte cap and a character cap at once, so the hook's
truncation and the server's length check can never disagree.

**One side effect, honestly.** `$_SERVER['REQUEST_URI']` is what several libraries read as "this is
a web request" - Monolog's `WebProcessor` attaches a `url` to a record only when it is set. In a
recorded console process your CLI log records may therefore gain a `url` that looks like a route.
That is why `console_path: false` exists as more than a cardinality knob.

**Upgrading.** A plainly invoked `bin/console app:sync` keeps the name it already had. The
invocations that get renamed are the ones that were wrong - `--env prod`, aliases, abbreviations -
and any command with arguments. A renamed path is a new bucket: the old row stops growing, and the
server needs a week of history before it can call a regression on the new one again.

## Exit codes

| Code | Meaning |
|---|---|
| `0` | Done - including when another run already held the lock. |
| `1` | The batch did not reach the server. The cursor has not moved, so the next minute retries the same window; cron sends the mail. |
| `2` | The configuration is wrong - an unreachable state directory, a rules file that is not there. Retrying will not help. |

## License

MIT.
