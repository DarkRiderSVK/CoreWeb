<?php
// admin/ajax_generate_stats.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

@include '../includes/db.php';
@include '../includes/auth.php';
require_once '../includes/language.php';

$currentUserData = currentUser();
$currentUser = is_array($currentUserData) ? ($currentUserData['username'] ?? '') : $currentUserData;

if (empty($currentUser)) {
    echo json_encode(['success' => false, 'message' => __('login_err_invalid')]);
    exit;
}

$hasManageRewards = false;
try {
    $stPerms = $pdo->prepare("SELECT permissions FROM coresync_admins WHERE LOWER(username) = LOWER(?) LIMIT 1");
    $stPerms->execute([$currentUser]);
    $row = $stPerms->fetch();
    if ($row && !empty($row['permissions'])) {
        $decoded = json_decode($row['permissions'], true);
        if (is_array($decoded) && in_array('manageRewards', $decoded)) {
            $hasManageRewards = true;
        }
    }
} catch (Exception $e) {}

if (!$hasManageRewards) {
    echo json_encode(['success' => false, 'message' => __('admin_unauthorized_action')]);
    exit;
}

// Zistíme, pre aký server sa má generovanie vykonať (ak je poslaný v POST/GET, inak pre všetky)
$targetServer = $_REQUEST['server_name'] ?? '';

$map = [
    'minecraft:mined' => 'mined',
    'minecraft:crafted' => 'crafted',
    'minecraft:picked_up' => 'picked_up',
    'minecraft:used' => 'used',
    'minecraft:broken' => 'broken',
    'minecraft:killed' => 'killed',
    'minecraft:custom' => 'custom'
];

try {
    $cfgDir = __DIR__ . '/../cfg';
    if (!is_dir($cfgDir)) mkdir($cfgDir, 0755, true);

    $serversToProcess = [];
    if (!empty($targetServer)) {
        $serversToProcess[] = $targetServer;
    } else {
        $stSrv = $pdo->query("SELECT DISTINCT server_name FROM coresync_player_data UNION SELECT DISTINCT server_name FROM coresync_servers_config");
        $serversToProcess = $stSrv->fetchAll(PDO::FETCH_COLUMN);
    }

    $lastJourneyStats = []; // Uložíme si to pre návrat do AJAX odpovede

    foreach ($serversToProcess as $srv) {
        $journeyStats = [
            'mined' => [], 'crafted' => [], 'picked_up' => [], 'used' => [],
            'broken' => [], 'killed' => [], 'custom' => [], 'advancement' => []
        ];

        $stmt = $pdo->prepare("SELECT stats_json, advancements_json FROM coresync_player_data WHERE server_name = ?");
        $stmt->execute([$srv]);

        while ($row = $stmt->fetch()) {
            if (!empty($row['stats_json'])) {
                $data = json_decode($row['stats_json'], true);
                if (isset($data['stats']) && is_array($data['stats'])) {
                    foreach ($map as $mcKey => $ourKey) {
                        if (isset($data['stats'][$mcKey]) && is_array($data['stats'][$mcKey])) {
                            foreach ($data['stats'][$mcKey] as $statName => $value) {
                                // Bez rozdielu či ide o the_vault: alebo minecraft:, zapíše sa to
                                $journeyStats[$ourKey][] = $statName;
                            }
                        }
                    }
                }
            }

            if (!empty($row['advancements_json'])) {
                $advs = json_decode($row['advancements_json'], true);
                if (is_array($advs)) {
                    foreach ($advs as $advKey => $advData) {
                        if (strpos($advKey, 'minecraft:recipes') === false && $advKey !== 'DataVersion') {
                            $journeyStats['advancement'][] = $advKey;
                        }
                    }
                }
            }
        }

        foreach ($journeyStats as $key => $values) {
            $journeyStats[$key] = array_values(array_unique($values));
        }

        $safeServerName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $srv);
        file_put_contents($cfgDir . '/journey_stats_' . $safeServerName . '.json', json_encode($journeyStats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $lastJourneyStats = $journeyStats;
    }

    // OPRAVA: Pre správny návrat dát pošleme vygenerované pole späť pre frontend JS premennú!
    echo json_encode(['success' => true, 'message' => __('admin_stats_synced'), 'stats' => $lastJourneyStats]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => __('admin_db_error', ['error' => $e->getMessage()])]);
}
