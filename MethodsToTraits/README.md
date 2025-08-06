# Rector Plugin: Public Methods to Traits

A powerful Rector plugin that automatically extracts public methods from classes into reusable traits based on configurable patterns and grouping strategies.

## 🚀 Features

- **Smart Method Detection**: Extract methods by prefixes, suffixes, annotations, or attributes
- **Unified Configuration**: All extraction types in one `extract_patterns` configuration
- **1:1 Method Mapping**: Extract individual methods to their own traits with auto-naming
- **N:1 Method Grouping**: Group multiple specific methods into single traits
- **Intelligent Grouping**: Group related methods into cohesive traits automatically
- **Dependency Analysis**: Handle method dependencies (properties, constants, other methods)
- **File Generation**: Automatically generate trait files with proper namespacing
- **Configurable**: Highly customizable with extensive configuration options

## 📦 Installation

```bash
composer require --dev rector/rector
```

Add the plugin to your project and configure your `rector.php` file.

## 🔧 Unified Configuration

All extraction logic is now unified under `extract_patterns` with different pattern types:

### Basic Configuration

```php
<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use JDR\Rector\MethodsToTraits\MethodsToTraitsRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([__DIR__ . '/src']);

    $rectorConfig->ruleWithConfiguration(MethodsToTraitsRector::class, [
        'extract_patterns' => [
            // Pattern-based extraction (unchanged)
            ['type' => 'prefix', 'value' => 'validate'],
            ['type' => 'prefix', 'value' => 'format'],
            ['type' => 'annotation', 'value' => 'extractable'],
            
            // NEW: 1:1 method mapping (auto trait names)
            ['type' => 'methods', 'methods' => ['generateUuid']],      // → generateUuidTrait
            ['type' => 'methods', 'methods' => ['hashPassword']],      // → hashPasswordTrait
            
            // NEW: 1:1 with explicit trait name
            [
                'type' => 'methods',
                'methods' => ['sendEmail'],
                'trait_name' => 'EmailSenderTrait'
            ],
            
            // NEW: N:1 method group mapping
            [
                'type' => 'methods',
                'trait_name' => 'UserValidationTrait',
                'methods' => ['validateEmail', 'validatePhone', 'validateAge']
            ],
            [
                'type' => 'methods',
                'trait_name' => 'DataFormatterTrait',
                'methods' => ['formatCurrency', 'formatDate', 'formatName']
            ]
        ],

        'trait_namespace' => 'App\\Traits',
        'output_directory' => __DIR__ . '/src/Traits',
        'group_by' => 'functionality',
        'min_methods_per_trait' => 2,
    ]);
};
```

## 📋 Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `extract_patterns` | array | `[]` | **Unified patterns for all extraction types** |
| `trait_namespace` | string | `'App\\Traits'` | Namespace for generated traits |
| `output_directory` | string | `'src/Traits'` | Directory to generate trait files |
| `group_by` | string | `'functionality'` | Grouping strategy for pattern-based extraction |
| `min_methods_per_trait` | int | `2` | Minimum methods for pattern-based traits (skipped for explicit groups) |
| `exclude_methods` | array | `[magic methods]` | Methods to exclude from extraction |
| `preserve_visibility` | bool | `true` | Preserve method visibility |
| `add_trait_use` | bool | `true` | Add trait use statements to classes |
| `generate_files` | bool | `true` | Generate trait files |

## 🎯 Extraction Pattern Types

### 1. Pattern-Based Extraction (Unchanged)

Extract methods based on naming patterns or annotations:

```php
'extract_patterns' => [
    ['type' => 'prefix', 'value' => 'validate'],           // validate* methods
    ['type' => 'suffix', 'value' => 'Helper'],             // *Helper methods
    ['type' => 'regex', 'value' => '/^(get|set)[A-Z]/'],   // getX, setX methods
    ['type' => 'annotation', 'value' => 'extractable'],    // @extractable methods
    ['type' => 'attribute', 'value' => 'Extractable'],     // #[Extractable] methods
]
```

### 2. 1:1 Method Mapping (NEW)

Extract individual methods to their own traits:

```php
'extract_patterns' => [
    // Auto-generated trait names
    ['type' => 'methods', 'methods' => ['generateUuid']],    // → generateUuidTrait
    ['type' => 'methods', 'methods' => ['hashPassword']],    // → hashPasswordTrait
    
    // Explicit trait names
    [
        'type' => 'methods',
        'methods' => ['sendEmail'],
        'trait_name' => 'EmailSenderTrait'
    ]
]
```

### 3. N:1 Method Grouping (NEW)

Group multiple specific methods into single traits:

```php
'extract_patterns' => [
    [
        'type' => 'methods',
        'trait_name' => 'UserValidationTrait',
        'methods' => ['validateEmail', 'validatePhone', 'validateAge']
    ],
    [
        'type' => 'methods',
        'trait_name' => 'PaymentHelpersTrait',
        'methods' => ['calculateTax', 'formatAmount', 'validateCard']
    ]
]
```

## 💡 Usage Examples

### Example 1: Individual Method Extraction

**Configuration:**
```php
'extract_patterns' => [
    ['type' => 'methods', 'methods' => ['generateApiKey']],  // → generateApiKeyTrait
    ['type' => 'methods', 'methods' => ['encryptData']],     // → encryptDataTrait
]
```

**Before:**
```php
class OrderService
{
    public function generateApiKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function encryptData(string $data): string
    {
        return openssl_encrypt($data, 'AES-256-CBC', $this->key);
    }

    public function processOrder(array $data): void
    {
        // Core business logic stays
    }
}
```

