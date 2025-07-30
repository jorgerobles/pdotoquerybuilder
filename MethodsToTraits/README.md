# Rector Plugin: Public Methods to Traits

A powerful Rector plugin that automatically extracts public methods from classes into reusable traits based on configurable patterns and grouping strategies.

## 🚀 Features

- **Smart Method Detection**: Extract methods by prefixes, suffixes, annotations, or attributes
- **Intelligent Grouping**: Group related methods into cohesive traits
- **Dependency Analysis**: Handle method dependencies (properties, constants, other methods)
- **Multiple Strategies**: Support for various extraction and grouping approaches
- **File Generation**: Automatically generate trait files with proper namespacing
- **Configurable**: Highly customizable with extensive configuration options

## 📦 Installation

```bash
composer require --dev rector/rector
```

Add the plugin to your project and configure your `rector.php` file.

## 🔧 Configuration

### Basic Configuration

```php
<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use JDR\Rector\MethodsToTraits\PublicMethodsToTraitsRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([__DIR__ . '/src']);

    $rectorConfig->ruleWithConfiguration(PublicMethodsToTraitsRector::class, [
        'extract_patterns' => [
            ['type' => 'prefix', 'value' => 'validate'],
            ['type' => 'prefix', 'value' => 'format'],
            ['type' => 'prefix', 'value' => 'calculate'],
        ],
        'trait_namespace' => 'App\\Traits',
        'output_directory' => __DIR__ . '/src/Traits',
        'group_by' => 'functionality',
        'min_methods_per_trait' => 2,
    ]);
};
```

### **NEW: Direct 1:1 Method to Trait Mapping**

```php
$rectorConfig->ruleWithConfiguration(PublicMethodsToTraitsRector::class, [
    // Enable direct mapping mode
    'use_direct_mapping' => true,
    
    // Map specific methods to specific traits (1:1 basis)
    'method_to_trait_map' => [
        'validateEmail' => 'EmailValidationTrait',
        'validatePhone' => 'PhoneValidationTrait', 
        'formatCurrency' => 'CurrencyFormatterTrait',
        'calculateTax' => 'TaxCalculatorTrait',
    ],

    'trait_namespace' => 'App\\Traits\\Specific',
    'output_directory' => __DIR__ . '/src/Traits/Specific',
]);
```

### Advanced Configuration

```php
$rectorConfig->ruleWithConfiguration(PublicMethodsToTraitsRector::class, [
    // Extraction patterns
    'extract_patterns' => [
        ['type' => 'prefix', 'value' => 'validate'],
        ['type' => 'suffix', 'value' => 'Helper'],
        ['type' => 'regex', 'value' => '/^(get|set)[A-Z].*Display$/'],
        ['type' => 'annotation', 'value' => 'extractable'],
        ['type' => 'attribute', 'value' => 'Extractable'],
    ],

    // Trait configuration
    'trait_namespace' => 'App\\Traits',
    'output_directory' => __DIR__ . '/src/Traits',

    // Grouping strategies
    'group_by' => 'functionality', // 'functionality', 'prefix', 'annotation', 'attribute'
    
    // Extraction rules
    'min_methods_per_trait' => 2,
    'exclude_methods' => ['__construct', '__destruct', 'main', 'execute'],
    
    // Behavior options
    'preserve_visibility' => true,
    'add_trait_use' => true,
    'generate_files' => true,
]);
```

## 📋 Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `extract_patterns` | array | `[]` | Patterns to match methods for extraction |
| `trait_namespace` | string | `'App\\Traits'` | Namespace for generated traits |
| `output_directory` | string | `'src/Traits'` | Directory to generate trait files |
| `group_by` | string | `'functionality'` | Grouping strategy |
| `min_methods_per_trait` | int | `2` | Minimum methods required to create a trait |
| `exclude_methods` | array | `[magic methods]` | Methods to exclude from extraction |
| `preserve_visibility` | bool | `true` | Preserve method visibility |
| `add_trait_use` | bool | `true` | Add trait use statements to classes |
| `generate_files` | bool | `true` | Generate trait files |
| **`use_direct_mapping`** | **bool** | **`false`** | **Enable 1:1 method-to-trait mapping** |
| **`method_to_trait_map`** | **array** | **`[]`** | **Direct method name to trait name mapping** |

