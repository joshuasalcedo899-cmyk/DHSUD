<?php
// config.php — PDO MySQL connection
// Update these values for your environment
$DB_HOST = '127.0.0.1';
$DB_NAME = 'dshudmail_db';
$DB_USER = 'root';
$DB_PASS = '';
$DB_CHARSET = 'utf8mb4';

// Sender contact number per department (edit these values as needed).
// Keys should match dept query/post values (emes, prls, afd, phsd, elupd, ord, hoa).
$SENDER_CONTACT_NUMBERS = [
    'default' => '0935 542 1538',
    'emes' => '0935 542 1538',
    'prls' => '0935 542 4532',
    'afd' => '(049) 501 6496',
    'phsd' => '0935 542 1538',
    'elupd' => '0935 542 1538',
    'ord' => '(049) 501 6496 / 0905 775 3519',
    'hoa' => '0935 542 1538',
];

// Sender tag per department (used in Sender Details).
$DEPARTMENT_SENDER_TAGS = [
    'default' => 'HREDRD-EMES',
    'emes' => 'HREDRD-EMES',
    'prls' => 'HREDRD-PRLS',
    'afd' => 'AFD',
    'phsd' => 'HREDRD-PHSD',
    'elupd' => 'HREDRD-ELUPD',
    'ord' => 'ORD-AMAC',
    'hoa' => 'HREDRD-HOA',
];

if (!function_exists('getDepartmentSenderTag')) {
    function getDepartmentSenderTag($departmentKey = '') {
        global $DEPARTMENT_SENDER_TAGS;
        $deptKey = strtolower(trim((string)$departmentKey));
        $map = is_array($DEPARTMENT_SENDER_TAGS) ? $DEPARTMENT_SENDER_TAGS : [];
        if ($deptKey !== '' && isset($map[$deptKey])) {
            $value = trim((string)$map[$deptKey]);
            if ($value !== '') return $value;
        }
        $default = trim((string)($map['default'] ?? ''));
        return ($default !== '' ? $default : 'HREDRD-EMES');
    }
}

if (!function_exists('normalizeDepartmentKey')) {
    function normalizeDepartmentKey($rawValue = '') {
        $deptKey = strtolower(trim((string)$rawValue));
        $allowed = ['emes', 'prls', 'afd', 'phsd', 'elupd', 'ord', 'hoa'];
        return in_array($deptKey, $allowed, true) ? $deptKey : 'emes';
    }
}

if (!function_exists('getDepartmentCodeFromKey')) {
    function getDepartmentCodeFromKey($rawValue = '') {
        $deptKey = normalizeDepartmentKey($rawValue);
        $map = [
            'emes' => 'EMES',
            'prls' => 'PRLS',
            'afd' => 'AFD',
            'phsd' => 'PHSD',
            'elupd' => 'ELUPD',
            'ord' => 'ORD',
            'hoa' => 'HOA',
        ];
        return $map[$deptKey] ?? 'EMES';
    }
}

if (!function_exists('getDepartmentKeyFromSenderTag')) {
    function getDepartmentKeyFromSenderTag($senderTag = '') {
        global $DEPARTMENT_SENDER_TAGS;

        $tag = strtoupper(trim((string)$senderTag));
        if ($tag === '') return '';

        if (preg_match('/HREDRD[-\s]*([A-Z0-9]+)/', $tag, $m)) {
            return strtolower(trim((string)($m[1] ?? '')));
        }

        $map = is_array($DEPARTMENT_SENDER_TAGS) ? $DEPARTMENT_SENDER_TAGS : [];
        foreach ($map as $deptKey => $configuredTag) {
            if ($deptKey === 'default') continue;
            if (strtoupper(trim((string)$configuredTag)) === $tag) {
                return strtolower(trim((string)$deptKey));
            }
        }

        return '';
    }
}

if (!function_exists('getSenderContactNumber')) {
    function getSenderContactNumber($departmentKey = '', $senderTag = '') {
        global $SENDER_CONTACT_NUMBERS;

        $deptKey = strtolower(trim((string)$departmentKey));
        if ($deptKey === '') {
            $deptKey = getDepartmentKeyFromSenderTag($senderTag);
        }

        $map = is_array($SENDER_CONTACT_NUMBERS) ? $SENDER_CONTACT_NUMBERS : [];
        if ($deptKey !== '' && isset($map[$deptKey])) {
            $value = trim((string)$map[$deptKey]);
            if ($value !== '') return $value;
        }

        $default = trim((string)($map['default'] ?? ''));
        return ($default !== '' ? $default : '0935 542 1538');
    }
}

$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
    // In development show error; in production, log and show generic message
    if (php_sapi_name() === 'cli' || getenv('APP_ENV') === 'development') {
        echo "DB connection failed: " . $e->getMessage();
    }
    exit;
}

