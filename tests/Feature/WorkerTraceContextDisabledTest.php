<?php

namespace Sensyn\LaravelOTelSupport\Tests\Feature;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Events\Looping;
use OpenTelemetry\Context\Context;
use Sensyn\LaravelOTelSupport\Tests\TestCase;

class WorkerTraceContextDisabledTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('otel-support.worker_trace_context.enabled', false);
    }

    public function test_the_context_is_left_alone(): void
    {
        $scope = Context::storage()->attach(Context::getCurrent());

        app(Dispatcher::class)->until(new Looping('redis', 'default'));

        $this->assertSame($scope, Context::storage()->scope());
    }
}