## 🎯 Extraction Patterns

### By Prefix
```php
['type' => 'prefix', 'value' => 'validate']
```
Extracts methods starting with "validate": `validateEmail()`, `validatePhone()`

### By Suffix
```php
['type' => 'suffix', 'value' => 'Helper']
```
Extracts methods ending with "Helper": `emailHelper()`, `phoneHelper()`

### By Regex
```php
['type' => 'regex', 'value' => '/^(get|set)[A-Z].*Display$/']
```
Extracts methods matching regex: `getEmailDisplay()`, `setNameDisplay()`

### By Annotation
```php
['type' => 'annotation', 'value' => 'extractable']
```
Extracts methods with `@extractable` annotation

### By Attribute (PHP 8+)
```php
['type' => 'attribute', 'value' => 'Extractable']
```
Extracts methods with `#[Extractable]` attribute

## 📊 Grouping Strategies

### Functionality (Default)
Groups methods by detected functionality:
- **Validation**: `validate*`, `check*`, `verify*`
- **Formatting**: `format*`, `transform*`, `convert*`
- **Calculation**: `calculate*`, `compute*`, `sum*`
- **Generation**: `generate*`, `create*`, `build*`
- **Parsing**: `parse*`, `extract*`, `decode*`
- **Utility**: `get*`, `set*`, `is*`, `has*`

### Prefix
Groups methods by their common prefix:
```php
// validate* methods → ValidationTrait
// format* methods → FormattingTrait
```

### Annotation
Groups methods by `@group` annotation:
```php
/**
 * @extractable
 * @group utility
 */
public function generateId(): string { }
```

### Attribute
Groups methods by `Group` attribute:
```php
#[Extractable]
#[Group('caching')]
public function getCachedData(): array { }
```

## 💡 Usage Examples

### **NEW: Example 1 - Direct 1:1 Method Mapping**

**Configuration:**
```php
$rectorConfig->ruleWithConfiguration(PublicMethodsToTraitsRector::class, [
    'use_direct_mapping' => true,
    'method_to_trait_map' => [
        'validateEmail' => 'EmailValidationTrait',
        'formatCurrency' => 'CurrencyFormatterTrait',
        'calculateTax' => 'TaxCalculatorTrait',
    ],
]);
```

**Before:**
```php
class OrderService
{
    public function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function formatCurrency(float $amount): string
    {
        return '

**Before:**
```php
class UserService
{
    public function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function formatEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function saveUser(array $data): void
    {
        // Main business logic
    }
}
```

**After:**
```php
class UserService
{
    use ValidationTrait;
    use FormattingTrait;

    public function saveUser(array $data): void
    {
        // Main business logic
    }
}
```

### Example 2: Using Annotations

**Before:**
```php
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

    public function createProduct(array $data): Product
    {
        // Core business logic
    }
}
```

**After:**
```php
class ProductService
{
    use UtilityTrait;

    public function createProduct(array $data): Product
    {
        // Core business logic
    }
}
```

### Example 3: PHP 8 Attributes

**Before:**
```php
class OrderService
{
    #[Extractable(group: 'caching')]
    public function getCachedTotal(int $orderId): ?float
    {
        return $this->cache->get("order_total_{$orderId}");
    }

    public function processOrder(array $data): Order
    {
        // Core business logic
    }
}
```

**After:**
```php
class OrderService
{
    use CachingTrait;

    public function processOrder(array $data): Order
    {
        // Core business logic
    }
}
```

## 🔍 Dependency Handling

The plugin intelligently analyzes method dependencies:

- **Properties**: Moves required private properties to traits
- **Constants**: Moves required class constants to traits
- **Methods**: Identifies method interdependencies
- **Complex Cases**: Flags complex dependencies for manual review

**Example:**
```php
// Before
class PaymentService
{
    private const TAX_RATE = 0.21;
    private $validator;

    public function calculateTax(float $amount): float
    {
        return $amount * self::TAX_RATE; // Uses constant
    }

    public function validateCard(string $card): bool
    {
        return $this->validator->validate($card); // Uses property
    }
}

// After - Generated CalculationTrait
trait CalculationTrait
{
    private const TAX_RATE = 0.21; // Moved constant

