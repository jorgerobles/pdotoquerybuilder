# Rector Plugin: PDO to Doctrine QueryBuilder

Este plugin de Rector convierte automáticamente consultas PDO a QueryBuilder de Doctrine/DBAL.

## Instalación

1. Instala las dependencias:
```bash
composer require --dev rector/rector
```

2. Copia el archivo `PdoToQueryBuilderRector.php` a tu proyecto.

3. Configura rector.php:

```php
<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use JDR\Rector\PdoToQb\PdoToQueryBuilderRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->rule(PdoToQueryBuilderRector::class);
    
    $rectorConfig->paths([
        __DIR__ . '/src',
    ]);
};
```

## Uso

Ejecuta Rector:
```bash
vendor/bin/rector process
```

### Paréntesis anidados complejos
**Antes:**
```php
$stmt = $pdo->prepare("
    SELECT * FROM products 
    WHERE (
        ((category = 'electronics' AND price > 100) 
         OR (category = 'books' AND rating > 4))
        AND NOT (discontinued = 1 OR out_of_stock = 1)
    )
    AND (supplier_id = ? OR featured = 1)
");
$stmt->execute([42]);
```

**Después:**
```php
$products = $this->connection->createQueryBuilder()
    ->select('*')
    ->from('products', 'products')
    ->where('(((category = \'electronics\' AND price > 100) OR (category = \'books\' AND rating > 4)) AND NOT (discontinued = 1 OR out_of_stock = 1))')
    ->andWhere('(supplier_id = :param1 OR featured = 1)')
    ->setParameter('param1', 42)
    ->executeQuery()
    ->fetchAllAssociative();
```

## Funcionalidades avanzadas

### Soporte completo para JOINs
- **INNER JOIN**: Convierte a `->innerJoin()`
- **LEFT JOIN**: Convierte a `->leftJoin()`
- **RIGHT JOIN**: Convierte a `->rightJoin()`
- **OUTER JOIN**: Convierte a `->join()`
- **CROSS JOIN**: Convierte a `->join()`

### Parser de condiciones WHERE
El plugin incluye un parser sofisticado que:
- Respeta la precedencia de operadores
- Maneja paréntesis anidados correctamente
- Preserva comillas en strings
- Convierte parámetros posicionales `?` a nombrados `:param1`, `:param2`, etc.
- Soporta operadores `AND`, `OR`, `NOT`

### GROUP BY y HAVING
```php
// Antes
"SELECT department, COUNT(*) FROM users GROUP BY department HAVING COUNT(*) > 5"

// Después
->select('department, COUNT(*)')
->from('users', 'users')
->addGroupBy('department')
->having('COUNT(*) > 5')
```

### ORDER BY múltiple
```php
// Antes
"SELECT * FROM users ORDER BY name ASC, created_at DESC, id"

// Después
->select('*')
->from('users', 'users')
->addOrderBy('name', 'ASC')
->addOrderBy('created_at', 'DESC')
->addOrderBy('id', 'ASC')
```

## Limitaciones actuales

- ✅ ~~No soporta JOINs complejos~~ (RESUELTO)
- ✅ ~~No soporta GROUP BY~~ (RESUELTO)
- ✅ ~~No soporta OR y NOT~~ (RESUELTO)
- ✅ ~~No soporta paréntesis anidados~~ (RESUELTO)
- ❌ No soporta subconsultas (subqueries)
- ❌ No soporta UNION/INTERSECT/EXCEPT
- ❌ No soporta funciones de ventana (window functions)
- ❌ No soporta CTEs (Common Table Expressions)
- ❌ Requiere que la variable PDO se llame `$pdo`, `$db` o `$connection`

## Casos de uso complejos soportados

### E-commerce con inventario
```php
// Query compleja de e-commerce
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
```

