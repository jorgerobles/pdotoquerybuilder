<?php

namespace tests\Fixture;

class DataFormatter
{
    public function formatEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function formatPhone(string $phone): string
    {
        return preg_replace('/[^\d+]/', '', $phone);
    }

    public function formatCurrency(float $amount): string
    {
        return '' . number_format($amount, 2);
    }

    public function processData(array $data): array
    {
        // Core logic remains
        return $data;
    }
}

?>
-----
<?php

namespace App;

use App\Traits\FormattingTrait;

class DataFormatter
{
    use FormattingTrait;

    public function processData(array $data): array
    {
        // Core logic remains
        return $data;
    }
}

?>