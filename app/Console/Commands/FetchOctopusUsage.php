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
        $result = 0;

        $fromInput = $this->option('from');
        $toInput = $this->option('to');

        if ($fromInput !== null && $toInput !== null) {
            $from = Carbon::createFromFormat('Y-m-d', $fromInput);
            $to = Carbon::createFromFormat('Y-m-d', $toInput);

            $token = $this->getToken();
            if (!$token) {
                $this->error("❌ トークン取得失敗（範囲指定）");
                $result = 1;
            } else {
                while ($from->lte($to)) {
                    $date = $from->format('Y-m-d');
                    $this->processSingleDay($date, $token);
                    sleep(3);
                    $from->addDay();
                }
            }

        } else {
            $token = $this->getToken();
            if (!$token) {
                $this->error('❌ トークン取得失敗。');
                $result = 1;
            } else {
                $result = $this->processSingleDay($this->option('date'), $token);
            }
        }

        return $result;
    }

    private function processSingleDay(?string $inputDate, string $token): int
    {
        [$targetDateJST, $startUtc, $endUtc] = $this->getTargetDateRange($inputDate);
        $dateText = $targetDateJST->format('Y-m-d');

        $accountNumber = $this->getAccountNumber($token);
        if (!$accountNumber) {
            return 1;
        }

        $readings = $this->getHalfHourlyReadings($token, $accountNumber, $startUtc, $endUtc);
        if (empty($readings)) {
            return 0;
        }

        $filteredReadings = collect($readings)->filter(function ($item) use ($targetDateJST) {
            $startAt = Carbon::parse($item['startAt'])->addHours(9);
            return $startAt->isSameDay($targetDateJST);
        });

        $totalKWh = $this->calculateTotalKWh($filteredReadings->all());
        $estimatedCost = $this->calculateEstimatedCost($totalKWh);

        $this->line("✅ {$dateText} の合計電力使用量: {$totalKWh} kWh");
        $this->line("💰 推定電気料金: {$estimatedCost} 円");

        OctopusUsage::updateOrCreate(
            ['date' => $dateText],
            ['kwh' => $totalKWh, 'estimated_cost' => $estimatedCost]
        );

        $this->outputMonthlySummary($targetDateJST);

        return 0;
    }

    private function getTargetDateRange(?string $inputDate = null): array
    {
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

        return $res['data']['account']['properties'][0]['electricitySupplyPoints'][0]['halfHourlyReadings'] ?? [];
    }

    private function calculateTotalKWh(array $readings): float
    {
        return collect($readings)->reduce(fn($carry, $item) => $carry + floatval($item['value']), 0);
    }

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

    private function outputMonthlySummary(Carbon $targetDate): void
    {
        $year = $targetDate->year;
        $month = $targetDate->month;

        if ($targetDate->day < 23) {
            $start = Carbon::create($year, $month - 1, 23)->startOfDay();
            $end = Carbon::create($year, $month, 22)->endOfDay();
        } else {
            $start = Carbon::create($year, $month, 23)->startOfDay();
            $end = Carbon::create($year, $month + 1, 22)->endOfDay();
        }

        $usages = OctopusUsage::whereBetween('date', [$start->toDateString(), $end->toDateString()])->get();

        $totalKWh = $usages->sum('kwh');
        $totalCost = $usages->sum('estimated_cost');

        $this->line("📊 月次集計（{$start->format('Y/m/d')}〜{$end->format('Y/m/d')}）");
        $this->line("🔌 合計使用量: {$totalKWh} kWh");
        $this->line("💰 合計金額: {$totalCost} 円");
    }
}