    public function calculateTax(float $amount): float
    {
        return $amount * self::TAX_RATE;
    }
}
```

## 🏃‍♂️ Running the Plugin

```bash
# Dry run to see what would be extracted
vendor/bin/rector process --dry-run

# Apply transformations
vendor/bin/rector process

# Process specific directory
vendor/bin/rector process src/Services

# With debug output
vendor/bin/rector process --debug

# Custom config
vendor/bin/rector process --config=rector-traits.php
```

## 📁 Generated File Structure

```
src/
├── Traits/
│   ├── ValidationTrait.php
│   ├── FormattingTrait.php
│   ├── CalculationTrait.php
│   └── UtilityTrait.php
├── Services/
│   ├── UserService.php (modified)
│   └── ProductService.php (modified)
```

## 🧪 Testing

The plugin includes comprehensive test fixtures:

```bash
vendor/bin/phpunit tests/PublicMethodsToTraitsRectorTest.php
```

Test scenarios:
- ✅ Basic method extraction
- ✅ Annotation-based extraction
- ✅ Attribute-based extraction
- ✅ Dependency handling
- ✅ Complex grouping scenarios
- ✅ Edge cases and error handling

## 🎛️ Multiple Configurations

You can run multiple extraction strategies:

```php
// Strategy 1: Extract helpers
$rectorConfig->ruleWithConfiguration(PublicMethodsToTraitsRector::class, [
    'extract_patterns' => [['type' => 'suffix', 'value' => 'Helper']],
    'trait_namespace' => 'App\\Traits\\Helpers',
    'output_directory' => __DIR__ . '/src/Traits/Helpers',
]);

// Strategy 2: Extract validators  
$rectorConfig->ruleWithConfiguration(PublicMethodsToTraitsRector::class, [
    'extract_patterns' => [['type' => 'prefix', 'value' => 'validate']],
    'trait_namespace' => 'App\\Traits\\Validation',
    'output_directory' => __DIR__ . '/src/Traits/Validation',
]);
```

## ⚠️ Important Considerations

1. **Backup Your Code**: Always backup before running transformations
2. **Review Dependencies**: Check extracted traits for proper dependencies
3. **Test Thoroughly**: Run your test suite after extraction
4. **Namespace Conflicts**: Ensure trait namespaces don't conflict
5. **Method Visibility**: Consider if extracted methods should remain public

## 🚫 Limitations

- Complex method interdependencies may require manual review
- Doesn't handle dynamic method calls or reflection
- Some edge cases with complex inheritance may need adjustment
- Generated traits may need manual optimization

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Add tests for new functionality
4. Ensure all tests pass
5. Submit a pull request

## 📄 License

This project is licensed under the MIT License.

## 🔗 Related Tools

- [Rector](https://getrector.org/) - The main refactoring tool
- [PHP-Parser](https://github.com/nikic/PHP-Parser) - Used for AST manipulation
- [PHPStan](https://phpstan.org/) - Static analysis for better code quality

---

**Happy refactoring! 🎉** . number_format($amount, 2);
}

    public function calculateTax(float $amount): float
    {
        return $amount * 0.21;
    }

    public function processOrder(array $data): void
    {
        // This stays - not in mapping
    }
}
```

**After:**
```php
class OrderService
{
    use EmailValidationTrait;
    use CurrencyFormatterTrait;
    use TaxCalculatorTrait;

    public function processOrder(array $data): void
    {
        // This stays - not in mapping
    }
}

// Generated: EmailValidationTrait.php (one method per trait)
// Generated: CurrencyFormatterTrait.php (one method per trait)  
// Generated: TaxCalculatorTrait.php (one method per trait)
```

### Example 2: Basic Validation & Formatting

**Before:**
```php
class UserService
{
    public function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function formatEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function saveUser(array $data): void
    {
        // Main business logic
    }
}
```

**After:**
```php
class UserService
{
    use ValidationTrait;
    use FormattingTrait;

    public function saveUser(array $data): void
    {
        // Main business logic
    }
}
```

### Example 2: Using Annotations

**Before:**
```php
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

    public function createProduct(array $data): Product
    {
        // Core business logic
    }
}
```

**After:**
```php
class ProductService
{
    use UtilityTrait;

    public function createProduct(array $data): Product
    {
        // Core business logic
    }
}
```

