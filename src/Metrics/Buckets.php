<?php

namespace Sensyn\LaravelOTelSupport\Metrics;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * histogram のバケット境界のパース / 正規化。
 *
 * 同梱 config が参照するため、publish 済み config を持つ消費側アプリのファイルから直接呼ばれる:
 * config/otel-support.php は `Buckets::parse(env(...), Buckets::DEFAULT_HTTP)` の形でこのクラスに
 * 依存し、publish するとその式が消費側リポジトリにコピーとして残る。@api を付けたメンバは
 * その BC 面 (壊すと config ロード時 = framework boot 前に fatal になる)。
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
     * @api publish 済み config が参照する。
     */
    public const DEFAULT_HTTP = self::SEMCONV_DURATION;

    /**
     * @var list<int|float>
     *
     * @api publish 済み config が参照する。
     */
    public const DEFAULT_QUEUE = self::SEMCONV_DURATION;

    /**
     * カンマ区切り文字列 ("0.05,0.1,0.25,...") を正規化済みの histogram バケット配列にパースする。
     * 非数値・空は $default を返す。順不同・重複は normalize() で整える。
     *
     * @param  list<int|float>  $default
     * @return list<int|float>
     *
     * @api publish 済み config が参照する。
     */
    public static function parse(mixed $csv, array $default): array
    {
        if (! is_string($csv)) {
            return $default; // env 未指定 (null 等)。異常ではないので無警告。
        }

        $boundaries = [];
        foreach (explode(',', $csv) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue; // 末尾カンマ等の空トークンはスキップ
            }
            if (! is_numeric($token)) {
                return $default; // floatval で "oops" => 0.0 に化けさせず default に戻す
            }
            $boundaries[] = (float) $token;
        }

        return self::normalize($boundaries) ?? $default;
    }

    /**
     * config からバケット境界を読み normalize する。不正 (非配列 / 非数値 / 空) なら warning を出して
     * $default にフォールバックする。telemetry の設定ミスでアプリ (特に HTTP の hot path) を落とさない
     * よう throw しない。
     *
     * @param  list<int|float>  $default
     * @return list<int|float>
     *
     * @internal 計装が config を読むための入口。publish 済み config は parse() を使う。
     */
    public static function fromConfig(string $configKey, array $default): array
    {
        // config() helper (illuminate/foundation) は使わない: どの illuminate/* コンポーネントにも含まれず、
        // laravel/framework 本体を require しない限り宣言できない依存になる。static ヘルパで container を
        // 持たないため、既存の Log と同じく illuminate/support の facade で引く。
        $configured = Config::get($configKey, $default);
        $normalized = is_array($configured) ? self::normalize($configured) : null;
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
