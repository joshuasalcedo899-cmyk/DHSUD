<?php
/**
 * EditMail Debug Utility
 * 
 * This debugger helps identify:
 * - Which columns cannot be updated
 * - Why specific updates fail
 * - Data type mismatches
 * - Database connectivity issues
 * - Permission problems
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// Allow debug access (optional: restrict by IP or session)
$debugMode = true;

if (!$debugMode && !isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$debug = [
    'timestamp' => date('Y-m-d H:i:s'),
    'checks' => [],
    'errors' => [],
    'warnings' => []
];

try {
    // ============ 1. DATABASE CONNECTION CHECK ============
    $debug['checks'][] = [
        'name' => 'Database Connection',
        'status' => 'OK',
        'details' => [
            'host' => $_ENV['DB_HOST'] ?? 'Not set',
            'database' => $_ENV['DB_NAME'] ?? 'Not set'
        ]
    ];

    // ============ 2. TABLE STRUCTURE CHECK ============
    $tableCheck = $pdo->query("DESCRIBE mailtracking")->fetchAll();
    
    $columns_info = [];
    foreach ($tableCheck as $col) {
        $columns_info[] = [
            'name' => $col['Field'],
            'type' => $col['Type'],
            'null' => $col['Null'],
            'key' => $col['Key'],
            'default' => $col['Default'],
            'extra' => $col['Extra']
        ];
    }
    
    $debug['checks'][] = [
        'name' => 'Table Structure (mailtracking)',
        'status' => 'OK',
        'column_count' => count($columns_info),
        'columns' => $columns_info
    ];

    // ============ 3. EXPECTED COLUMNS CHECK ============
    $expectedColumns = [
        'Date released to AFD' => 'date',
        'Parcel No.' => 'int',
        'Recipient Details' => 'text',
        'Parcel Details' => 'text',
        'Sender Details' => 'text',
        'File Name (PDF)' => 'text',
        'Tracking No.' => 'int',
        'Status' => 'text',
        'Transmittal Remarks/Received By' => 'text',
        'Date' => 'date',
        'Evaluator' => 'text',
        'Notice/Order Code' => 'text'
    ];
    
    $columnMissing = [];
    $columnMismatch = [];
    $columnExists = [];
    
    foreach ($expectedColumns as $colName => $expectedType) {
        $found = false;
        foreach ($columns_info as $col) {
            if ($col['name'] === $colName) {
                $found = true;
                $columnExists[$colName] = $col;
                $actualType = strtolower($col['type']);
                $typeMatch = (strpos($actualType, $expectedType) === 0);
                
                if (!$typeMatch) {
                    $columnMismatch[] = [
                        'column' => $colName,
                        'expected' => $expectedType,
                        'actual' => $col['type'],
                        'issue' => 'Type mismatch (non-critical - still updateable)',
                        'notes' => 'varchar and text are compatible for updates'
                    ];
                }
                break;
            }
        }
        if (!$found) {
            $columnMissing[] = [
                'column' => $colName,
                'expected' => $expectedType,
                'actual' => 'NOT FOUND',
                'issue' => 'CRITICAL - Column missing from table'
            ];
            $debug['errors'][] = "CRITICAL: Expected column '{$colName}' not found in mailtracking table";
        }
    }
    
    $debug['checks'][] = [
        'name' => 'Column Schema Validation',
        'status' => count($columnMissing) === 0 ? 'OK' : 'CRITICAL',
        'columns_found' => count($columnExists),
        'type_mismatches' => count($columnMismatch) > 0 ? $columnMismatch : 'None',
        'missing_columns' => count($columnMissing) > 0 ? $columnMissing : 'None'
    ];

    // ============ 4. TEST SAMPLE UPDATE ============
    $testCheck = [
        'name' => 'Test Update Capability',
        'tests' => []
    ];
    
    // Get a sample record
    $sampleResult = $pdo->query("SELECT COUNT(*) as count FROM mailtracking");
    $sampleCount = $sampleResult->fetch();
    $hasRecords = $sampleCount && $sampleCount['count'] > 0;
    
    if ($hasRecords) {
        $sampleRecord = $pdo->query("SELECT `Notice/Order Code` FROM mailtracking LIMIT 1")->fetch();
        $testNoticeCode = $sampleRecord['Notice/Order Code'];
        
        // Test each column for updateability
        foreach ($expectedColumns as $colName => $expectedType) {
            if ($colName === 'Notice/Order Code') {
                continue; // Skip primary key
            }
            
            // Check if column exists
            if (!isset($columnExists[$colName])) {
                $testCheck['tests'][] = [
                    'column' => $colName,
                    'updateable' => false,
                    'reason' => 'Column does not exist in table',
                    'severity' => 'CRITICAL'
                ];
                continue;
            }
            
            // Get the actual column info
            $colInfo = $columnExists[$colName];
            
            try {
                // Generate appropriate test value
                $testValue = generateTestValue($colInfo['type']);
                
                // Attempt UPDATE statement
                $testSql = "UPDATE mailtracking SET `$colName` = :test_val WHERE `Notice/Order Code` = :notice_code LIMIT 1";
                $testStmt = $pdo->prepare($testSql);
                
                $testResult = $testStmt->execute([
                    ':test_val' => $testValue,
                    ':notice_code' => $testNoticeCode
                ]);
                
                $testCheck['tests'][] = [
                    'column' => $colName,
                    'type' => $colInfo['type'],
                    'null_allowed' => $colInfo['null'],
                    'updateable' => true,
                    'test_value' => $testValue,
                    'rows_affected' => $testStmt->rowCount(),
                    'result' => 'SUCCESS'
                ];
                
            } catch (PDOException $e) {
                $testCheck['tests'][] = [
                    'column' => $colName,
                    'type' => $colInfo['type'],
                    'null_allowed' => $colInfo['null'],
                    'updateable' => false,
                    'error_message' => $e->getMessage(),
                    'result' => 'FAILED',
                    'severity' => 'ERROR'
                ];
                $debug['errors'][] = "Column '{$colName}' failed update test: " . $e->getMessage();
            }
        }
    } else {
        $testCheck['tests'][] = [
            'status' => 'SKIPPED',
            'reason' => 'No sample records in table to test with'
        ];
        $debug['warnings'][] = "Could not test updates - table is empty";
    }
    
    $debug['checks'][] = $testCheck;

    // ============ 5. PRIVILEGES CHECK ============
    try {
        $privCheck = $pdo->query("SHOW GRANTS FOR CURRENT_USER()")->fetchAll();
        $debug['checks'][] = [
            'name' => 'User Privileges',
            'status' => 'OK',
            'grants' => array_map(fn($row) => $row[0] ?? reset($row), $privCheck)
        ];
    } catch (Exception $e) {
        $debug['warnings'][] = "Could not retrieve privileges: " . $e->getMessage();
    }

    // ============ 6. REQUEST DATA ANALYSIS ============
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $requestAnalysis = [
            'name' => 'Request Data Analysis',
            'post_data' => $_POST,
            'analysis' => []
        ];
        
        $originalNotice = trim($_POST['original_notice_code'] ?? '');
        $newNotice = trim($_POST['Notice/Order Code'] ?? $originalNotice);
        
        $requestAnalysis['analysis'][] = [
            'field' => 'original_notice_code',
            'value' => $originalNotice,
            'empty' => empty($originalNotice)
        ];
        
        $requestAnalysis['analysis'][] = [
            'field' => 'Notice/Order Code',
            'value' => $newNotice,
            'empty' => empty($newNotice),
            'changed' => $newNotice !== $originalNotice
        ];
        
        // Analyze each field being updated
        foreach ($expectedColumns as $colName => $expectedType) {
            if ($colName === 'Notice/Order Code') continue;
            
            $postValue = $_POST[$colName] ?? null;
            if ($postValue !== null) {
                $analysis = [
                    'column' => $colName,
                    'post_key_found' => true,
                    'value' => $postValue,
                    'trimmed' => trim((string)$postValue),
                    'expected_type' => $expectedType,
                    'conversion_needed' => in_array($colName, ['Parcel No.', 'Tracking No.']) ? 'int' : 'string'
                ];
                $requestAnalysis['analysis'][] = $analysis;
            }
        }
        
        $debug['checks'][] = $requestAnalysis;
    }

    $debug['success'] = true;
    
    // Count updateable columns from tests
    $updateableCount = 0;
    $failedCount = 0;
    if (!empty($testCheck['tests'])) {
        foreach ($testCheck['tests'] as $test) {
            if (isset($test['updateable'])) {
                if ($test['updateable']) $updateableCount++;
                else $failedCount++;
            }
        }
    }
    
    $debug['summary'] = [
        'total_columns_in_table' => count($columns_info),
        'expected_columns' => count($expectedColumns),
        'columns_found' => count($columnExists),
        'columns_missing' => count($columnMissing),
        'type_mismatches_warning' => count($columnMismatch) . ' (non-critical - varchar/text are compatible)',
        'updateable_columns' => $updateableCount,
        'non_updateable_columns' => $failedCount,
        'errors_found' => count($debug['errors']),
        'warnings_found' => count($debug['warnings']),
        'overall_status' => count($debug['errors']) === 0 ? 'HEALTHY' : 'ISSUES_FOUND'
    ];

} catch (PDOException $e) {
    $debug['success'] = false;
    $debug['errors'][] = 'Database Error: ' . $e->getMessage();
    http_response_code(500);
}

echo json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

/**
 * Generate appropriate test values based on column type
 */
function generateTestValue($columnType) {
    $type = strtolower($columnType);
    
    if (strpos($type, 'int') !== false) {
        return 999;
    } elseif (strpos($type, 'date') !== false) {
        return date('Y-m-d');
    } elseif (strpos($type, 'text') !== false || strpos($type, 'varchar') !== false) {
        return 'DEBUG_TEST_' . date('YmdHis');
    } elseif (strpos($type, 'float') !== false || strpos($type, 'double') !== false) {
        return 99.99;
    } elseif (strpos($type, 'decimal') !== false) {
        return 99.99;
    }
    
    return null;
}

?>
