<?php

declare(strict_types=1);

namespace Guardrail\Config;

use Guardrail\Collector\CollectorInterface;
use Guardrail\Collector\CompositeCollector;
use Guardrail\Collector\ExcludingCollector;
use Guardrail\Collector\NamespaceCollector;

/**
 * Fluent builder for configuring entry point collectors.
 */
final class EntryPointBuilder
{
    /** @var list<CollectorInterface> */
    private array $collectors = [];

    /** @var list<CollectorInterface> */
    private array $exclusions = [];

    private ?NamespaceCollector $currentNamespaceCollector = null;

    private bool $inExcluding = false;

    public function __construct(
        private readonly RuleBuilder $parent,
    ) {}

    public function namespace(string $pattern): self
    {
        $collector = (new NamespaceCollector())->namespace($pattern);

        if ($this->inExcluding) {
            $this->exclusions[] = $collector;
            return $this;
        }

        $this->collectors[] = $collector;
        $this->currentNamespaceCollector = $collector;

        return $this;
    }

    public function method(string ...$methods): self
    {
        if ($this->currentNamespaceCollector !== null && !$this->inExcluding) {
            // Replace the last collector with one that has method filter
            array_pop($this->collectors);
            $this->currentNamespaceCollector = $this->currentNamespaceCollector->method(...$methods);
            $this->collectors[] = $this->currentNamespaceCollector;
        }

        return $this;
    }

    public function publicMethods(): self
    {
        if ($this->currentNamespaceCollector !== null && !$this->inExcluding) {
            array_pop($this->collectors);
            $this->currentNamespaceCollector = $this->currentNamespaceCollector->publicMethods();
            $this->collectors[] = $this->currentNamespaceCollector;
        }

        return $this;
    }

    public function excluding(): self
    {
        $this->inExcluding = true;
        $this->currentNamespaceCollector = null;
        return $this;
    }

    public function or(): self
    {
        $this->inExcluding = false;
        $this->currentNamespaceCollector = null;
        return $this;
    }

    /**
     * End entry point configuration and return to the rule builder.
     */
    public function end(): RuleBuilder
    {
        return $this->parent;
    }

    public function build(): CollectorInterface
    {
        $baseCollector = match (count($this->collectors)) {
            0 => throw new \LogicException('At least one entry point collector must be specified'),
            1 => $this->collectors[0],
            default => CompositeCollector::or(...$this->collectors),
        };

        if ($this->exclusions === []) {
            return $baseCollector;
        }

        $exclusionCollector = match (count($this->exclusions)) {
            1 => $this->exclusions[0],
            default => CompositeCollector::or(...$this->exclusions),
        };

        return new ExcludingCollector($baseCollector, $exclusionCollector);
    }
}