### Example 3: PHP 8 Attributes

**Before:**
```php
class OrderService
{
    #[Extractable(group: 'caching')]
    public function getCachedTotal(int $orderId): ?float
    {
        return $this->cache->get("order_total_{$orderId}");
    }

    public function processOrder(array $data): Order
    {
        // Core business logic
    }
}
```

**After:**
```php
class OrderService
{
    use CachingTrait;

    public function processOrder(array $data): Order
    {
        // Core business logic
    }
}
```

## 🔍 Dependency Handling

The plugin intelligently analyzes method dependencies:

- **Properties**: Moves required private properties to traits
- **Constants**: Moves required class constants to traits
- **Methods**: Identifies method interdependencies
- **Complex Cases**: Flags complex dependencies for manual review

**Example:**
```php
// Before
class PaymentService
{
    private const TAX_RATE = 0.21;
    private $validator;

    public function calculateTax(float $amount): float
    {
        return $amount * self::TAX_RATE; // Uses constant
    }

    public function validateCard(string $card): bool
    {
        return $this->validator->validate($card); // Uses property
    }
}

// After - Generated CalculationTrait
trait CalculationTrait
{
    private const TAX_RATE = 0.21; // Moved constant

    public function calculateTax(float $amount): float
    {
        return $amount * self::TAX_RATE;
    }
}
```

## 🏃‍♂️ Running the Plugin

```bash
# Dry run to see what would be extracted
vendor/bin/rector process --dry-run

# Apply transformations
vendor/bin/rector process

# Process specific directory
vendor/bin/rector process src/Services

# With debug output
vendor/bin/rector process --debug

# Custom config
vendor/bin/rector process --config=rector-traits.php
```

## 📁 Generated File Structure

```
src/
├── Traits/
│   ├── ValidationTrait.php
│   ├── FormattingTrait.php
│   ├── CalculationTrait.php
│   └── UtilityTrait.php
├── Services/
│   ├── UserService.php (modified)
│   └── ProductService.php (modified)
```

## 🧪 Testing

The plugin includes comprehensive test fixtures:

```bash
vendor/bin/phpunit tests/PublicMethodsToTraitsRectorTest.php
```

Test scenarios:
- ✅ Basic method extraction
- ✅ Annotation-based extraction
- ✅ Attribute-based extraction
- ✅ Dependency handling
- ✅ Complex grouping scenarios
- ✅ Edge cases and error handling

## 🎛️ Multiple Configurations

You can run multiple extraction strategies:

```php
// Strategy 1: Extract helpers
$rectorConfig->ruleWithConfiguration(PublicMethodsToTraitsRector::class, [
    'extract_patterns' => [['type' => 'suffix', 'value' => 'Helper']],
    'trait_namespace' => 'App\\Traits\\Helpers',
    'output_directory' => __DIR__ . '/src/Traits/Helpers',
]);

// Strategy 2: Extract validators  
$rectorConfig->ruleWithConfiguration(PublicMethodsToTraitsRector::class, [
    'extract_patterns' => [['type' => 'prefix', 'value' => 'validate']],
    'trait_namespace' => 'App\\Traits\\Validation',
    'output_directory' => __DIR__ . '/src/Traits/Validation',
]);
```

## ⚠️ Important Considerations

1. **Backup Your Code**: Always backup before running transformations
2. **Review Dependencies**: Check extracted traits for proper dependencies
3. **Test Thoroughly**: Run your test suite after extraction
4. **Namespace Conflicts**: Ensure trait namespaces don't conflict
5. **Method Visibility**: Consider if extracted methods should remain public

## 🚫 Limitations

- Complex method interdependencies may require manual review
- Doesn't handle dynamic method calls or reflection
- Some edge cases with complex inheritance may need adjustment
- Generated traits may need manual optimization

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Add tests for new functionality
4. Ensure all tests pass
5. Submit a pull request

## 📄 License

This project is licensed under the MIT License.

## 🔗 Related Tools

- [Rector](https://getrector.org/) - The main refactoring tool
- [PHP-Parser](https://github.com/nikic/PHP-Parser) - Used for AST manipulation
- [PHPStan](https://phpstan.org/) - Static analysis for better code quality

---

**Happy refactoring! 🎉**