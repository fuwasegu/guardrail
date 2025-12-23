<?php

declare(strict_types=1);

namespace Guardrail\Command;

use Guardrail\Analysis\Analyzer;
use Guardrail\Config\GuardrailConfig;
use Guardrail\Reporter\ConsoleReporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'check', description: 'Check codebase against configured rules')]
final class CheckCommand extends Command
{
    private const DEFAULT_CONFIG_FILES = [
        'guardrail.config.php',
        'guardrail.php',
    ];

    protected function configure(): void
    {
        $this
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to configuration file')
            ->addOption(
                'path',
                'p',
                InputOption::VALUE_REQUIRED,
                'Path to analyze (defaults to current directory)',
                getcwd(),
            )
            ->addOption(
                'rule',
                'r',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Only check specific rule(s)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Guardrail');

        /** @var string $basePath */
        $basePath = $input->getOption('path');

        // Find config file
        /** @var string|null $configPath */
        $configPath = $input->getOption('config');
        if ($configPath === null) {
            $configPath = $this->findConfigFile($basePath);
        }

        if ($configPath === null) {
            $io->error('No configuration file found. Create guardrail.config.php or specify with --config');
            return Command::FAILURE;
        }

        $io->text(sprintf('Loading configuration from <info>%s</info>', $configPath));

        // Load rules
        try {
            $rules = GuardrailConfig::loadFromFile($configPath);
        } catch (\Throwable $e) {
            $io->error(sprintf('Failed to load configuration: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        // Filter rules if specified
        /** @var list<string> $ruleFilter */
        $ruleFilter = $input->getOption('rule');
        if ($ruleFilter !== []) {
            $rules = array_values(array_filter($rules, static fn(\Guardrail\Config\Rule $rule) => in_array(
                $rule->name,
                $ruleFilter,
                strict: true,
            )));

            if ($rules === []) {
                $io->error(sprintf('No rules found matching: %s', implode(', ', $ruleFilter)));
                return Command::FAILURE;
            }
        }

        $io->text(sprintf('Analyzing with <info>%d rule(s)</info>...', count($rules)));
        $io->newLine();

        // Run analysis
        $analyzer = new Analyzer($basePath);

        try {
            $results = $analyzer->analyze($rules);
        } catch (\Throwable $e) {
            $io->error(sprintf('Analysis failed: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        // Report results
        $reporter = new ConsoleReporter($output, $output->isVerbose());
        return $reporter->report($results);
    }

    private function findConfigFile(string $basePath): ?string
    {
        foreach (self::DEFAULT_CONFIG_FILES as $filename) {
            $path = $basePath . DIRECTORY_SEPARATOR . $filename;
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }
}
