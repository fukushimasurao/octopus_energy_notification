<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class FetchOctopusUsage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fetch-octopus-usage';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch yesterday\'s electricity usage from Octopus and notify via LINE';


    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = env('OCTOPUS_EMAIL');
        $password = env('OCTOPUS_PASSWORD');
        $lineUserId = env('LINE_USER_ID');
        $lineAccessToken = env('LINE_CHANNEL_ACCESS_TOKEN');


        // Octopus APIエンドポイント
        $apiUrl = 'https://api.oejp-kraken.energy/v1/graphql/';

        // 前日（JST）→ UTC に変換
        $targetDateJST = Carbon::yesterday()->startOfDay();
        $startUtc = $targetDateJST->copy()->subHours(9);
        $endUtc = $targetDateJST->copy()->addDay()->subSeconds(1)->subHours(9);

        // 表示確認
        $this->info("🕒 UTC取得範囲: {$startUtc} ～ {$endUtc}");
        $this->info("🗓 対象JST日付: {$targetDateJST->format('Y-m-d')} (00:00 ～ 23:59)");


        $dateText = $targetDateJST->format('Y-m-d');

        $this->info("⏱ 前日: $dateText のデータを取得中...");

        // Step1: トークン取得
        $authPayload = [
            'query' => 'mutation obtainKrakenToken($input: ObtainJSONWebTokenInput!) {
                obtainKrakenToken(input: $input) {
                    token
                }
            }',
            'variables' => ['input' => ['email' => $email, 'password' => $password]],
        ];

        $authRes = Http::post($apiUrl, $authPayload);
        $token = $authRes['data']['obtainKrakenToken']['token'] ?? null;

        if (!$token) {
            $this->error('❌ トークン取得失敗。メールとパスワードを確認してください。');
            return 1;
        }

        // Step 2: アカウント番号取得
        $accountRes = Http::withHeaders([
            'Authorization' => 'JWT ' . $token,
        ])->post($apiUrl, [
            'query' => 'query accountViewer {
                viewer {
                    accounts {
                        number
                    }
                }
            }'
        ]);

        $accountNumber = $accountRes['data']['viewer']['accounts'][0]['number'] ?? null;

        if (!$accountNumber) {
            $this->error('❌ アカウント番号取得失敗。');
            return 1;
        }

        // Step3: 使用量取得
        $usageQuery = [
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
            'variables' => [
                'accountNumber' => $accountNumber,
                'fromDatetime' => $startUtc,
                'toDatetime' => $endUtc,
            ]
        ];

        $usageRes = Http::withHeaders([
            'Authorization' => 'JWT ' . $token,
        ])->post($apiUrl, $usageQuery);
        $readings = $usageRes['data']['account']['properties'][0]['electricitySupplyPoints'][0]['halfHourlyReadings'] ?? [];

        if (empty($readings)) {
            $this->warn("⚠️ データが見つかりませんでした。");
            return 0;
        }

        // Step4: 合計
        $totalKWh = collect($readings)->reduce(function ($carry, $item) {
            return $carry + floatval($item['value']);
        }, 0);

        $this->info("✅ {$dateText} の合計電力使用量: {$totalKWh} kWh");

        return 0;
    }
}
