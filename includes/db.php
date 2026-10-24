<?php
// includes/db.php

// Načítanie konfiguračných údajov z priečinka cfg/
$configFile = __DIR__ . '/../cfg/db.php';
if (!file_exists($configFile)) {
    throw new \Exception("Konfiguračný súbor databázy (cfg/db.php) nebol nájdený. Spustite inštaláciu.");
}
$config = require $configFile;

$host = $config['host'] ?? '127.0.0.1';
$db   = $config['db'] ?? '';
$user = $config['user'] ?? '';
$pass = $config['pass'] ?? '';
$charset = $config['charset'] ?? 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

// --- GLOBÁLNE NASTAVENIE ČASOVEJ ZÓNY ---
$portalTimezone = 'Europe/Bratislava'; // Defaultná hodnota[cite: 7]
try {
    $stTz = $pdo->query("SELECT setting_value FROM coresync_portal_settings WHERE setting_key = 'portal_timezone' LIMIT 1");
    $fetchedTz = $stTz->fetchColumn();
    if (!empty($fetchedTz)) {
        $portalTimezone = $fetchedTz;
    }
} catch (Exception $e) {}

date_default_timezone_set($portalTimezone);

// --- GLOBÁLNA FUNKCIA NA FORMÁTOVANIE ČASU ---
if (!function_exists('formatCorrectTime')) {
    function formatCorrectTime($input, $timezoneStr = null, $isUnix = false, $format = 'd.m.Y H:i') {
        if (empty($input)) return 'Nikdy';

        global $portalTimezone;
        $tz = $timezoneStr ?: $portalTimezone;

        try {
            if ($isUnix) {
                $dt = new DateTime();
                $dt->setTimestamp((int)$input);
            } else {
                // Predpokladáme, že DB ukladá čas v UTC (štandard MySQL)[cite: 7]
                $dt = new DateTime($input, new DateTimeZone('UTC'));
            }
            $dt->setTimezone(new DateTimeZone($tz));
            return $dt->format($format);
        } catch (Exception $e) {
            return $input;
        }
    }
}
