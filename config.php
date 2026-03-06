<?php
// config.php — PDO MySQL connection
// Update these values for your environment
$DB_HOST = '127.0.0.1';
$DB_NAME = 'dshudmail_db';
$DB_USER = 'root';
$DB_PASS = '';
$DB_CHARSET = 'utf8mb4';

// Sender contact number per department (edit these values as needed).
// Keys should match dept query/post values (emes, prls, afd, phsd, elupd, ord).
$SENDER_CONTACT_NUMBERS = [
    'default' => '0935 542 1538',
    'emes' => '0935 542 1538',
    'prls' => '0935 542 4532',
    'afd' => '(049) 501 6496',
    'phsd' => '0935 542 1538',
    'elupd' => '0935 542 1538',
    'ord' => '(049) 501 6496 / 0905 775 3519',
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

?>
