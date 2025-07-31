<?php

namespace tests\Fixture;

class OrderService
{
    #[Extractable(group: 'caching')]
    public function getCachedTotal(int $orderId): ?float
    {
        return $this->cache->get("order_total_{$orderId}");
    }

    #[Extractable(group: 'caching')]
    public function setCachedTotal(int $orderId, float $total): void
    {
        $this->cache->set("order_total_{$orderId}", $total, 3600);
    }

    #[Extractable(group: 'logging')]
    public function logOrderCreated(int $orderId): void
    {
        $this->logger->info("Order {$orderId} created");
    }

    #[Extractable(group: 'logging')]
    public function logOrderUpdated(int $orderId): void
    {
        $this->logger->info("Order {$orderId} updated");
    }

    public function processOrder(array $data): array
    {
        return $data;
    }
}

?>
-----
<?php

namespace App;

use App\Traits\CachingTrait;
use App\Traits\LoggingTrait;

class OrderService
{
    use CachingTrait;
    use LoggingTrait;

    public function processOrder(array $data): array
    {
        return $data;
    }
}

?>