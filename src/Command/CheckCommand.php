<?php

declare(strict_types=1);

namespace Guardrail\Command;

use Guardrail\Analysis\Analyzer;
use Guardrail\Analysis\AnalyzerProgress;
use Guardrail\Analysis\ProgressPhase;
use Guardrail\Config\GuardrailConfig;
use Guardrail\Reporter\ConsoleReporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
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
            )
            ->addOption(
                'memory-limit',
                'm',
                InputOption::VALUE_REQUIRED,
                'Memory limit for analysis (e.g., "1G", "512M", "-1" for unlimited)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Set memory limit if specified (intentional for CLI tool, like PHPStan)
        /** @var string|null $memoryLimit */
        $memoryLimit = $input->getOption('memory-limit');
        if ($memoryLimit !== null) {
            ini_set('memory_limit', $memoryLimit);
        }

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

        // Load full configuration (rules + scan settings)
        try {
            $config = GuardrailConfig::loadConfigFromFile($configPath);
            $rules = $config->rules;
            $scanConfig = $config->scanConfig;
        } catch (\Throwable $e) {
            $io->error(sprintf('Failed to load configuration: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        // Show scan configuration
        $io->text(sprintf(
            'Scanning: <info>%s</info>, excluding: <comment>%s</comment>',
            implode(', ', $scanConfig->paths),
            implode(', ', $scanConfig->excludes),
        ));

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

        // Run analysis with progress bar
        $analyzer = new Analyzer($basePath);
        $progressBar = null;
        $currentRuleName = '';

        $analyzer->onProgress(function (AnalyzerProgress $progress) use (
            $output,
            &$progressBar,
            &$currentRuleName,
        ): void {
            match ($progress->phase) {
                ProgressPhase::BuildingCallGraph => $this->showBuildingMessage($output),
                ProgressPhase::CallGraphBuilt => $this->clearBuildingMessage($output),
                ProgressPhase::AnalyzingRule => $this->updateProgressBar(
                    $output,
                    $progress,
                    $progressBar,
                    $currentRuleName,
                ),
            };
        });

        try {
            $results = $analyzer->analyze($rules, $scanConfig);
            if ($progressBar !== null) {
                $progressBar->finish();
                $output->writeln('');
            }
        } catch (\Throwable $e) {
            if ($progressBar !== null) {
                $progressBar->clear();
            }
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

    private function showBuildingMessage(OutputInterface $output): void
    {
        $output->write('<comment>Building call graph...</comment>');
    }

    private function clearBuildingMessage(OutputInterface $output): void
    {
        // Clear the building message and move cursor back
        $output->write("\r" . str_repeat(' ', times: 30) . "\r");
    }

    private function updateProgressBar(
        OutputInterface $output,
        AnalyzerProgress $progress,
        ?ProgressBar &$progressBar,
        string &$currentRuleName,
    ): void {
        if ($progress->ruleName !== $currentRuleName) {
            // New rule started
            if ($progressBar !== null) {
                $progressBar->finish();
                $output->writeln('');
            }

            $currentRuleName = $progress->ruleName ?? '';
            $progressBar = new ProgressBar($output, $progress->total);
            $progressBar->setFormat(" <info>%message%</info>\n %current%/%max% [%bar%] %percent:3s%%");
            $progressBar->setMessage(sprintf('Rule: %s', $currentRuleName));
            $progressBar->start();
        }

        if ($progressBar !== null) {
            $progressBar->setProgress($progress->current);
        }
    }
}
