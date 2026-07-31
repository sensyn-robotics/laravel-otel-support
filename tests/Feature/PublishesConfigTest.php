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

    /**
     * publish 済み config はパッケージのクラスを **一切参照してはいけない**。
     *
     * publish するとこのファイルは消費側リポジトリのコピーになり、以後パッケージ側の修正は遡って
     * 届かない。パッケージのクラスを参照していると、パッケージを composer から外した時点で
     * LoadConfiguration がこのファイルを require したところで「クラスが無い」の fatal になる。
     * それは HandleExceptions より前なので例外ハンドラが無く、`php artisan config:clear` 自身も
     * 同じ bootstrap を通るため artisan での復旧もできない。
     *
     * このアサートは名前空間文字列で見る。`Buckets::class` のような定数参照で書くと、クラスを
     * rename したときにアサート側も一緒に動いて検査が素通りする。
     */
    public function test_published_config_does_not_reference_the_package(): void
    {
        $this->publish();

        $contents = (string) file_get_contents($this->publishedPath);

        $this->assertStringNotContainsString('Sensyn\\LaravelOTelSupport', $contents);
        $this->assertStringNotContainsString('use ', $contents);
    }

    public function test_published_config_loads_without_the_package_autoloaded(): void
    {
        $this->publish();

        // publish 後の config が実際にロードできること。
        /** @var array<string, mixed> $published */
        $published = require $this->publishedPath;

        // env 未設定なので生値は null。既定への解決は実行時の Buckets::fromConfig() が行う。
        $this->assertNull(Arr::get($published, 'metrics.http.buckets'));
        $this->assertNull(Arr::get($published, 'metrics.queue.duration_buckets'));
        $this->assertTrue(Arr::get($published, 'metrics.enabled'));
        $this->assertTrue(Arr::get($published, 'worker_flusher.enabled'));

        // 実行時の読み口が、その null を既定のバケットに解決すること (config 側から定数参照を
        // 外したことで穴が開いていないかの確認)。
        $this->assertSame(
            Buckets::DEFAULT_HTTP,
            Buckets::fromConfig('otel-support.metrics.http.buckets', Buckets::DEFAULT_HTTP),
        );
    }
}
