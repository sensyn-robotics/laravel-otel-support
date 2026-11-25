<?php

namespace Sensyn\LaravelOTelSupport\Tests\Feature;

use Closure;
use Illuminate\Queue\Worker;
use Mockery;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use ReflectionProperty;
use RuntimeException;
use Sensyn\LaravelOTelSupport\Tests\TestCase;
use Sensyn\LaravelOTelSupport\WorkerTraceContext;

/**
 * auto-laravel の Command hook と同じく、command span を Context::storage()->attach() で載せた状態から始める。
 *
 * テスト環境には ext-opentelemetry が無いので、Worker::daemon の pre / post hook は register() に渡す
 * HookManager で受け取って直接呼ぶ。
 */
class WorkerTraceContextTest extends TestCase
{
    private TracerProvider $tracerProvider;

    private SpanInterface $commandSpan;

    private ScopeInterface $commandScope;

    private Closure $pre;

    private Closure $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tracerProvider = new TracerProvider;
        $this->commandSpan = $this->tracerProvider->getTracer('test')->spanBuilder('Command queue:work')->startSpan();
        $this->commandScope = Context::storage()->attach($this->commandSpan->storeInContext(Context::getCurrent()));

        $hooks = Mockery::mock(HookManagerInterface::class);
        $hooks->shouldReceive('hook')
            ->once()
            ->with(Worker::class, 'daemon', Mockery::type(Closure::class), Mockery::type(Closure::class))
            ->andReturnUsing(function (?string $class, string $function, Closure $pre, Closure $post): void {
                $this->pre = $pre;
                $this->post = $post;
            });
        (new WorkerTraceContext)->register($hooks);
    }

    private function startSpan(): SpanInterface
    {
        return $this->tracerProvider->getTracer('test')->spanBuilder('receive')->startSpan();
    }

    private function joinsCommandTrace(SpanInterface $span): bool
    {
        return $span->getContext()->getTraceId() === $this->commandSpan->getContext()->getTraceId();
    }

    public function test_spans_join_the_command_span_outside_the_daemon(): void
    {
        // 前提の確認: 切り離す前は command span の trace に入る。
        $this->assertTrue($this->joinsCommandTrace($this->startSpan()));
    }

    public function test_spans_start_their_own_trace_inside_the_daemon(): void
    {
        ($this->pre)();

        $this->assertFalse($this->joinsCommandTrace($this->startSpan()));
    }

    public function test_leaving_the_daemon_restores_the_command_span(): void
    {
        ($this->pre)();
        ($this->post)();

        // daemon が例外で抜けた時も ext の post hook は走る。auto-laravel の post hook は
        // Context::storage()->scope() を外して command span を閉じるので、ここに command span が戻っている必要がある。
        $this->assertSame($this->commandScope, Context::storage()->scope());
    }

    public function test_a_leaked_job_scope_on_top_is_left_alone(): void
    {
        ($this->pre)();
        $jobScope = Context::storage()->attach($this->startSpan()->storeInContext(Context::getCurrent()));
        ($this->post)();

        $this->assertSame($jobScope, Context::storage()->scope());
    }

    public function test_a_failing_attach_does_not_reach_the_worker(): void
    {
        // enter() は attach の前に Context::getCurrent() で current() を呼ぶので、そこは本物の context を返す。
        $current = Context::getCurrent();
        $storage = Mockery::mock(ContextStorageInterface::class.', '.ExecutionContextAwareInterface::class);
        $storage->shouldReceive('current')->andReturn($current);
        $storage->shouldReceive('attach')->once()->andThrow(new RuntimeException('attach exploded'));
        // attach に失敗した回の post は何もしない (storage に触らない)。
        $storage->shouldNotReceive('scope');
        Context::setStorage($storage);

        ($this->pre)();
        ($this->post)();
    }

    public function test_a_failing_detach_does_not_reach_the_worker(): void
    {
        ($this->pre)();
        $storage = Mockery::mock(ContextStorageInterface::class.', '.ExecutionContextAwareInterface::class);
        $storage->shouldReceive('scope')->andThrow(new RuntimeException('scope exploded'));
        Context::setStorage($storage);

        ($this->post)();

        $this->addToAssertionCount(1);
    }

    public function test_context_values_other_than_the_span_are_kept_inside_the_daemon(): void
    {
        $key = Context::createKey('app-value');
        Context::storage()->attach(Context::getCurrent()->with($key, 'kept'));

        ($this->pre)();

        $this->assertSame('kept', Context::getCurrent()->get($key));
    }

    public function test_register_once_hooks_only_once_per_process(): void
    {
        $this->resetRegistered();
        $hooks = Mockery::mock(HookManagerInterface::class);
        $hooks->shouldReceive('hook')->once();

        WorkerTraceContext::registerOnce($hooks);
        WorkerTraceContext::registerOnce($hooks);
    }

    public function test_a_failing_hook_registration_does_not_reach_the_application(): void
    {
        $this->resetRegistered();
        $hooks = Mockery::mock(HookManagerInterface::class);
        $hooks->shouldReceive('hook')->once()->andThrow(new RuntimeException('hook exploded'));

        WorkerTraceContext::registerOnce($hooks);

        $this->addToAssertionCount(1);
    }

    private function resetRegistered(): void
    {
        (new ReflectionProperty(WorkerTraceContext::class, 'registered'))->setValue(null, false);
    }
}
