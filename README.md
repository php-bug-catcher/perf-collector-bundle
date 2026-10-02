# Bug Catcher performance collector bundle

Symfony integration for
[`php-bug-catcher/perf-collector`](https://github.com/php-bug-catcher/perf-collector): the
aggregator as a console command configured once in `config/packages/`, and the Doctrine query
count and time added to every performance sample.

The collector itself does not need this bundle. A non-Symfony application installs the hook and
runs `bc-perf-aggregate` from cron and loses nothing but the SQL numbers.

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

```yaml
# config/packages/bug_catcher_perf_collector.yaml
bug_catcher_perf_collector:
    log: '%env(BCPERF_LOG)%'
    endpoint: 'https://bugcatcher.example.com'
    project: 'myapp'
```

## License

MIT.
