<?php

namespace tests\Fixture;

class PaymentService
{
    private $validator;
    private const TAX_RATE = 0.21;
    private const MAX_AMOUNT = 10000;

    public function validateCard(string $cardNumber): bool
    {
        return $this->validator->validateCard($cardNumber);
    }

    public function validateAmount(float $amount): bool
    {
        return $amount > 0 && $amount <= self::MAX_AMOUNT;
    }

    public function calculateTax(float $amount): float
    {
        return $amount * self::TAX_RATE;
    }

    public function formatAmount(float $amount): string
    {
        return number_format($amount, 2) . ' EUR';
    }

    public function processPayment(array $data): array
    {
        return $data;
    }
}

?>
-----
<?php

namespace App;

use App\Traits\ValidationTrait;
use App\Traits\CalculationTrait;
use App\Traits\FormattingTrait;

class PaymentService
{
    use ValidationTrait;
    use CalculationTrait;
    use FormattingTrait;

    private $validator;
    private const TAX_RATE = 0.21;
    private const MAX_AMOUNT = 10000;

    public function processPayment(array $data): array
    {
        return $data;
    }
}

?>