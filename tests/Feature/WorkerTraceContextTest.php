<?php

namespace Sensyn\LaravelOTelSupport\Tests\Feature;

use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Mockery;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use RuntimeException;
use Sensyn\LaravelOTelSupport\Tests\TestCase;
use Sensyn\LaravelOTelSupport\WorkerTraceContext;

/**
 * auto-laravel の Command hook と同じく、command span を Context::storage()->attach() で載せた状態から始める。
 */
class WorkerTraceContextTest extends TestCase
{
    private TracerProvider $tracerProvider;

    private SpanInterface $commandSpan;

    private ScopeInterface $commandScope;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tracerProvider = new TracerProvider;
        $this->commandSpan = $this->tracerProvider->getTracer('test')->spanBuilder('Command queue:work')->startSpan();
        $this->commandScope = Context::storage()->attach($this->commandSpan->storeInContext(Context::getCurrent()));
    }

    private function subscribedDispatcher(): Dispatcher
    {
        $dispatcher = new Dispatcher;
        (new WorkerTraceContext)->subscribe($dispatcher);

        return $dispatcher;
    }

    private function startSpan(): SpanInterface
    {
        return $this->tracerProvider->getTracer('test')->spanBuilder('receive')->startSpan();
    }

    public function test_spans_join_the_command_span_before_the_first_loop(): void
    {
        // 前提の確認: 切り離す前は command span の trace に入る。
        $this->assertSame($this->commandSpan->getContext()->getTraceId(), $this->startSpan()->getContext()->getTraceId());
    }

    public function test_spans_start_their_own_trace_after_the_first_loop(): void
    {
        $dispatcher = $this->subscribedDispatcher();

        $this->assertNotFalse($dispatcher->until(new Looping('redis', 'default')));

        $this->assertNotSame($this->commandSpan->getContext()->getTraceId(), $this->startSpan()->getContext()->getTraceId());
    }

    public function test_later_loops_do_not_stack_scopes(): void
    {
        $dispatcher = $this->subscribedDispatcher();

        $dispatcher->until(new Looping('redis', 'default'));
        $dispatcher->until(new Looping('redis', 'default'));
        $dispatcher->dispatch(new WorkerStopping);

        // 1 回の WorkerStopping で command span の scope に戻る = attach は 1 回だけ。
        $this->assertSame($this->commandScope, Context::storage()->scope());
    }

    public function test_worker_stopping_restores_the_command_span(): void
    {
        $dispatcher = $this->subscribedDispatcher();

        $dispatcher->until(new Looping('redis', 'default'));
        $dispatcher->dispatch(new WorkerStopping);

        // auto-laravel の post hook は Context::storage()->scope() を外して command span を閉じる。
        $this->assertSame($this->commandScope, Context::storage()->scope());
    }

    public function test_worker_stopping_leaves_a_job_scope_on_top_alone(): void
    {
        $dispatcher = $this->subscribedDispatcher();

        $dispatcher->until(new Looping('redis', 'default'));
        // job timeout (Worker::kill) ではジョブの span の scope が上にある状態で WorkerStopping が来る。
        $jobScope = Context::storage()->attach($this->startSpan()->storeInContext(Context::getCurrent()));
        $dispatcher->dispatch(new WorkerStopping);

        $this->assertSame($jobScope, Context::storage()->scope());
    }

    public function test_worker_stopping_without_a_loop_does_nothing(): void
    {
        $this->subscribedDispatcher()->dispatch(new WorkerStopping);

        $this->assertSame($this->commandScope, Context::storage()->scope());
    }

    public function test_the_provider_subscribes_it_by_default(): void
    {
        app(DispatcherContract::class)->until(new Looping('redis', 'default'));

        $this->assertNotSame($this->commandSpan->getContext()->getTraceId(), $this->startSpan()->getContext()->getTraceId());
    }

    public function test_a_failing_attach_does_not_reach_the_worker(): void
    {
        $dispatcher = $this->subscribedDispatcher();
        $storage = Mockery::mock(ContextStorageInterface::class.', '.ExecutionContextAwareInterface::class);
        $storage->shouldReceive('attach')->andThrow(new RuntimeException('attach exploded'));
        Context::setStorage($storage);

        $this->assertNotFalse($dispatcher->until(new Looping('redis', 'default')));
    }

    public function test_a_failing_detach_does_not_reach_the_worker(): void
    {
        $dispatcher = $this->subscribedDispatcher();
        $dispatcher->until(new Looping('redis', 'default'));
        $storage = Mockery::mock(ContextStorageInterface::class.', '.ExecutionContextAwareInterface::class);
        $storage->shouldReceive('scope')->andThrow(new RuntimeException('scope exploded'));
        Context::setStorage($storage);

        $dispatcher->dispatch(new WorkerStopping);

        $this->addToAssertionCount(1);
    }
}
