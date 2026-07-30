<?php

namespace Sensyn\LaravelOTelSupport\Tests\Feature;

use Illuminate\Support\Arr;
use Illuminate\Testing\PendingCommand;
use Sensyn\LaravelOTelSupport\Metrics\Buckets;
use Sensyn\LaravelOTelSupport\Tests\TestCase;

/**
 * vendor:publish --tag=otel-support-config の回帰テスト。
 *
 * publish された config は消費側アプリで require されるため、中の名前空間参照 (Buckets) が
 * パッケージのものになっていないと、publish した瞬間から実行時に落ちる
 * (移設元アプリの旧名前空間が残っている場合など。既定値のままなら publish 不要なので気付きにくい)。
 * タグ名・出力パス・publish 後の中身を実際に publish して確認する。
 */
class PublishesConfigTest extends TestCase
{
    private string $publishedPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Testbench の skeleton (vendor 配下) の config ディレクトリ。skeleton は otel-support.php を
        // 同梱しないので、ここに在るのは前回の実行が落ちて残した物だけ。stale な内容で緑にならないよう消す。
        $this->publishedPath = app()->configPath('otel-support.php');
        $this->deletePublishedConfig();
    }

    protected function tearDown(): void
    {
        $this->deletePublishedConfig();

        parent::tearDown();
    }

    private function deletePublishedConfig(): void
    {
        if ($this->publishedPath !== '' && file_exists($this->publishedPath)) {
            unlink($this->publishedPath);
        }
    }

    private function publish(): void
    {
        // --force は stale なファイルを黙ってスキップさせないため (skip でも exit code は 0)。
        $command = $this->artisan('vendor:publish', ['--tag' => 'otel-support-config', '--force' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);

        // run() を明示して、このメソッドから戻った時点で publish 済みであることを保証する
        // (PendingCommand は破棄時にも実行されるが、破棄タイミングに依存させない)。
        $command->assertSuccessful()->run();
    }

    public function test_config_is_published_to_the_application_config_path(): void
    {
        $this->assertFileDoesNotExist($this->publishedPath);

        $this->publish();

        // タグ名・publish 先パスの両方の担保 (どちらかが違えば何も出力されない)。
        $this->assertFileExists($this->publishedPath);
    }

    public function test_published_config_points_at_the_package_namespace(): void
    {
        $this->publish();

        $contents = (string) file_get_contents($this->publishedPath);
        $this->assertStringContainsString(Buckets::class, $contents);

        // publish 後の config が実際にロードできること。旧名前空間 (移設元アプリの App\... 等) を
        // 指していれば、この require が Error で落ちる。
        /** @var array<string, mixed> $published */
        $published = require $this->publishedPath;

        $this->assertSame(Buckets::DEFAULT_HTTP, Arr::get($published, 'metrics.http.buckets'));
        $this->assertSame(Buckets::DEFAULT_QUEUE, Arr::get($published, 'metrics.queue.duration_buckets'));
        $this->assertTrue(Arr::get($published, 'metrics.enabled'));
        $this->assertTrue(Arr::get($published, 'worker_flusher.enabled'));
    }
}
