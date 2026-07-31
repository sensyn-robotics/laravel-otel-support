<?php

namespace Sensyn\LaravelOTelSupport\Metrics;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * histogram のバケット境界のパース / 正規化。
 *
 * **同梱 config はこのクラスを参照しない。** config/otel-support.php が `Buckets::parse(env(...), ...)`
 * を書いていた頃は、publish した消費側リポジトリにその式がコピーとして残り、パッケージを外すか
 * クラスを rename した時点で config ロード時 (= framework boot 前、例外ハンドラより前) の fatal に
 * なっていた。artisan 自身も同じ bootstrap を通るため `config:clear` でも復旧できない。
 * 今の同梱 config は env の生値だけを持ち、パース/正規化は実行時の読み口である fromConfig() が行う。
 *
 * このクラス全体が @internal で、消費側のファイルから直接呼ばれることは無い。
 */
class Buckets
{
    /**
     * semconv が HTTP / messaging duration の双方に推奨する ExplicitBucketBoundaries (秒)。
     * https://opentelemetry.io/docs/specs/semconv/http/http-metrics/
     * https://opentelemetry.io/docs/specs/semconv/messaging/messaging-metrics/
     *
     * @var list<int|float>
     *
     * @internal DEFAULT_HTTP / DEFAULT_QUEUE の実体。消費側は用途別の定数を使うこと。
     */
    public const SEMCONV_DURATION = [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10];

    /**
     * @var list<int|float>
     *
     * @internal 計装が既定として渡す。同梱 config からは参照しない。
     */
    public const DEFAULT_HTTP = self::SEMCONV_DURATION;

    /**
     * @var list<int|float>
     *
     * @internal 計装が既定として渡す。同梱 config からは参照しない。
     */
    public const DEFAULT_QUEUE = self::SEMCONV_DURATION;

    /**
     * カンマ区切り文字列 ("0.05,0.1,0.25,...") を正規化済みの histogram バケット配列にパースする。
     * 非数値・空は $default を返す。順不同・重複は normalize() で整える。
     *
     * @param  list<int|float>  $default
     * @return list<int|float>
     *
     * @internal fromConfig() が env 由来の文字列を読むための入口。
     */
    public static function parse(mixed $csv, array $default): array
    {
        // env 未指定 (null 等) も不正値も $default。異常かどうかの区別は fromConfig() 側で行う。
        return (is_string($csv) ? self::parseCsv($csv) : null) ?? $default;
    }

    /**
     * カンマ区切り文字列を正規化済みの境界配列にする。パースできなければ null (= 呼び出し側が既定へ)。
     *
     * @return list<int|float>|null
     */
    private static function parseCsv(string $csv): ?array
    {
        $boundaries = [];
        foreach (explode(',', $csv) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue; // 末尾カンマ等の空トークンはスキップ
            }
            if (! is_numeric($token)) {
                return null; // floatval で "oops" => 0.0 に化けさせず既定に戻す
            }
            $boundaries[] = (float) $token;
        }

        return self::normalize($boundaries);
    }

    /**
     * config からバケット境界を読み normalize する。受けつける形は 2 つ:
     *
     *   - **カンマ区切り文字列** — 同梱 config が `env('OTEL_METRICS_HTTP_BUCKETS')` を素通しした形。
     *   - **数値配列** — 消費側が publish した config に直接書いた形。
     *
     * 未設定 (キー無し、または env 未指定で null) は異常ではないので無警告で $default。
     * それ以外の不正 (非数値 / 空 / 配列でも文字列でもない) は warning を出して $default に倒す。
     * telemetry の設定ミスでアプリ (特に HTTP の hot path) を落とさないよう throw しない。
     *
     * @param  list<int|float>  $default
     * @return list<int|float>
     *
     * @internal 計装が config を読むための唯一の入口。
     */
    public static function fromConfig(string $configKey, array $default): array
    {
        // config() helper (illuminate/foundation) は使わない: どの illuminate/* コンポーネントにも含まれず、
        // laravel/framework 本体を require しない限り宣言できない依存になる。static ヘルパで container を
        // 持たないため、既存の Log と同じく illuminate/support の facade で引く。
        //
        // 既定値を Config::get に渡さないのは、キーが存在して値が null (env 未指定) の場合に
        // Config::get が既定ではなく null を返すため。両者を下の null 判定で同じ扱いにする。
        $configured = Config::get($configKey);

        if ($configured === null) {
            return $default;
        }

        $normalized = match (true) {
            is_string($configured) => self::parseCsv($configured),
            is_array($configured) => self::normalize($configured),
            default => null,
        };
        if ($normalized !== null) {
            return $normalized;
        }

        Log::warning("[laravel-otel-support.metrics] config '{$configKey}' is not a numeric bucket list; falling back to default buckets.");

        return $default;
    }

    /**
     * 数値の境界リストをソート + 重複除去で strictly-ascending に正規化する。
     * SDK の ExplicitBucketHistogramAggregation は順序検証をしないので、降順・重複・非数値を
     * 素通すと histogram が静かに壊れる。空 or 非数値が混ざる場合は null を返す。
     *
     * @param  array<array-key, mixed>  $boundaries
     * @return list<int|float>|null
     *
     * @internal parse() / fromConfig() の共通処理。
     */
    public static function normalize(array $boundaries): ?array
    {
        $numeric = [];
        foreach ($boundaries as $boundary) {
            if (! is_int($boundary) && ! is_float($boundary)) {
                return null;
            }
            $numeric[] = $boundary;
        }
        if ($numeric === []) {
            return null;
        }

        $numeric = array_values(array_unique($numeric, SORT_NUMERIC));
        sort($numeric, SORT_NUMERIC);

        return $numeric;
    }
}
