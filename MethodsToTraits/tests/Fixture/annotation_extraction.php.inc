<?php

namespace tests\Fixture;

class ProductService
{
    /**
     * @extractable
     * @group utility
     */
    public function generateSku(): string
    {
        return 'SKU-' . uniqid();
    }

    /**
     * @extractable
     * @group utility
     */
    public function generateBarcode(): string
    {
        return 'BC-' . random_int(100000, 999999);
    }

    /**
     * @extractable
     * @group conversion
     */
    public function convertPrice(float $price): string
    {
        return '' . number_format($price, 2);
    }

    public function createProduct(array $data): array
    {
        return $data;
    }
}

?>
-----
<?php

namespace App;

use App\Traits\UtilityTrait;
use App\Traits\ConversionTrait;

class ProductService
{
    use UtilityTrait;
    use ConversionTrait;

    public function createProduct(array $data): array
    {
        return $data;
    }
}

?>