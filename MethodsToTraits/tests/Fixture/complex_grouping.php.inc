<?php

namespace tests\Fixture;

class AnalyticsService
{
    public function calculateAverage(array $numbers): float
    {
        return array_sum($numbers) / count($numbers);
    }

    public function calculateSum(array $numbers): float
    {
        return array_sum($numbers);
    }

    public function calculateMedian(array $numbers): float
    {
        sort($numbers);
        $count = count($numbers);
        $middle = floor(($count - 1) / 2);

        if ($count % 2) {
            return $numbers[$middle];
        } else {
            return ($numbers[$middle] + $numbers[$middle + 1]) / 2;
        }
    }

    public function parseJson(string $json): array
    {
        return json_decode($json, true) ?? [];
    }

    public function parseXml(string $xml): array
    {
        $parsed = simplexml_load_string($xml);
        return json_decode(json_encode($parsed), true);
    }

    public function generateReport(array $data): string
    {
        return json_encode($data);
    }

    public function generateChart(array $data): string
    {
        return '<chart>' . implode(',', $data) . '</chart>';
    }

    public function executeAnalysis(array $data): array
    {
        // Core business logic remains
        return $data;
    }
}

?>
-----
<?php

namespace App;

use App\Traits\CalculationTrait;
use App\Traits\ParsingTrait;
use App\Traits\GenerationTrait;

class AnalyticsService
{
    use CalculationTrait;
    use ParsingTrait;
    use GenerationTrait;

    public function executeAnalysis(array $data): array
    {
        // Core business logic remains
        return $data;
    }
}

?>