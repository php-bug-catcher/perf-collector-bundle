<?php

declare(strict_types=1);

use BugCatcher\PerfCollector\Cli\AggregateOptions;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;

/**
 * Every option `bc-perf-aggregate` takes on the command line, said once in configuration
 * instead - see the README for the cron entry that replaces.
 *
 * @link https://symfony.com/doc/current/bundles/best_practices.html#configuration
 */
return static function (DefinitionConfigurator $definition): void {
	$definition
		->rootNode()
			->children()
				// The log the auto_prepend_file hook writes, which is to say BCPERF_LOG. A
				// rotation pattern is written %%Y%%m%%d in YAML: the container resolves a single
				// per cent sign as a parameter reference. '%env(BCPERF_LOG)%' avoids the question
				// altogether and keeps the hook and the aggregator on one value.
				->scalarNode('log')->isRequired()->cannotBeEmpty()->end()
				// Base URL of the Bug Catcher server. The ingest path is the collector's business.
				->scalarNode('endpoint')->isRequired()->cannotBeEmpty()->end()
				// The project code the samples belong to, as it is spelled in Bug Catcher.
				->scalarNode('project')->isRequired()->cannotBeEmpty()->end()
				->scalarNode('token')->defaultNull()->end()
				// Read cursors and, by default, the lock file. Deliberately NOT under
				// %kernel.cache_dir%: cache:clear is a deploy step, and losing the cursor is how a
				// window gets counted twice.
				->scalarNode('state_dir')->defaultValue('%kernel.project_dir%/var/bcperf')->cannotBeEmpty()->end()
				// Defaults to aggregate.lock inside state_dir.
				->scalarNode('lock_file')->defaultNull()->cannotBeEmpty()->end()
				// A JSON list of extra path normalisation rules, applied before the shipped ones.
				->scalarNode('rules_file')->defaultNull()->cannotBeEmpty()->end()
				// false means rules_file is the whole list rather than an addition to it.
				->booleanNode('default_rules')->defaultTrue()->end()
				// Most bytes read in one run. A backlog from a server outage drains over several
				// runs rather than becoming one request that can never succeed.
				->integerNode('max_bytes')->defaultValue(AggregateOptions::DEFAULT_MAX_BYTES)->min(1)->end()
				// Count Doctrine queries and their time into every sample. Needs doctrine/dbal;
				// without it there is nothing to time and nothing is registered.
				->booleanNode('sql_metrics')->defaultTrue()->end()
				// Name a console run after the command Symfony resolved and its positional
				// arguments, instead of leaving it to the hook's argv guess - which reads the
				// first non-option token, so `bin/console --env prod app:sync` is recorded as
				// `/console/prod` and an alias is recorded as itself.
				->booleanNode('console_path')->defaultTrue()->end()
				// false keeps the command name and drops every argument value. For an application
				// whose arguments are an e-mail address or an order reference, that is the
				// difference between a path per job and a path per invocation.
				->booleanNode('console_path_arguments')->defaultTrue()->end()
				// Argument names whose value is replaced by {redacted}. Added to the built-in
				// list, never replacing it: a path cannot be scrubbed afterwards, so the only safe
				// direction for this list to move in is longer.
				->arrayNode('console_path_redact')
					->beforeNormalization()->ifString()->then(static fn (string $one): array => [$one])->end()
					// An empty needle is a substring of every name, so it would redact the whole
					// application while reading like a harmless blank line in YAML.
					->scalarPrototype()->cannotBeEmpty()->end()
				->end()
			->end()
	;
};
