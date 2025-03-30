<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

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

        // JSTの対象日だけに絞り込む（UTC→JST変換して日付フィルタ）
        $filteredReadings = collect($readings)->filter(function ($item) use ($targetDateJST) {
            $startAt = Carbon::parse($item['startAt'])->addHours(9); // JST変換
            return $startAt->isSameDay($targetDateJST);
        });

        $totalKWh = $this->calculateTotalKWh($filteredReadings->all());
        $this->info("✅ {$dateText} の合計電力使用量: {$totalKWh} kWh");

        return 0;
    }

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

    private function getAccountNumber(string $token): ?string
    {
        $res = Http::withHeaders([
            'Authorization' => 'JWT ' . $token,
        ])->post('https://api.oejp-kraken.energy/v1/graphql/', [
            'query' => 'query accountViewer { viewer { accounts { number } } }'
        ]);

        return $res['data']['viewer']['accounts'][0]['number'] ?? null;
    }

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

        // supplyPoints[0] のみ利用（1つのメーター対象）
        return $res['data']['account']['properties'][0]['electricitySupplyPoints'][0]['halfHourlyReadings'] ?? [];
    }

    private function calculateTotalKWh(array $readings): float
    {
        return collect($readings)->reduce(fn($carry, $item) => $carry + floatval($item['value']), 0);
    }
}