**After:**
```php
class OrderService
{
    use GenerateApiKeyTrait;    // Single method trait
    use EncryptDataTrait;       // Single method trait

    public function processOrder(array $data): void
    {
        // Core business logic stays
    }
}
```

### Example 2: Method Grouping

**Configuration:**
```php
'extract_patterns' => [
    [
        'type' => 'methods',
        'trait_name' => 'StringUtilitiesTrait',
        'methods' => ['slugify', 'sanitizeHtml', 'truncateText']
    ]
]
```

**Before:**
```php
class ContentService
{
    public function slugify(string $text): string
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $text), '-'));
    }

    public function sanitizeHtml(string $html): string
    {
        return htmlspecialchars($html, ENT_QUOTES, 'UTF-8');
    }

    public function truncateText(string $text, int $length): string
    {
        return strlen($text) > $length ? substr($text, 0, $length) . '...' : $text;
    }

    public function publishContent(array $data): void
    {
        // Core business logic stays
    }
}
```

**After:**
```php
class ContentService
{
    use StringUtilitiesTrait;   // Multiple methods in one trait

    public function publishContent(array $data): void
    {
        // Core business logic stays
    }
}
```

### Example 3: Mixed Strategies

**Configuration:**
```php
'extract_patterns' => [
    // Pattern-based: All validate* methods grouped automatically
    ['type' => 'prefix', 'value' => 'validate'],
    
    // 1:1: Special methods get their own traits  
    ['type' => 'methods', 'methods' => ['generateHash']],
    
    // N:1: Group related formatting methods
    [
        'type' => 'methods',
        'trait_name' => 'DisplayFormatterTrait',
        'methods' => ['formatMoney', 'formatPercentage', 'formatFileSize']
    ]
]
```

**Before:**
```php
class UserService
{
    // Pattern-based extraction
    public function validateEmail(string $email): bool { /* ... */ }
    public function validatePhone(string $phone): bool { /* ... */ }
    
    // 1:1 extraction
    public function generateHash(string $data): string { /* ... */ }
    
    // N:1 extraction
    public function formatMoney(float $amount): string { /* ... */ }
    public function formatPercentage(float $value): string { /* ... */ }
    public function formatFileSize(int $bytes): string { /* ... */ }
    
    // Stays in class
    public function saveUser(array $data): void { /* ... */ }
}
```

**After:**
```php
class UserService
{
    use ValidationTrait;         // Pattern-based grouping
    use GenerateHashTrait;       // 1:1 extraction
    use DisplayFormatterTrait;   // N:1 explicit grouping

    public function saveUser(array $data): void { /* ... */ }
}
```

## 🔍 Configuration Validation

The plugin includes built-in validation:

```php
// ✅ Valid: Single method with auto trait name
['type' => 'methods', 'methods' => ['generateId']]

// ✅ Valid: Single method with explicit name
['type' => 'methods', 'methods' => ['generateId'], 'trait_name' => 'IdGeneratorTrait']

// ✅ Valid: Multiple methods with explicit trait name
['type' => 'methods', 'trait_name' => 'HelpersTrait', 'methods' => ['method1', 'method2']]

// ❌ Invalid: Multiple methods without explicit trait name
['type' => 'methods', 'methods' => ['method1', 'method2']]  // Throws exception

// ❌ Invalid: Empty methods array
['type' => 'methods', 'methods' => []]  // Throws exception
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
```

## 📁 Generated File Structure

```
src/
├── Traits/
│   ├── GenerateUuidTrait.php           # 1:1 extraction
│   ├── HashPasswordTrait.php           # 1:1 extraction
│   ├── UserValidationTrait.php         # N:1 grouping
│   ├── StringUtilitiesTrait.php        # N:1 grouping
│   ├── ValidationTrait.php             # Pattern-based
│   └── FormattingTrait.php             # Pattern-based
├── Services/
│   ├── UserService.php (modified)
│   └── OrderService.php (modified)
```

## 🎛️ Real-World Configuration Examples

### Legacy Code Refactoring
```php
'extract_patterns' => [
    // Extract problematic legacy methods
    [
        'type' => 'methods',
        'trait_name' => 'LegacyHelpersTrait',
        'methods' => ['legacyDateFormat', 'legacyValidation', 'legacyStringClean']
    ]
]
```

### Team-Specific Organization
```php
'extract_patterns' => [
    // User management utilities
    [
        'type' => 'methods',
        'trait_name' => 'UserUtilitiesTrait', 
        'methods' => ['hashPassword', 'generateToken', 'validateSession']
    ],
    
    // Payment processing helpers
    [
        'type' => 'methods',
        'trait_name' => 'PaymentHelpersTrait',
        'methods' => ['calculateTax', 'formatAmount', 'validateCard']
    ]
]
```

### Security-Focused Extraction
```php
'extract_patterns' => [
    // Extract security methods to dedicated traits
    ['type' => 'methods', 'methods' => ['encryptSensitiveData']],
    ['type' => 'methods', 'methods' => ['generateCsrfToken']],
    ['type' => 'methods', 'methods' => ['sanitizeUserInput']],
]
```

## ⚠️ Important Notes

1. **Configuration Validation**: Multiple methods require explicit `trait_name`
2. **Minimum Methods Filter**: Skipped for explicit method groups
3. **Dependency Handling**: Smart analysis and migration of dependencies
4. **Backup Recommended**: Always backup code before running transformations
5. **Test Thoroughly**: Run test suite after extraction

## 🚫 Limitations

- Complex method interdependencies may require manual review
- Doesn't handle dynamic method calls or reflection
- Some edge cases with complex inheritance may need adjustment

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Add tests for new functionality
4. Ensure all tests pass
5. Submit a pull request

## 📄 License

This project is licensed under the MIT License.

---

**Happy refactoring! 🎉**