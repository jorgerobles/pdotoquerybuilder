# Ejemplos de Uso del Rector PDO Converter

## Instalación y Configuración

```bash
# 1. Instalar dependencias
composer install

# 2. Verificar la configuración
cat rector.php

# 3. Ejecutar en modo dry-run para ver qué cambios se aplicarían
composer run rector-dry

# 4. Ejecutar con debug para ver información detallada
composer run rector-debug
```

## Casos de Uso Soportados

### 1. SELECT Básico

**Antes:**
```php
<?php
class UserRepository 
{
    public function findActiveUsers($pdo) 
    {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE active = ?");
        $stmt->execute([1]);
        return $stmt->fetchAll();
    }
}
```

**Después:**
```php
<?php
class UserRepository 
{
    public function findActiveUsers($pdo) 
    {
        return $this->connection->createQueryBuilder()
            ->select('*')
            ->from('users', 'users')
            ->where('active = :param1')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
```

### 2. SELECT con JOINs Complejos

**Antes:**
```php
<?php
class PostRepository 
{
    public function findPostsWithCategories($pdo) 
    {
        $stmt = $pdo->prepare("
            SELECT p.title, p.content, u.name as author, c.name as category
            FROM posts p
            INNER JOIN users u ON p.user_id = u.id
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.published = ? AND u.active = ?
            ORDER BY p.created_at DESC
            LIMIT 20 OFFSET 10
        ");
        $stmt->execute([1, 1]);
        return $stmt->fetchAll();
    }
}
```

**Después:**
```php
<?php
class PostRepository 
{
    public function findPostsWithCategories($pdo) 
    {
        return $this->connection->createQueryBuilder()
            ->select('p.title, p.content, u.name as author, c.name as category')
            ->from('posts', 'p')
            ->innerJoin('p', 'users', 'u', 'p.user_id = u.id')
            ->leftJoin('p', 'categories', 'c', 'p.category_id = c.id')
            ->where('p.published = :param1')
            ->andWhere('u.active = :param2')
            ->addOrderBy('p.created_at', 'DESC')
            ->setMaxResults(20)
            ->setFirstResult(10)
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
```

### 3. INSERT con Múltiples Valores

**Antes:**
```php
<?php
class UserRepository 
{
    public function createUser($pdo, $name, $email, $age) 
    {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, age, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$name, $email, $age]);
        return $pdo->lastInsertId();
    }
}
```

**Después:**
```php
<?php
class UserRepository 
{
    public function createUser($pdo, $name, $email, $age) 
    {
        $this->connection->createQueryBuilder()
            ->insert('users')
            ->setValue('name', ':param1')
            ->setValue('email', ':param2')
            ->setValue('age', ':param3')
            ->setValue('created_at', 'NOW()')
            ->executeStatement();
        return $this->connection->lastInsertId();
    }
}
```

### 4. UPDATE con Condiciones Complejas

**Antes:**
```php
<?php
class UserRepository 
{
    public function updateUserStatus($pdo, $userId, $status, $reason) 
    {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET status = ?, 
                status_reason = ?, 
                updated_at = NOW() 
            WHERE id = ? AND active = 1
        ");
        return $stmt->execute([$status, $reason, $userId]);
    }
}
```

**Después:**
```php
<?php
class UserRepository 
{
    public function updateUserStatus($pdo, $userId, $status, $reason) 
    {
        return $this->connection->createQueryBuilder()
            ->update('users')
            ->set('status', ':param1')
            ->set('status_reason', ':param2')
            ->set('updated_at', 'NOW()')
            ->where('id = :param3')
            ->andWhere('active = 1')
            ->executeStatement();
    }
}
```

### 5. DELETE con JOINs (Multi-tabla)

**Antes:**
```php
<?php
class CleanupRepository 
{
    public function deleteOrphanedPosts($pdo) 
    {
        $stmt = $pdo->prepare("
            DELETE p FROM posts p
            LEFT JOIN users u ON p.user_id = u.id
            WHERE u.id IS NULL OR u.deleted_at IS NOT NULL
        ");
        return $stmt->execute();
    }
}
```

