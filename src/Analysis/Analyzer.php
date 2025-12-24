<?php

declare(strict_types=1);

namespace Guardrail\Analysis;

use Guardrail\Analysis\CallGraph\CallGraph;
use Guardrail\Analysis\CallGraph\CallGraphBuilder;
use Guardrail\Collector\EntryPoint;
use Guardrail\Config\MethodReference;
use Guardrail\Config\PathCondition;
use Guardrail\Config\Rule;

/**
 * Main analyzer that checks rules against the codebase.
 */
final class Analyzer
{
    /** @var (callable(AnalyzerProgress): void)|null */
    private $progressCallback = null;

    public function __construct(
        private readonly string $basePath,
    ) {}

    /**
     * @param callable(AnalyzerProgress): void $callback
     */
    public function onProgress(callable $callback): self
    {
        $this->progressCallback = $callback;
        return $this;
    }

    /**
     * @param list<Rule> $rules
     * @return list<RuleResult>
     */
    public function analyze(array $rules): array
    {
        $this->reportProgress(AnalyzerProgress::buildingCallGraph());

        // Build call graph once for the analysis
        $callGraph = (new CallGraphBuilder())->build($this->basePath);

        $this->reportProgress(AnalyzerProgress::callGraphBuilt());

        $results = [];
        foreach ($rules as $rule) {
            $results[] = $this->analyzeRule($rule, $callGraph);
        }

        return $results;
    }

    private function reportProgress(AnalyzerProgress $progress): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)($progress);
        }
    }

    private function analyzeRule(Rule $rule, CallGraph $callGraph): RuleResult
    {
        // Use false for preserve_keys to avoid key collisions from yield from
        $entryPoints = iterator_to_array($rule->entryPointCollector->collect($this->basePath), preserve_keys: false);
        $total = count($entryPoints);
        $results = [];

        $this->reportProgress(AnalyzerProgress::analyzingRule($rule->name, 0, $total));

        foreach ($entryPoints as $index => $entryPoint) {
            $results[] = $this->analyzeEntryPoint($entryPoint, $rule, $callGraph);
            $this->reportProgress(AnalyzerProgress::analyzingRule($rule->name, $index + 1, $total));
        }

        return new RuleResult($rule, $results);
    }

    private function analyzeEntryPoint(EntryPoint $entryPoint, Rule $rule, CallGraph $callGraph): AnalysisResult
    {
        // For MVP, we check if ANY of the required calls is reachable
        foreach ($rule->requiredCalls as $requiredCall) {
            $result = $this->checkRequiredCall($entryPoint, $requiredCall, $rule->pathCondition, $callGraph);
            if ($result->found) {
                return $result;
            }
        }

        // None of the required calls were found
        return new AnalysisResult(
            entryPoint: $entryPoint,
            requiredCall: $rule->requiredCalls[0],
            found: false,
            message: $rule->getDisplayMessage(),
        );
    }

    /**
     * @param PathCondition $condition Currently unused - OnAllPaths requires CFG analysis (not yet implemented)
     */
    private function checkRequiredCall(
        EntryPoint $entryPoint,
        MethodReference $requiredCall,
        PathCondition $condition,
        CallGraph $callGraph,
    ): AnalysisResult {
        $path = $callGraph->findPathTo(
            $entryPoint->className,
            $entryPoint->methodName,
            $requiredCall->className,
            $requiredCall->methodName,
        );

        if ($path !== null) {
            return new AnalysisResult(
                entryPoint: $entryPoint,
                requiredCall: $requiredCall,
                found: true,
                callPath: $path,
            );
        }

        return new AnalysisResult(entryPoint: $entryPoint, requiredCall: $requiredCall, found: false);
    }
}
