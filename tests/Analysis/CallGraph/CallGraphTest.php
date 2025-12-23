<?php

declare(strict_types=1);

namespace Guardrail\Tests\Analysis\CallGraph;

use Guardrail\Analysis\CallGraph\CallGraph;
use Guardrail\Analysis\CallGraph\MethodCall;
use PHPUnit\Framework\TestCase;

final class CallGraphTest extends TestCase
{
    private CallGraph $graph;

    protected function setUp(): void
    {
        $this->graph = new CallGraph();
    }

    public function testAddAndGetCallsFrom(): void
    {
        $call = new MethodCall(
            callerClass: 'App\\Controller',
            callerMethod: 'index',
            calleeClass: 'App\\Service',
            calleeMethod: 'execute',
            line: 10,
        );

        $this->graph->addCall($call);

        $calls = $this->graph->getCallsFrom('App\\Controller', 'index');
        $this->assertCount(1, $calls);
        $this->assertSame('App\\Service', $calls[0]->calleeClass);
        $this->assertSame('execute', $calls[0]->calleeMethod);
    }

    public function testGetCallsFromReturnsEmptyForUnknown(): void
    {
        $this->assertSame([], $this->graph->getCallsFrom('App\\Unknown', 'method'));
    }

    public function testAddAndGetCallsTo(): void
    {
        $call = new MethodCall(
            callerClass: 'App\\Controller',
            callerMethod: 'index',
            calleeClass: 'App\\Service',
            calleeMethod: 'execute',
            line: 10,
        );

        $this->graph->addCall($call);

        $calls = $this->graph->getCallsTo('App\\Service', 'execute');
        $this->assertCount(1, $calls);
        $this->assertSame('App\\Controller', $calls[0]->callerClass);
    }

    public function testHasPathToDirectCall(): void
    {
        $call = new MethodCall(
            callerClass: 'App\\Controller',
            callerMethod: 'index',
            calleeClass: 'App\\Service',
            calleeMethod: 'execute',
            line: 10,
        );

        $this->graph->addCall($call);

        $this->assertTrue($this->graph->hasPathTo('App\\Controller', 'index', 'App\\Service', 'execute'));
    }

    public function testHasPathToIndirectCall(): void
    {
        // Controller -> Service -> Repository
        $this->graph->addCall(new MethodCall(
            callerClass: 'App\\Controller',
            callerMethod: 'index',
            calleeClass: 'App\\Service',
            calleeMethod: 'execute',
            line: 10,
        ));
        $this->graph->addCall(new MethodCall(
            callerClass: 'App\\Service',
            callerMethod: 'execute',
            calleeClass: 'App\\Repository',
            calleeMethod: 'find',
            line: 20,
        ));

        $this->assertTrue($this->graph->hasPathTo('App\\Controller', 'index', 'App\\Repository', 'find'));
    }

    public function testHasPathToReturnsFalseWhenNoPath(): void
    {
        $this->graph->addCall(new MethodCall(
            callerClass: 'App\\Controller',
            callerMethod: 'index',
            calleeClass: 'App\\Service',
            calleeMethod: 'execute',
            line: 10,
        ));

        $this->assertFalse($this->graph->hasPathTo('App\\Controller', 'index', 'App\\Repository', 'find'));
    }

    public function testHasPathToHandlesCycles(): void
    {
        // A -> B -> A (cycle)
        $this->graph->addCall(new MethodCall(
            callerClass: 'App\\A',
            callerMethod: 'methodA',
            calleeClass: 'App\\B',
            calleeMethod: 'methodB',
            line: 10,
        ));
        $this->graph->addCall(new MethodCall(
            callerClass: 'App\\B',
            callerMethod: 'methodB',
            calleeClass: 'App\\A',
            calleeMethod: 'methodA',
            line: 20,
        ));

        // Should not infinite loop
        $this->assertFalse($this->graph->hasPathTo('App\\A', 'methodA', 'App\\C', 'methodC'));
    }

    public function testFindPathToDirectCall(): void
    {
        $call = new MethodCall(
            callerClass: 'App\\Controller',
            callerMethod: 'index',
            calleeClass: 'App\\Service',
            calleeMethod: 'execute',
            line: 10,
        );

        $this->graph->addCall($call);

        $path = $this->graph->findPathTo('App\\Controller', 'index', 'App\\Service', 'execute');

        $this->assertNotNull($path);
        $this->assertCount(1, $path);
        $this->assertSame('App\\Service', $path[0]->calleeClass);
    }

    public function testFindPathToIndirectCall(): void
    {
        $this->graph->addCall(new MethodCall(
            callerClass: 'App\\Controller',
            callerMethod: 'index',
            calleeClass: 'App\\Service',
            calleeMethod: 'execute',
            line: 10,
        ));
        $this->graph->addCall(new MethodCall(
            callerClass: 'App\\Service',
            callerMethod: 'execute',
            calleeClass: 'App\\Repository',
            calleeMethod: 'find',
            line: 20,
        ));

        $path = $this->graph->findPathTo('App\\Controller', 'index', 'App\\Repository', 'find');

        $this->assertNotNull($path);
        $this->assertCount(2, $path);
        $this->assertSame('App\\Service', $path[0]->calleeClass);
        $this->assertSame('App\\Repository', $path[1]->calleeClass);
    }

    public function testFindPathToReturnsNullWhenNoPath(): void
    {
        $this->assertNull($this->graph->findPathTo('App\\Controller', 'index', 'App\\Service', 'execute'));
    }

    public function testGetAllMethods(): void
    {
        $this->graph->addCall(new MethodCall(
            callerClass: 'App\\A',
            callerMethod: 'method1',
            calleeClass: 'App\\B',
            calleeMethod: 'method2',
            line: 10,
        ));
        $this->graph->addCall(new MethodCall(
            callerClass: 'App\\B',
            callerMethod: 'method2',
            calleeClass: 'App\\C',
            calleeMethod: 'method3',
            line: 20,
        ));

        $methods = $this->graph->getAllMethods();

        $this->assertCount(3, $methods);
        $this->assertContains('App\\A::method1', $methods);
        $this->assertContains('App\\B::method2', $methods);
        $this->assertContains('App\\C::method3', $methods);
    }

    public function testCallWithNullCalleeClassNotAddedToIncoming(): void
    {
        $call = new MethodCall(
            callerClass: 'App\\Controller',
            callerMethod: 'index',
            calleeClass: null,
            calleeMethod: 'unknownMethod',
            line: 10,
        );

        $this->graph->addCall($call);

        // Should be in outgoing
        $outgoing = $this->graph->getCallsFrom('App\\Controller', 'index');
        $this->assertCount(1, $outgoing);

        // But not trackable in incoming (null class)
        $this->assertSame([], $this->graph->getCallsTo('', 'unknownMethod'));
    }
}
