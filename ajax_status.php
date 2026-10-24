<?php
// ajax_status.php
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

header('Content-Type: application/json');
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/skins.php';

$activeServer = $_SESSION['active_server'] ?? '';
if (empty($activeServer) && isset($pdo)) {
    try {
        $stFirst = $pdo->query("SELECT server_name FROM coresync_servers_config WHERE is_hidden = 0 LIMIT 1");
        $activeServer = $stFirst->fetchColumn() ?: '';
    } catch (Exception $e) {}
}

$response = ['online' => false];

if (isset($pdo) && !empty($activeServer)) {
    try {
        $stStats = $pdo->prepare("SELECT * FROM coresync_server_stats WHERE server_name = ? LIMIT 1");
        $stStats->execute([$activeServer]);
        $stats = $stStats->fetch();

        if ($stats) {
            $lastUpdatedTime = strtotime(($stats['last_updated'] ?? '') . ' UTC');
            $isOnline = ($lastUpdatedTime && (time() - $lastUpdatedTime) <= 180);

            if ($isOnline) {
                $worldTime = (int)($stats['world_time'] ?? 0);
                $normalizedTime = $worldTime % 24000;
                $deg = (int)(($normalizedTime * 360) / 24000);
                $isDay = ($normalizedTime >= 0 && $normalizedTime < 12300);

                $totalMinutes = (int)($normalizedTime / 17);
                $mcHours = (int)(($totalMinutes / 60) + 6) % 24;
                $mcMinutes = (int)($totalMinutes % 60);
                $mcSeconds = (int)(($normalizedTime % 17) * (60 / 17));

                $playersList = [];
                if (!empty($stats['players_json'])) {
                    $rawPlayers = json_decode($stats['players_json'], true) ?? [];
                    foreach ($rawPlayers as $p) {
                        $pName = $p['name'] ?? 'Player';
                        $playersList[] = [
                            'name' => $pName,
                            'avatar' => getPlayerAvatar($pName, 30)
                        ];
                    }
                }

                $response = [
                    'online' => true,
                    'statusText' => 'ONLINE',
                    'statusColor' => '#4ade80',
                    'tps' => number_format((float)($stats['tps'] ?? 20.0), 2),
                    'playersOnline' => (int)($stats['online_players'] ?? 0),
                    'maxPlayers' => (int)($stats['max_players'] ?? 0),
                    'ramUsed' => round(($stats['ram_used_mb'] ?? 0) / 1024, 1),
                    'ramMax' => round(($stats['ram_max_mb'] ?? 0) / 1024, 1),
                    'worldTime' => $normalizedTime,
                    'timeName' => $isDay ? 'Deň' : 'Noc',
                    'formattedTime' => sprintf('%02d:%02d:%02d', $mcHours, $mcMinutes, $mcSeconds),
                    'celestialIcon' => $isDay ? '☀️' : '🌙',
                    'deg' => $deg,
                    'playersList' => $playersList
                ];
            } else {
                $response = [
                    'online' => false,
                    'statusText' => 'OFFLINE',
                    'statusColor' => '#ff4444',
                    'playersOnline' => 0,
                    'maxPlayers' => 0
                ];
            }
        }
    } catch (Exception $e) {}
}

echo json_encode($response);