### Sistema CRM con permisos
```php
// Query de permisos de usuario
$stmt = $pdo->prepare("
    SELECT u.id, u.name, r.name as role, p.permission_name
    FROM users u
    INNER JOIN user_roles ur ON u.id = ur.user_id
    INNER JOIN roles r ON ur.role_id = r.id
    LEFT JOIN role_permissions rp ON r.id = rp.role_id
    LEFT JOIN permissions p ON rp.permission_id = p.id
    WHERE (
        (u.active = 1 AND u.email_verified = 1)
        OR (u.is_admin = 1 AND u.mfa_enabled = 1)
    )
    AND NOT (
        u.locked = 1 
        OR (u.last_login < '2024-01-01' AND r.require_recent_login = 1)
    )
    AND (
        r.department_id = ? 
        OR (r.cross_department = 1 AND u.manager_id = ?)
    )
    GROUP BY u.id, r.id, p.id
    ORDER BY u.name, r.priority DESC
");
```

## Configuración avanzada

### Rector configuration con reglas específicas

```php
<?php

declare(strict_types=1);

use JDR\Rector\PdoToQb\PdoToQueryBuilderRector;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    // Regla principal para convertir PDO a QueryBuilder
    $rectorConfig->rule(PdoToQueryBuilderRector::class);
    
    // Regla adicional para manejar execute() y fetch*()
    $rectorConfig->rule(PdoExecuteToQueryBuilderRector::class);
    
    $rectorConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/app',
    ]);
    
    $rectorConfig->skip([
        __DIR__ . '/tests',
        __DIR__ . '/vendor',
        // Excluir archivos específicos si es necesario
        __DIR__ . '/src/LegacyDatabase.php',
    ]);
    
    // Configuraciones adicionales
    $rectorConfig->importNames();
    $rectorConfig->removeUnusedImports();
};
```

## Ejecutar tests completos

```bash
# Tests básicos
vendor/bin/phpunit tests/PdoToQueryBuilderRectorTest.php

# Tests de JOINs
vendor/bin/phpunit tests/PdoToQueryBuilderRectorTest.php::testConvertJoinQueries

# Tests de condiciones complejas
vendor/bin/phpunit tests/PdoToQueryBuilderRectorTest.php::testConvertComplexWhereConditions

# Tests de paréntesis anidados
vendor/bin/phpunit tests/PdoToQueryBuilderRectorTest.php::testConvertNestedConditionsWithParentheses

# Todos los tests
vendor/bin/phpunit tests/
```

## Debugging y troubleshooting

### Verificar transformaciones paso a paso
```bash
# Ver qué archivos serían modificados sin aplicar cambios
vendor/bin/rector process --dry-run

# Aplicar cambios solo a un archivo específico
vendor/bin/rector process src/Repository/UserRepository.php

# Ver output detallado
vendor/bin/rector process --debug
```

### Casos problemáticos comunes

1. **Parámetros no mapeados correctamente**: El plugin convierte `?` a `:param1`, `:param2`, etc. secuencialmente.

2. **JOINs con alias complejos**: Asegúrate de que los alias estén bien definidos en la query original.

3. **Condiciones WHERE muy complejas**: Para casos extremos, revisa manualmente el código generado.

## Contribuir al plugin

Para añadir nuevas funcionalidades:

1. **Extend parsing**: Modifica `parseSelectQuery()` para nuevas cláusulas SQL
2. **Add builders**: Crea nuevos métodos `build*Query()` para tipos de query
3. **Improve WHERE parser**: Extiende `parseComplexWhereClause()` para casos edge
4. **Add tests**: Siempre añade tests correspondientes en `/Fixture/`

### Roadmap futuro
- [ ] Soporte para subconsultas (subqueries)
- [ ] Soporte para UNION queries
- [ ] Soporte para window functions
- [ ] Mejoras en el parser de expresiones complejas
- [ ] Soporte para más tipos de JOIN con condiciones complejas
- [ ] Integración con Doctrine ORM Entity queries

## Ejecutar tests

```bash
vendor/bin/phpunit tests/PdoToQueryBuilderRectorTest.php
```

## Contribuir

Para añadir nuevas transformaciones:

1. Extiende el método `buildQueryBuilderFromSql()`
2. Añade nuevos parsers para consultas complejas
3. Crea tests correspondientes

## Consideraciones

- El plugin asume que tienes una propiedad `$connection` de tipo Doctrine Connection
- Los parámetros se convierten de posicionales (?) a nombrados (:param)
- Se recomienda revisar manualmente el código transformado