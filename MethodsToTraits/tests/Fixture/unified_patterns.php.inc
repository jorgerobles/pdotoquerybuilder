<?php

namespace tests\Fixture;

class ServiceClass
{
    // Methods for 1:1 extraction (auto trait names)
    public function generateUuid(): string
    {
        return uniqid();
    }

    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    // Methods for N:1 extraction (explicit trait name)
    public function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function validatePhone(string $phone): bool
    {
        return preg_match('/^\+?[1-9]\d{1,14}$/', $phone);
    }

    public function validateAge(int $age): bool
    {
        return $age >= 18 && $age <= 120;
    }

    // Methods for pattern-based extraction
    public function formatCurrency(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }

    public function formatDate(\DateTime $date): string
    {
        return $date->format('Y-m-d');
    }

    // Core business method (should stay)
    public function processOrder(array $data): array
    {
        return $data;
    }
}

?>
-----
<?php

namespace App;

use App\Traits\GenerateUuidTrait;
use App\Traits\HashPasswordTrait;
use App\Traits\UserValidationTrait;
use App\Traits\FormattingTrait;

class ServiceClass
{
    use GenerateUuidTrait;      // 1:1 auto trait name
    use HashPasswordTrait;      // 1:1 auto trait name
    use UserValidationTrait;    // N:1 explicit trait name
    use FormattingTrait;        // Pattern-based grouping

    // Core business method (should stay)
    public function processOrder(array $data): array
    {
        return $data;
    }
}

?>