if (!function_exists('dbColumnExists')) {
    function dbColumnExists($tableName, $columnName) {
        static $cache = [];
        global $pdo;

        $table = trim((string)$tableName);
        $column = trim((string)$columnName);
        if ($table === '' || $column === '') return false;

        $cacheKey = strtolower($table . '.' . $column);
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table_name
                  AND COLUMN_NAME = :column_name
            ");
            $stmt->execute([
                ':table_name' => $table,
                ':column_name' => $column,
            ]);
            $cache[$cacheKey] = ((int)$stmt->fetchColumn() > 0);
        } catch (Throwable $e) {
            $cache[$cacheKey] = false;
        }

        return $cache[$cacheKey];
    }
}

if (!function_exists('mailtrackingHasDepartmentKey')) {
    function mailtrackingHasDepartmentKey() {
        return dbColumnExists('mailtracking', 'department_key');
    }
}

if (!function_exists('mailtrackingHasUpdatedAt')) {
    function mailtrackingHasUpdatedAt() {
        return dbColumnExists('mailtracking', 'updated_at');
    }
}

if (!function_exists('archiveHasDepartmentKey')) {
    function archiveHasDepartmentKey() {
        return dbColumnExists('archive', 'department_key');
    }
}

if (!function_exists('buildMailtrackingDepartmentScope')) {
    function buildMailtrackingDepartmentScope($departmentKey = '', $tableAlias = '', $paramBase = 'department_scope') {
        $normalizedDept = normalizeDepartmentKey($departmentKey);
        $qualifiedPrefix = '';
        if (trim((string)$tableAlias) !== '') {
            $qualifiedPrefix = rtrim(trim((string)$tableAlias), '.') . '.';
        }

        if (mailtrackingHasDepartmentKey()) {
            return [
                'sql' => $qualifiedPrefix . '`department_key` = :' . $paramBase . '_key',
                'params' => [
                    ':' . $paramBase . '_key' => $normalizedDept,
                ],
            ];
        }

        $senderTag = strtoupper(trim((string)getDepartmentSenderTag($normalizedDept)));
        $deptCode = strtoupper(trim((string)getDepartmentCodeFromKey($normalizedDept)));

        return [
            'sql' => '(
                UPPER(COALESCE(' . $qualifiedPrefix . '`Sender Details`, \'\')) LIKE :' . $paramBase . '_sender
                OR UPPER(COALESCE(' . $qualifiedPrefix . '`Notice/Order Code`, \'\')) LIKE :' . $paramBase . '_notice
            )',
            'params' => [
                ':' . $paramBase . '_sender' => '%' . $senderTag . '%',
                ':' . $paramBase . '_notice' => $deptCode . '-%',
            ],
        ];
    }
}

if (!function_exists('getConfiguredDesktopPdfRoot')) {
    function dhsudEnsureWritableDir($dirPath) {
        $dir = rtrim((string)$dirPath, '\\/');
        if ($dir === '') return false;
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (!is_dir($dir)) return false;
        $probe = $dir . DIRECTORY_SEPARATOR . '.write_test_' . uniqid('', true) . '.tmp';
        $ok = (@file_put_contents($probe, 'ok') !== false);
        if ($ok && file_exists($probe)) {
            @unlink($probe);
        }
        return $ok;
    }
}

if (!function_exists('detectLocalPreferredPdfRoot')) {
    function detectLocalPreferredPdfRoot() {
        $candidates = [];

        $oneDrive = trim((string)getenv('OneDrive'));
        if ($oneDrive !== '') $candidates[] = rtrim($oneDrive, '\\/') . DIRECTORY_SEPARATOR . 'Desktop' . DIRECTORY_SEPARATOR . 'Downloaded_PDFs';

        $oneDriveConsumer = trim((string)getenv('OneDriveConsumer'));
        if ($oneDriveConsumer !== '') $candidates[] = rtrim($oneDriveConsumer, '\\/') . DIRECTORY_SEPARATOR . 'Desktop' . DIRECTORY_SEPARATOR . 'Downloaded_PDFs';

        $oneDriveCommercial = trim((string)getenv('OneDriveCommercial'));
        if ($oneDriveCommercial !== '') $candidates[] = rtrim($oneDriveCommercial, '\\/') . DIRECTORY_SEPARATOR . 'Desktop' . DIRECTORY_SEPARATOR . 'Downloaded_PDFs';

        $userProfile = trim((string)getenv('USERPROFILE'));
        if ($userProfile !== '') {
            $candidates[] = rtrim($userProfile, '\\/') . DIRECTORY_SEPARATOR . 'Desktop' . DIRECTORY_SEPARATOR . 'Downloaded_PDFs';
            $candidates[] = rtrim($userProfile, '\\/') . DIRECTORY_SEPARATOR . 'Documents' . DIRECTORY_SEPARATOR . 'Downloaded_PDFs';
            $candidates[] = rtrim($userProfile, '\\/') . DIRECTORY_SEPARATOR . 'Downloads' . DIRECTORY_SEPARATOR . 'Downloaded_PDFs';
        }

        $username = trim((string)getenv('USERNAME'));
        if ($username !== '') {
            $candidates[] = 'C:\\Users\\' . $username . '\\OneDrive\\Desktop\\Downloaded_PDFs';
            $candidates[] = 'C:\\Users\\' . $username . '\\Desktop\\Downloaded_PDFs';
            $candidates[] = 'C:\\Users\\' . $username . '\\Documents\\Downloaded_PDFs';
            $candidates[] = 'C:\\Users\\' . $username . '\\Downloads\\Downloaded_PDFs';
        }

        $publicDir = trim((string)getenv('PUBLIC'));
        if ($publicDir !== '') $candidates[] = rtrim($publicDir, '\\/') . DIRECTORY_SEPARATOR . 'Desktop' . DIRECTORY_SEPARATOR . 'Downloaded_PDFs';

        $candidates[] = 'C:\\Users\\Public\\Desktop\\Downloaded_PDFs';
        $candidates[] = __DIR__ . '/Downloaded_PDFs';
        $candidates[] = __DIR__ . '/JRS_PDFs/Downloaded_PDFs';

        $seen = [];
        foreach ($candidates as $candidateRaw) {
            $candidate = rtrim((string)$candidateRaw, '\\/');
            if ($candidate === '') continue;
            $key = strtolower($candidate);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            if (dhsudEnsureWritableDir($candidate)) {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('persistLocalRuntimePdfRootConfig')) {
    function persistLocalRuntimePdfRootConfig($preferredRoot) {
        $root = rtrim((string)$preferredRoot, '\\/');
        if ($root === '') return;

        $payload = [
            'generatedAt' => date('c'),
            'preferredPdfRoot' => $root,
        ];

        $cacheDir = __DIR__ . '/cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }
        @file_put_contents($cacheDir . '/runtime-paths.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('isInvalidSystemPdfRoot')) {
    function isInvalidSystemPdfRoot($pathValue) {
        $path = strtolower(str_replace('/', '\\', trim((string)$pathValue)));
        if ($path === '') return true;
        return (strpos($path, '\\windows\\system32\\config\\systemprofile\\') !== false);
    }
}

if (!function_exists('getConfiguredDesktopPdfRoot')) {
    function getConfiguredDesktopPdfRoot() {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $candidateFiles = [
            __DIR__ . '/cache/runtime-paths.json',
            __DIR__ . '/runtime-paths.json',
        ];

        foreach ($candidateFiles as $filePath) {
            if (!is_file($filePath)) {
                continue;
            }

            $raw = @file_get_contents($filePath);
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }

            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }

            $preferredRoot = trim((string)($data['preferredPdfRoot'] ?? ''));
            if ($preferredRoot !== '' && !isInvalidSystemPdfRoot($preferredRoot) && dhsudEnsureWritableDir($preferredRoot)) {
                $cached = rtrim($preferredRoot, '\\/');
                return $cached;
            }

            $desktopPath = trim((string)($data['desktopPath'] ?? ''));
            if ($desktopPath !== '' && !isInvalidSystemPdfRoot($desktopPath)) {
                $cached = rtrim($desktopPath, '\\/') . DIRECTORY_SEPARATOR . 'Downloaded_PDFs';
                if (dhsudEnsureWritableDir($cached)) {
                    return $cached;
                }
            }

            $documentsPath = trim((string)($data['documentsPath'] ?? ''));
            if ($documentsPath !== '' && !isInvalidSystemPdfRoot($documentsPath)) {
                $cached = rtrim($documentsPath, '\\/') . DIRECTORY_SEPARATOR . 'Downloaded_PDFs';
                if (dhsudEnsureWritableDir($cached)) {
                    return $cached;
                }
            }

            $downloadsPath = trim((string)($data['downloadsPath'] ?? ''));
            if ($downloadsPath !== '' && !isInvalidSystemPdfRoot($downloadsPath)) {
                $cached = rtrim($downloadsPath, '\\/') . DIRECTORY_SEPARATOR . 'Downloaded_PDFs';
                if (dhsudEnsureWritableDir($cached)) {
                    return $cached;
                }
            }
        }

        $detectedRoot = detectLocalPreferredPdfRoot();
        if ($detectedRoot !== '') {
            persistLocalRuntimePdfRootConfig($detectedRoot);
            $cached = $detectedRoot;
            return $cached;
        }

        $cached = '';
        return $cached;
    }
}

?>
