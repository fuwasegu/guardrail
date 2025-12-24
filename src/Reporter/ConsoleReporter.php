<?php

declare(strict_types=1);

namespace Guardrail\Reporter;

use Guardrail\Analysis\AnalysisResult;
use Guardrail\Analysis\PairedCallViolation;
use Guardrail\Analysis\RuleResult;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleReporter implements ReporterInterface
{
    public function __construct(
        private readonly OutputInterface $output,
        private readonly bool $verbose = false,
    ) {}

    /**
     * @param list<RuleResult> $results
     */
    public function report(array $results): int
    {
        $totalViolations = 0;
        $totalPairedViolations = 0;
        $totalPassed = 0;
        $totalEntryPoints = 0;

        foreach ($results as $ruleResult) {
            $this->reportRule($ruleResult);
            $totalViolations += $ruleResult->getViolationCount();
            $totalPairedViolations += count($ruleResult->pairedCallViolations);
            $totalPassed += $ruleResult->getPassedCount();
            $totalEntryPoints += $ruleResult->getTotalCount();
        }

        $this->output->writeln('');
        $this->reportSummary($results, $totalViolations, $totalPairedViolations, $totalPassed, $totalEntryPoints);

        return $totalViolations > 0 || $totalPairedViolations > 0 ? 1 : 0;
    }

    private function reportRule(RuleResult $result): void
    {
        $this->output->writeln('');
        $this->output->writeln(sprintf('<comment>Rule: %s</comment>', $result->rule->name));
        $this->output->writeln(str_repeat('━', times: 60));

        $violations = $result->getViolations();
        $pairedViolations = $result->pairedCallViolations;

        if ($violations === [] && $pairedViolations === []) {
            $passedCount = $result->getTotalCount();
            if ($passedCount === 0) {
                $this->output->writeln('<info>✓ No violations (paired call rules only)</info>');
                return;
            }

            $this->output->writeln(sprintf('<info>✓ All %d entry points passed</info>', $passedCount));
            return;
        }

        foreach ($violations as $violation) {
            $this->reportViolation($violation);
        }

        foreach ($pairedViolations as $pairedViolation) {
            $this->reportPairedViolation($pairedViolation);
        }

        if ($this->verbose) {
            $passed = $result->getPassed();
            foreach ($passed as $p) {
                $this->reportPassed($p);
            }
        }
    }

    private function reportViolation(AnalysisResult $result): void
    {
        $this->output->writeln('');
        $this->output->writeln(sprintf('<error>✗ %s</error>', $result->entryPoint->getIdentifier()));

        $this->output->writeln(sprintf('  <fg=gray>%s</>', $result->entryPoint->filePath));

        if ($result->message !== null) {
            $this->output->writeln(sprintf('  <fg=yellow>%s</>', $result->message));
        }

        $this->output->writeln(sprintf('  <fg=red>No call to %s found in call chain</>', $result->requiredCall));
    }

    private function reportPassed(AnalysisResult $result): void
    {
        $this->output->writeln(sprintf('<info>✓ %s</info>', $result->entryPoint->getIdentifier()));

        if ($result->callPath !== null && $result->callPath !== []) {
            $path = array_map(static fn($call) => $call->getCalleeIdentifier(), $result->callPath);
            $this->output->writeln(sprintf('  <fg=gray>via: %s</>', implode(' → ', $path)));
        }
    }

    private function reportPairedViolation(PairedCallViolation $violation): void
    {
        $this->output->writeln('');
        $this->output->writeln(sprintf('<error>✗ %s</error>', $violation->entryPoint->getIdentifier()));

        $this->output->writeln(sprintf('  <fg=gray>%s</>', $violation->entryPoint->filePath));
        $this->output->writeln(sprintf('  <fg=yellow>%s</>', $violation->getMessage()));

        // Show the path to the trigger call
        if ($violation->triggerPath !== []) {
            $path = array_map(static fn($call) => $call->getCalleeIdentifier(), $violation->triggerPath);
            $this->output->writeln(sprintf('  <fg=red>Trigger called via: %s</>', implode(' → ', $path)));
        }
    }

    /**
     * @param list<RuleResult> $results
     */
    private function reportSummary(
        array $results,
        int $totalViolations,
        int $totalPairedViolations,
        int $totalPassed,
        int $totalEntryPoints,
    ): void {
        $this->output->writeln(str_repeat('━', times: 60));
        $this->output->writeln('<comment>Summary</comment>');
        $this->output->writeln(str_repeat('━', times: 60));

        $rulesPassed = count(array_filter($results, static fn(RuleResult $r) => !$r->hasViolations()));
        $rulesFailed = count($results) - $rulesPassed;

        $this->output->writeln(sprintf(
            'Rules:        %d total, <info>%d passed</info>, %s',
            count($results),
            $rulesPassed,
            $rulesFailed > 0 ? "<error>{$rulesFailed} failed</error>" : '<info>0 failed</info>',
        ));

        if ($totalEntryPoints > 0) {
            $this->output->writeln(sprintf(
                'Entry points: %d total, <info>%d passed</info>, %s',
                $totalEntryPoints,
                $totalPassed,
                $totalViolations > 0 ? "<error>{$totalViolations} failed</error>" : '<info>0 failed</info>',
            ));
        }

        if ($totalPairedViolations > 0) {
            $this->output->writeln(sprintf('Paired calls: <error>%d violation(s)</error>', $totalPairedViolations));
        }

        $this->output->writeln('');

        $allViolations = $totalViolations + $totalPairedViolations;
        if ($allViolations > 0) {
            $this->output->writeln(sprintf('<error>✗ %d violation(s) found</error>', $allViolations));
            return;
        }

        $this->output->writeln('<info>✓ All checks passed</info>');
    }
}
