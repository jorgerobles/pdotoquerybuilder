<?php

namespace tests\Fixture;

class UserService
{
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

    public function saveUser(array $data): void
    {
        // Core business logic stays
    }
}

?>
-----
<?php

namespace App;

use App\Traits\ValidationTrait;

class UserService
{
    use ValidationTrait;

    public function saveUser(array $data): void
    {
        // Core business logic stays
    }
}

?>