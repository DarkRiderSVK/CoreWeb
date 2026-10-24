<?php
// includes/auth.php - Jednotná autentifikácia pre hru aj web

if (session_status() === PHP_SESSION_NONE) {
    // Nastavenie doby platnosti session cookie na 7 dní (v sekundách: 7 * 24 * 60 * 60)
    $lifetime = 604800;

    // Nastavíme parametry predtým, ako zavoláme session_start()
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'domain' => '', // Nechaj prázdne pre aktuálnu doménu, prípadne zadaj '.tvojadomena.sk' ak to chceš pre subdomény
        'secure' => isset($_SERVER['HTTPS']), // Zapne sa automaticky, ak ideš cez HTTPS
        'httponly' => true, // Zabezpečenie proti XSS útokom
        'samesite' => 'Lax'
    ]);

    // Predĺžime aj serverový limit pre garbage collection, aby session na serveri hneď nevymazalo
    ini_set('session.gc_maxlifetime', $lifetime);

    session_start();
}

require_once 'db.php';

// Funkcia na overenie prihláseného užívateľa
function currentUser() {
    if (isset($_SESSION['network_user'])) {
        return $_SESSION['network_user'];
    }
    return null;
}

// Funkcia na spracovanie prihlásenia
function handleLogin($username, $password, $pdo) {
    $stmt = $pdo->prepare("SELECT uuid, player_name, web_password, ip_address FROM coresync_users WHERE LOWER(player_name) = LOWER(?) LIMIT 1");
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch();

    if ($user && !empty($user['web_password'])) {
        // Naše pluginy používajú SHA-256 hashovanie hesiel
        $hashedInput = hash('sha256', trim($password));

        if (hash_equals($user['web_password'], $hashedInput)) {
            $_SESSION['network_user'] = [
                'uuid' => $user['uuid'],
                'username' => $user['player_name'],
                'ip' => $user['ip_address']
            ];
            return true;
        }
    }
    return false;
}