**Después:**
```php
<?php
class CleanupRepository 
{
    public function deleteOrphanedPosts($pdo) 
    {
        return $this->connection->createQueryBuilder()
            ->delete('posts')
            ->leftJoin('main', 'users', 'u', 'posts.user_id = u.id')
            ->where('u.id IS NULL')
            ->orWhere('u.deleted_at IS NOT NULL')
            ->executeStatement();
    }
}
```

### 6. Consultas con WHERE Complejos

**Antes:**
```php
<?php
class SearchRepository 
{
    public function searchProducts($pdo, $term, $minPrice, $maxPrice, $categoryId) 
    {
        $stmt = $pdo->prepare("
            SELECT * FROM products 
            WHERE (name LIKE ? OR description LIKE ?) 
            AND price BETWEEN ? AND ? 
            AND (category_id = ? OR ? IS NULL)
            AND (active = 1 AND stock > 0)
            ORDER BY 
                CASE WHEN featured = 1 THEN 0 ELSE 1 END,
                name ASC
        ");
        $stmt->execute(["%$term%", "%$term%", $minPrice, $maxPrice, $categoryId, $categoryId]);
        return $stmt->fetchAll();
    }
}
```

**Después:**
```php
<?php
class SearchRepository 
{
    public function searchProducts($pdo, $term, $minPrice, $maxPrice, $categoryId) 
    {
        return $this->connection->createQueryBuilder()
            ->select('*')
            ->from('products', 'products')
            ->where('(name LIKE :param1 OR description LIKE :param2)')
            ->andWhere('price BETWEEN :param3 AND :param4')
            ->andWhere('(category_id = :param5 OR :param6 IS NULL)')
            ->andWhere('(active = 1 AND stock > 0)')
            ->addOrderBy('CASE WHEN featured = 1 THEN 0 ELSE 1 END', 'ASC')
            ->addOrderBy('name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
```

## Patrones Especiales Soportados

### 1. Heredocs y Nowdocs
```php
// Antes
$sql = <<<SQL
    SELECT u.*, p.title
    FROM users u
    LEFT JOIN posts p ON u.id = p.user_id
    WHERE u.active = ?
SQL;
$stmt = $pdo->prepare($sql);

// Después - Se convierte automáticamente
```

### 2. Concatenación de Strings
```php
// Antes
$sql = "SELECT * FROM users WHERE active = 1";
$sql .= " AND created_at > ?";
$stmt = $pdo->prepare($sql);

// Después - Se convierte automáticamente
```

### 3. INSERT con SET (MySQL)
```php
// Antes
$stmt = $pdo->prepare("INSERT INTO users SET name = ?, email = ?, created_at = NOW()");

// Después
$this->connection->createQueryBuilder()
    ->insert('users')
    ->setValue('name', ':param1')
    ->setValue('email', ':param2')
    ->setValue('created_at', 'NOW()');
```

## Comandos de Testing

```bash
# Probar conversiones específicas
composer run test-select
composer run test-insert  
composer run test-update
composer run test-delete

# Probar todo
composer run test

# Verificar conversiones antes de aplicar
composer run rector-dry

# Ver debug detallado
composer run rector-debug
```

## Limitaciones Actuales

1. **Subconsultas complejas**: Pueden requerir ajuste manual
2. **Stored procedures**: No soportados automáticamente
3. **Funciones específicas de DB**: Pueden necesitar revisión
4. **Variables PHP en SQL**: Se convierten a parámetros `?`

## Próximos Pasos

Después de ejecutar el Rector:

1. Revisar las conversiones generadas
2. Ajustar imports si es necesario
3. Verificar que los parámetros sean correctos
4. Ejecutar tests para validar la funcionalidad
5. Hacer ajustes manuales en casos complejos