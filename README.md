# Bug Catcher performance collector bundle

Symfony integration for
[`php-bug-catcher/perf-collector`](https://github.com/php-bug-catcher/perf-collector): the
aggregator as a console command configured once in `config/packages/`, and the Doctrine query
count and query time added to every performance sample.

```
auto_prepend_file hook ──▶ /dev/shm/bcperf.jsonl ──▶ bug-catcher:perf-aggregate ──HTTP──▶ Bug Catcher
     (one line/request)        (local only)              (cron, every minute)
```

The collector does not need this bundle. A non-Symfony application installs the hook and runs
`bc-perf-aggregate` from cron, and loses nothing but the SQL numbers. What the bundle adds is
saying the aggregator's eight command-line options once, in configuration, and an
`#[AsEventListener]` that puts `sq` (queries) and `st` (seconds in the database) into every
sample.

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

## Exit codes

| Code | Meaning |
|---|---|
| `0` | Done - including when another run already held the lock. |
| `1` | The batch did not reach the server. The cursor has not moved, so the next minute retries the same window; cron sends the mail. |
| `2` | The configuration is wrong - an unreachable state directory, a rules file that is not there. Retrying will not help. |

## License

MIT.
