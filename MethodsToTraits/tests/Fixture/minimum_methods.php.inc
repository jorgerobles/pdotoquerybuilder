<?php

namespace tests\Fixture;

class MinimalService
{
    public function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function formatName(string $name): string
    {
        return ucwords(strtolower($name));
    }

    public function processData(array $data): array
    {
        return $data;
    }
}

?>
-----
<?php

namespace App;

class MinimalService
{
    // No traits added because each group has only 1 method
    // and min_methods_per_trait is 2

    public function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function formatName(string $name): string
    {
        return ucwords(strtolower($name));
    }

    public function processData(array $data): array
    {
        return $data;
    }
}

?>