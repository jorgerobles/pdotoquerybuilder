<?php

class SampleRepository {
    public function getProducts() {

        $pdo = new PDO('mysql:host=localhost;dbname=products', 'root', '');
        $stmt = $pdo->prepare("
            SELECT p.*, i.quantity, c.name as category_name, s.name as supplier_name
            FROM products p
            INNER JOIN categories c ON p.category_id = c.id
            LEFT JOIN inventory i ON p.id = i.product_id
            LEFT JOIN suppliers s ON p.supplier_id = s.id
            WHERE (
                (p.status = 'active' AND i.quantity > 0)
                OR (p.status = 'backorder' AND p.restock_date IS NOT NULL)
            )
            AND NOT (s.blocked = 1 OR s.credit_hold = 1)
            AND (c.featured = 1 OR p.promotion_id IS NOT NULL)
            GROUP BY p.id, c.id, s.id
            HAVING quantity > 10 OR p.min_stock_override = 1
            ORDER BY c.sort_order, p.name
            LIMIT 50
        ");

    }
}