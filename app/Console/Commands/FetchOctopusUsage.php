<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use App\Models\OctopusUsage;

class FetchOctopusUsage extends Command
{
    protected $signature = 'app:fetch-octopus-usage {--date=} {--from=} {--to=}';

    protected $description = 'Fetch daily electricity usage from Octopus and store in the database';

    public function handle(): int
    {
        // 🔁 複数日対応
        if ($this->option('from') && $this->option('to')) {
            $from = Carbon::createFromFormat('Y-m-d', $this->option('from'));
            $to = Carbon::createFromFormat('Y-m-d', $this->option('to'));

            $token = $this->getToken();
            if (!$token) {
                $this->error("❌ トークン取得失敗（範囲指定）");
                return 1;
            }

            while ($from->lte($to)) {
                $date = $from->format('Y-m-d');
                $this->info("📅 処理中: {$date}");
                $this->processSingleDay($date, $token);
                sleep(3); // API制限対策の待機
                $from->addDay();
            }

            return 0;
        }

        // 🔁 単一日の処理
        $token = $this->getToken();
        if (!$token) {
            $this->error('❌ トークン取得失敗。');
            return 1;
        }

        return $this->processSingleDay($this->option('date'), $token);
    }

    /**
     * 指定日のデータを取得・保存
     */
    private function processSingleDay(?string $inputDate, string $token): int
    {
        [$targetDateJST, $startUtc, $endUtc] = $this->getTargetDateRange($inputDate);
        $dateText = $targetDateJST->format('Y-m-d');

        $this->info("\n🕒 UTC取得範囲: {$startUtc} ～ {$endUtc}");
        $this->info("🗓 対象JST日付: {$dateText} (00:00 ～ 23:59)");

        $accountNumber = $this->getAccountNumber($token);
        if (!$accountNumber) {
            $this->error("❌ アカウント番号取得失敗（{$dateText}）");
            return 1;
        }

        $readings = $this->getHalfHourlyReadings($token, $accountNumber, $startUtc, $endUtc);
        if (empty($readings)) {
            $this->warn("⚠️ データが見つかりませんでした（{$dateText}）");
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
     * JST日付からUTCの範囲を取得
     */
    private function getTargetDateRange(?string $inputDate = null): array
    {
        $targetDateJST = $inputDate
            ? Carbon::createFromFormat('Y-m-d', $inputDate)->startOfDay()
            : Carbon::yesterday()->startOfDay();

        $startUtc = $targetDateJST->copy()->subHours(9)->toIso8601String();
        $endUtc = $targetDateJST->copy()->addDay()->subSecond()->subHours(9)->toIso8601String();

        return [$targetDateJST, $startUtc, $endUtc];
    }

    /**
     * OctopusのJWTトークンを取得
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
     * アカウント番号を取得
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
     * 指定期間の電力使用量データを取得
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
     * 合計使用量（kWh）を算出
     */
    private function calculateTotalKWh(array $readings): float
    {
        return collect($readings)->reduce(fn($carry, $item) => $carry + floatval($item['value']), 0);
    }

    /**
     * 使用量から段階料金制で金額を算出
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
