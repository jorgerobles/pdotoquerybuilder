<?php

namespace tests\Fixture;

class ExclusionService
{
    public function __construct()
    {
        // Constructor should not be extracted
    }

    public function __destruct()
    {
        // Destructor should not be extracted
    }

    public function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function validatePhone(string $phone): bool
    {
        return preg_match('/^\+?[1-9]\d{1,14}$/', $phone);
    }

    public function formatEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function formatPhone(string $phone): string
    {
        return preg_replace('/[^\d+]/', '', $phone);
    }
}

?>
    -----
<?php

namespace App;

use App\Traits\ValidationTrait;
use App\Traits\FormattingTrait;

class ExclusionService
{
    use ValidationTrait;
    use FormattingTrait;

    public function __construct()
    {
        // Constructor should not be extracted
    }

    public function __destruct()
    {
        // Destructor should not be extracted
    }
}

?>