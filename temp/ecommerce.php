<?php

class ComprehensivePdoToQueryBuilder
{
    public function doSomething()
    {
        $stmt = $this->connection()
            ->createQueryBuilder()
            ->select('p.*, i.quantity, c.name as category_name, s.name as supplier_name')
            ->from('products', 'products')
            ->innerJoin('c', 'categories', 'c', 'p.category_id = c.id')
            ->leftJoin('i', 'inventory', 'i', 'p.id = i.product_id')
            ->leftJoin('s', 'suppliers', 's', 'p.supplier_id = s.id')
            ->where('(p.status = \'active\' AND i.quantity > 0')
            ->orWhere('(p.status = \'backorder\' AND p.restock_date IS AND NULL)')
            ->andWhere('NOT ((s.blocked = 1 OR s.credit_hold = 1))')
            ->andWhere('c.featured = 1')
            ->orWhere('p.promotion_id IS')
            ->andWhere('NULL')
            ->addGroupBy('p.id')
            ->addGroupBy('c.id')
            ->addGroupBy('s.id')
            ->having('quantity > 10 OR p.min_stock_override = 1')
            ->addOrderBy('c.sort_order', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->setMaxResults(50);

        $stmt->execute(['active']);
        return $stmt->fetchAll();
    }

}