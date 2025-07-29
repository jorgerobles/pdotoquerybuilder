<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpMyAdmin\SqlParser\Parser;

$sql = "SELECT u.name, u.email FROM users u WHERE u.age > ? AND u.status = ?";
$parser = new Parser($sql);

if (!empty($parser->statements)) {
    $statement = $parser->statements[0];
    echo "Statement type: " . get_class($statement) . "\n";
    echo "Parsing successful!\n";
} else {
    echo "Failed to parse SQL\n";
}