<?php
// includes/language.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$langDir = __DIR__ . '/lang/';
$availableLangs = [];
if (is_dir($langDir)) {
    foreach (glob($langDir . '*.php') as $file) {
        $availableLangs[] = basename($file, '.php');
    }
}

// Prepnutie jazyka cez GET parameter (?lang=xx)
if (isset($_GET['lang']) && in_array($_GET['lang'], $availableLangs)) {
    $_SESSION['lang'] = $_GET['lang'];
}

// Fallback logika: ak session jazyk neexistuje, skús 'sk', inak prvý dostupný
$currentLang = $_SESSION['lang'] ?? '';
if (!in_array($currentLang, $availableLangs)) {
    if (in_array('sk', $availableLangs)) {
        $currentLang = 'sk';
    } elseif (!empty($availableLangs)) {
        $currentLang = $availableLangs[0];
    } else {
        $currentLang = 'en';
    }
    $_SESSION['lang'] = $currentLang;
}

$langFile = $langDir . $currentLang . '.php';
$lang = file_exists($langFile) ? require $langFile : [];

if (!function_exists('__')) {
    function __($key, $replacements = []) {
        global $lang;
        $text = $lang[$key] ?? $key;
        foreach ($replacements as $search => $replace) {
            $text = str_replace('{' . $search . '}', $replace, $text);
        }
        return $text;
    }
}
?>
