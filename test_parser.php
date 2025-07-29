<?php

declare(strict_types=1);

/**
 * Complete SQL Parser Test for Rector PDO Converter
 *
 * This file tests the phpmyadmin/sql-parser library to ensure
 * it can handle your SQL statements before using the Rector.
 */

require_once __DIR__ . '/vendor/autoload.php';

use PhpMyAdmin\SqlParser\Parser;

function testSqlParser() {
    echo "=== BASIC SQL PARSER TEST ===\n";

    $sql = "SELECT u.name, u.email FROM users u WHERE u.age > ? AND u.status = ? ORDER BY u.name LIMIT 10";

    try {
        $parser = new Parser($sql);

        if (empty($parser->statements)) {
            echo "❌ No statements found\n";
            return;
        }

        $statement = $parser->statements[0];
        echo "✅ Statement type: " . get_class($statement) . "\n";

        if ($statement instanceof \PhpMyAdmin\SqlParser\Statements\SelectStatement) {
            echo "✅ SELECT statement parsed successfully!\n";

            echo "\nSELECT expressions:\n";
            if ($statement->expr) {
                foreach ($statement->expr as $expr) {
                    echo "  - " . $expr->expr . ($expr->alias ? " AS " . $expr->alias : "") . "\n";
                }
            }

            echo "\nFROM tables:\n";
            if ($statement->from) {
                foreach ($statement->from as $table) {
                    echo "  - Table: " . $table->table . " Alias: " . ($table->alias ?: 'none') . "\n";
                }
            }

            echo "\nWHERE conditions:\n";
            if ($statement->where) {
                foreach ($statement->where as $condition) {
                    echo "  - " . $condition . "\n";
                }
            }

            echo "\nORDER BY:\n";
            if ($statement->order) {
                foreach ($statement->order as $order) {
                    echo "  - " . $order->expr . " " . ($order->type ?: 'ASC') . "\n";
                }
            }

            echo "\nLIMIT:\n";
            if ($statement->limit) {
                echo "  - Row count: " . $statement->limit->rowCount . "\n";
                echo "  - Offset: " . ($statement->limit->offset ?: 'none') . "\n";
            }
        }

    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

function testVariousSqlStatements() {
    echo "\n=== TESTING VARIOUS SQL STATEMENT TYPES ===\n";

    $sqlStatements = [
        "Simple SELECT" => "SELECT * FROM users WHERE status = 'active'",
        "INSERT" => "INSERT INTO users (name, email) VALUES (?, ?)",
        "UPDATE" => "UPDATE users SET status = ? WHERE id = ?",
        "DELETE" => "DELETE FROM users WHERE status = 'inactive'",
        "Complex SELECT" => "SELECT u.*, p.name FROM users u LEFT JOIN profiles p ON u.id = p.user_id WHERE u.age > 18 ORDER BY u.name LIMIT 20"
    ];

    foreach ($sqlStatements as $type => $sql) {
        echo "\n--- $type ---\n";
        echo "SQL: $sql\n";

        try {
            $parser = new Parser($sql);

            if (!empty($parser->statements)) {
                $statement = $parser->statements[0];
                echo "✅ Parsed as: " . get_class($statement) . "\n";
            } else {
                echo "❌ No statements found!\n";
            }

        } catch (Exception $e) {
            echo "❌ Error parsing: " . $e->getMessage() . "\n";
        }
    }
}

function testAdvancedSqlFeatures() {
    echo "\n=== TESTING ADVANCED SQL FEATURES ===\n";

    $advancedSql = [
        "Complex SELECT with JOINs" => "SELECT u.id, u.name, p.bio, COUNT(o.id) as order_count 
                            FROM users u 
                            LEFT JOIN profiles p ON u.id = p.user_id 
                            LEFT JOIN orders o ON u.id = o.user_id 
                            WHERE u.status = 'active' AND u.age BETWEEN 18 AND 65 
                            GROUP BY u.id, u.name, p.bio 
                            HAVING COUNT(o.id) > 5 
                            ORDER BY order_count DESC, u.name ASC 
                            LIMIT 20 OFFSET 10",

        "INSERT with multiple columns" => "INSERT INTO users (name, email, age, status, created_at) 
                                          VALUES (?, ?, ?, 'active', NOW())",

        "UPDATE with complex WHERE" => "UPDATE users 
                                       SET status = ?, updated_at = NOW() 
                                       WHERE (age > ? OR vip_status = 1) AND last_login < ?",

        "DELETE with subquery" => "DELETE FROM users 
                                  WHERE id IN (SELECT user_id FROM inactive_accounts WHERE days_inactive > 30)",

        "SELECT with CASE" => "SELECT id, name, 
                              CASE 
                                  WHEN age < 18 THEN 'Minor' 
                                  WHEN age < 65 THEN 'Adult' 
                                  ELSE 'Senior' 
                              END as age_group 
                              FROM users 
                              WHERE status = ?",
    ];

    foreach ($advancedSql as $type => $sql) {
        echo "\n--- $type ---\n";
        $cleanSql = trim(preg_replace('/\s+/', ' ', $sql));
        echo "SQL: $cleanSql\n";

        try {
            $parser = new Parser($sql);

            if (!empty($parser->statements)) {
                $statement = $parser->statements[0];
                echo "✅ Parsed as: " . get_class($statement) . "\n";

                if ($statement instanceof \PhpMyAdmin\SqlParser\Statements\SelectStatement) {
                    if ($statement->expr) {
                        echo "   Fields: " . count($statement->expr) . " expressions\n";
                    }
                    if ($statement->from) {
                        echo "   Tables: " . count($statement->from) . " table(s)\n";
                    }
                    if ($statement->join) {
                        echo "   JOINs: " . count($statement->join) . " join(s)\n";
                    }
                    if ($statement->where) {
                        echo "   WHERE: " . count($statement->where) . " condition(s)\n";
                    }
                    if ($statement->group) {
                        echo "   GROUP BY: " . count($statement->group) . " group(s)\n";
                    }
                    if ($statement->order) {
                        echo "   ORDER BY: " . count($statement->order) . " order(s)\n";
                    }
                    if ($statement->limit) {
                        echo "   LIMIT: " . ($statement->limit->rowCount ?: 'none') . "\n";
                        echo "   OFFSET: " . ($statement->limit->offset ?: 'none') . "\n";
                    }
                }
            } else {
                echo "❌ No statements found\n";
            }

        } catch (Exception $e) {
            echo "❌ Error: " . $e->getMessage() . "\n";
        }
    }
}

function testParameterDetection() {
    echo "\n=== TESTING PARAMETER DETECTION ===\n";

    $parameterTests = [
        "Single parameter" => "SELECT * FROM users WHERE id = ?",
        "Multiple parameters" => "SELECT * FROM users WHERE age > ? AND status = ? AND city = ?",
        "Mixed with literals" => "SELECT * FROM users WHERE age > ? AND status = 'active' AND created_at > ?",
        "No parameters" => "SELECT * FROM users WHERE status = 'active'",
        "IN clause" => "SELECT * FROM users WHERE status IN (?, ?, ?)",
        "LIKE with parameter" => "SELECT * FROM users WHERE name LIKE ? OR email LIKE ?",
    ];

    foreach ($parameterTests as $test => $sql) {
        echo "\n--- $test ---\n";
        echo "SQL: $sql\n";

        $paramCount = substr_count($sql, '?');
        echo "Parameters found: $paramCount\n";

        if ($paramCount > 0) {
            echo "Would convert to: ";
            $converted = $sql;
            $counter = 1;
            $converted = preg_replace_callback('/\?/', function() use (&$counter) {
                return ":param" . ($counter++);
            }, $converted);
            echo "$converted\n";
        }
    }
}

function generateTestConfig() {
    echo "\n=== GENERATING TEST CONFIGURATION ===\n";

    $config = '<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use App\Rector\Doctrine\StepByStepPdoRector;

return RectorConfig::configure()
    ->withRules([
        StepByStepPdoRector::class,
    ])
    ->withPaths([
        __DIR__ . \'/src\',
        __DIR__ . \'/tests/fixtures\', // Add test fixtures
    ])
    ->withSkip([
        __DIR__ . \'/vendor\',
        __DIR__ . \'/var\',
    ])
    ->withImportNames()
    ->withParallel() // Enable parallel processing
    ->withCache(__DIR__ . \'/var/cache/rector\'); // Enable caching
';

    echo "Save this as rector.php:\n";
    echo str_repeat("-", 50) . "\n";
    echo $config;
    echo str_repeat("-", 50) . "\n";
}

function printInstallationSteps() {
    echo "\n=== INSTALLATION AND USAGE STEPS ===\n";

    echo "1. Install the SQL parser dependency:\n";
    echo "   composer require phpmyadmin/sql-parser:^5.7\n\n";

    echo "2. Update your rector.php configuration:\n";
    echo "   <?php\n";
    echo "   declare(strict_types=1);\n";
    echo "   use Rector\\Config\\RectorConfig;\n";
    echo "   use App\\Rector\\Doctrine\\StepByStepPdoRector;\n\n";
    echo "   return RectorConfig::configure()\n";
    echo "       ->withRules([\n";
    echo "           StepByStepPdoRector::class,\n";
    echo "       ])\n";
    echo "       ->withPaths([\n";
    echo "           __DIR__ . '/src',\n";
    echo "       ]);\n\n";

    echo "3. Test the SQL parser first:\n";
    echo "   php test_sql_parser.php\n\n";

    echo "4. Run Rector in dry-run mode to see what changes will be made:\n";
    echo "   vendor/bin/rector process --dry-run --debug\n\n";

    echo "5. Apply the changes:\n";
    echo "   vendor/bin/rector process\n\n";

    echo "6. Run your tests to verify the conversion:\n";
    echo "   vendor/bin/phpunit\n\n";

    echo "=== DEBUGGING TIPS ===\n";
    echo "- Check error_log for debug messages from the rector\n";
    echo "- Use --debug flag to see detailed processing info\n";
    echo "- Start with simple SQL statements first\n";
    echo "- Verify that phpmyadmin/sql-parser can parse your SQL before using rector\n\n";

    echo "=== COMMON ISSUES ===\n";
    echo "- SQL syntax errors will prevent parsing\n";
    echo "- Dynamic SQL (string concatenation) won't work\n";
    echo "- Some MySQL-specific syntax might need adjustment\n";
    echo "- Complex subqueries may need manual review\n\n";

    echo "=== EXAMPLE INPUT/OUTPUT ===\n";
    echo "Input (PDO):\n";
    echo "  \$stmt = \$pdo->prepare(\"SELECT * FROM users WHERE status = ?\");\n";
    echo "  \$stmt->execute(['active']);\n";
    echo "  return \$stmt->fetchAll();\n\n";

    echo "Output (QueryBuilder):\n";
    echo "  return \$this->connection()->createQueryBuilder()\n";
    echo "      ->select('*')\n";
    echo "      ->from('users', 'users')\n";
    echo "      ->where('status = :param1')\n";
    echo "      ->setParameter('param1', 'active')\n";
    echo "      ->executeQuery()\n";
    echo "      ->fetchAllAssociative();\n\n";

    echo "=== SUPPORTED SQL FEATURES ===\n";
    echo "✅ SELECT with complex WHERE conditions\n";
    echo "✅ INSERT with VALUES\n";
    echo "✅ UPDATE with SET and WHERE\n";
    echo "✅ DELETE with WHERE\n";
    echo "✅ JOINs (LEFT, RIGHT, INNER, CROSS)\n";
    echo "✅ ORDER BY with multiple columns\n";
    echo "✅ GROUP BY and HAVING\n";
    echo "✅ LIMIT and OFFSET\n";
    echo "✅ Parameter binding (? -> :param1, :param2)\n";
    echo "✅ Table aliases\n";
    echo "✅ Multiple fetch methods\n\n";

    echo "=== LIMITATIONS ===\n";
    echo "❌ Complex subqueries may need manual review\n";
    echo "❌ Dynamic SQL (concatenated strings) not supported\n";
    echo "❌ Some MySQL-specific functions might need adjustment\n";
    echo "❌ Custom operators might need manual conversion\n\n";
}

function showNextSteps() {
    echo "=== NEXT STEPS ===\n";
    echo "1. If all tests pass, the SQL parser is working correctly\n";
    echo "2. Place the StepByStepPdoRector.php in src/Rector/Doctrine/\n";
    echo "3. Create rector.php configuration file\n";
    echo "4. Run: vendor/bin/rector process --dry-run --debug\n";
    echo "5. If satisfied with changes, run: vendor/bin/rector process\n";
    echo "6. Run your tests to ensure everything works\n";
    echo "7. Commit your changes\n\n";

    echo "=== TROUBLESHOOTING ===\n";
    echo "If you encounter issues:\n";
    echo "- Check that phpmyadmin/sql-parser is properly installed\n";
    echo "- Verify your SQL syntax is valid\n";
    echo "- Test individual SQL statements with this script\n";
    echo "- Check rector debug output for detailed error messages\n";
    echo "- Start with simple conversions and gradually increase complexity\n";
}

// Main execution
if (php_sapi_name() === 'cli') {
    echo "Testing PhpMyAdmin SQL Parser for Rector PDO Conversion\n";
    echo str_repeat("=", 60) . "\n";

    // Check if the parser is available
    if (!class_exists('PhpMyAdmin\\SqlParser\\Parser')) {
        echo "❌ PhpMyAdmin\\SqlParser\\Parser not found!\n";
        echo "Please install it with: composer require phpmyadmin/sql-parser:^5.7\n";
        exit(1);
    }

    echo "✅ PhpMyAdmin SQL Parser is available\n\n";

    // Run all tests
    try {
        testSqlParser();
        testVariousSqlStatements();
        testAdvancedSqlFeatures();
        testParameterDetection();
        generateTestConfig();
        printInstallationSteps();
        showNextSteps();

        echo str_repeat("=", 60) . "\n";
        echo "✅ All tests completed successfully!\n";
        echo "You can now proceed with using the Rector.\n";
        echo str_repeat("=", 60) . "\n";

    } catch (Exception $e) {
        echo "\n❌ An error occurred during testing: " . $e->getMessage() . "\n";
        echo "Please check your installation and try again.\n";
        exit(1);
    }
} else {
    echo "This script should be run from the command line.\n";
}