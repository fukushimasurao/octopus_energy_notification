<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use App\Models\OctopusUsage;

class FetchOctopusUsage extends Command
{
    protected $signature = 'app:fetch-octopus-usage {--date=}';

    protected $description = 'Fetch daily electricity usage from Octopus and notify via LINE';

    public function handle(): int
    {
        [$targetDateJST, $startUtc, $endUtc] = $this->getTargetDateRange();
        $dateText = $targetDateJST->format('Y-m-d');

        $this->info("\n🕒 UTC取得範囲: {$startUtc} ～ {$endUtc}");
        $this->info("🗓 対象JST日付: {$dateText} (00:00 ～ 23:59)");

        $token = $this->getToken();
        if (!$token) {
            $this->error('❌ トークン取得失敗。');
            return 1;
        }

        $accountNumber = $this->getAccountNumber($token);
        if (!$accountNumber) {
            $this->error('❌ アカウント番号取得失敗。');
            return 1;
        }

        $readings = $this->getHalfHourlyReadings($token, $accountNumber, $startUtc, $endUtc);
        if (empty($readings)) {
            $this->warn("⚠️ データが見つかりませんでした。");
            return 0;
        }

        $filteredReadings = collect($readings)->filter(function ($item) use ($targetDateJST) {
            $startAt = Carbon::parse($item['startAt'])->addHours(9);
            return $startAt->isSameDay($targetDateJST);
        });

        $totalKWh = $this->calculateTotalKWh($filteredReadings->all());
        $estimatedCost = $this->calculateEstimatedCost($totalKWh);

        $this->info("✅ {$dateText} の合計電力使用量: {$totalKWh} kWh");
        $this->info("💰 推定電気料金: {$estimatedCost} 円");

        OctopusUsage::updateOrCreate(
            ['date' => $dateText],
            ['kwh' => $totalKWh, 'estimated_cost' => $estimatedCost]
        );

        $this->info("📝 データベースに保存しました。");

        return 0;
    }

    /**
     * 対象となるJST日付と、それに対応するUTCの開始・終了時刻を取得する。
     *
     * @return array [Carbon $targetDateJST, string $startUtc, string $endUtc]
     */
    private function getTargetDateRange(): array
    {
        $inputDate = $this->option('date');
        $targetDateJST = $inputDate
            ? Carbon::createFromFormat('Y-m-d', $inputDate)->startOfDay()
            : Carbon::yesterday()->startOfDay();

        $startUtc = $targetDateJST->copy()->subHours(9)->toIso8601String();
        $endUtc = $targetDateJST->copy()->addDay()->subSecond()->subHours(9)->toIso8601String();

        return [$targetDateJST, $startUtc, $endUtc];
    }

    /**
     * Octopus Energy APIからJWTトークンを取得する。
     *
     * @return string|null 成功時はトークン、失敗時はnull
     */
    private function getToken(): ?string
    {
        $email = env('OCTOPUS_EMAIL');
        $password = env('OCTOPUS_PASSWORD');

        $response = Http::post('https://api.oejp-kraken.energy/v1/graphql/', [
            'query' => 'mutation obtainKrakenToken($input: ObtainJSONWebTokenInput!) {
                obtainKrakenToken(input: $input) {
                    token
                }
            }',
            'variables' => ['input' => compact('email', 'password')],
        ]);

        return $response['data']['obtainKrakenToken']['token'] ?? null;
    }

    /**
     * トークンに紐づくOctopus Energyのアカウント番号を取得する。
     *
     * @param string $token JWTトークン
     * @return string|null アカウント番号またはnull
     */
    private function getAccountNumber(string $token): ?string
    {
        $res = Http::withHeaders([
            'Authorization' => 'JWT ' . $token,
        ])->post('https://api.oejp-kraken.energy/v1/graphql/', [
            'query' => 'query accountViewer { viewer { accounts { number } } }'
        ]);

        return $res['data']['viewer']['accounts'][0]['number'] ?? null;
    }

    /**
     * 指定された期間の30分ごとの電力使用量データを取得する。
     *
     * @param string $token JWTトークン
     * @param string $accountNumber アカウント番号
     * @param string $startUtc UTCの開始日時
     * @param string $endUtc UTCの終了日時
     * @return array 使用量データの配列
     */
    private function getHalfHourlyReadings(string $token, string $accountNumber, string $startUtc, string $endUtc): array
    {
        $query = [
            'query' => 'query halfHourlyReadings($accountNumber: String!, $fromDatetime: DateTime, $toDatetime: DateTime) {
                account(accountNumber: $accountNumber) {
                    properties {
                        electricitySupplyPoints {
                            halfHourlyReadings(fromDatetime: $fromDatetime, toDatetime: $toDatetime) {
                                startAt
                                value
                            }
                        }
                    }
                }
            }',
            'variables' => compact('accountNumber', 'startUtc', 'endUtc')
        ];

        $res = Http::withHeaders([
            'Authorization' => 'JWT ' . $token,
        ])->post('https://api.oejp-kraken.energy/v1/graphql/', $query);

        return $res['data']['account']['properties'][0]['electricitySupplyPoints'][0]['halfHourlyReadings'] ?? [];
    }

    /**
     * 電力使用量（kWh）を30分ごとのデータから合計する。
     *
     * @param array $readings 使用量データ
     * @return float 合計kWh
     */
    private function calculateTotalKWh(array $readings): float
    {
        return collect($readings)->reduce(fn($carry, $item) => $carry + floatval($item['value']), 0);
    }

    /**
     * 指定されたkWh使用量に基づき、電気料金を推定する。
     * 段階料金制に基づいて料金を計算し、基本料金を加算する。
     *
     * @param float $totalKWh 使用量（kWh）
     * @return float 推定料金（円）
     */
    private function calculateEstimatedCost(float $totalKWh): float
    {
        $baseCost = 29.10;
        $energyCost = 0.0;

        if ($totalKWh <= 120) {
            $energyCost = $totalKWh * 20.62;
        } elseif ($totalKWh <= 300) {
            $energyCost = 120 * 20.62 + ($totalKWh - 120) * 25.29;
        } else {
            $energyCost = 120 * 20.62 + 180 * 25.29 + ($totalKWh - 300) * 27.44;
        }

        return round($baseCost + $energyCost, 2);
    }
}
