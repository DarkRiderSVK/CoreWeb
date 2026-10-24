<?php
// ajax_claim.php
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

require_once 'includes/db.php';
require_once 'includes/auth.php';

$currentUser = currentUser();
if(!$currentUser) {
    echo json_encode(['success' => false, 'message' => 'Musíš byť prihlásený.']);
    exit;
}
$uuid = $currentUser['uuid'];
$questId = (int)($_POST['quest_id'] ?? 0);

// Zistenie aktuálneho aktívneho servera priamo a bezpečne zo sessionu
$activeServer = $_SESSION['active_server'] ?? '';
if (empty($activeServer)) {
    $stFirst = $pdo->query("SELECT server_name FROM coresync_servers_config WHERE is_hidden = 0 LIMIT 1");
    $activeServer = $stFirst->fetchColumn() ?: 'global';
}

$stmtQ = $pdo->prepare("SELECT * FROM coresync_quests WHERE id = ? AND is_active = 1");
$stmtQ->execute([$questId]);
$quest = $stmtQ->fetch();

if(!$quest) {
    echo json_encode(['success' => false, 'message' => 'Úloha neexistuje alebo nie je aktívna.']);
    exit;
}

// Kontrola claimov striktne pre aktuálny server
$stmtCheck = $pdo->prepare("SELECT quest_id FROM coresync_quest_claims WHERE uuid = ? AND server_name = ?");
$stmtCheck->execute([$uuid, $activeServer]);
$claimsRaw = $stmtCheck->fetchAll(PDO::FETCH_COLUMN);
$claimedQuests = array_map('intval', $claimsRaw);

if(in_array($questId, $claimedQuests)) {
    echo json_encode(['success' => false, 'message' => 'Odmenu si už vyzdvihol.']);
    exit;
}

// 1. Ochrana: Unlock After
$unlockAfter = json_decode($quest['unlock_after_json'], true) ?? [];
foreach ($unlockAfter as $reqLock) {
    if (!in_array((int)$reqLock, $claimedQuests, true)) {
        echo json_encode(['success' => false, 'message' => 'Úloha je pre teba ešte zamknutá.']);
        exit;
    }
}

// Zistenie levelu hráča pre prípadnú podmienku (viazané na daný server)
$stmtXp = $pdo->prepare("SELECT SUM(q.xp_reward) FROM coresync_quest_claims c JOIN coresync_quests q ON c.quest_id = q.id WHERE c.uuid = ? AND c.server_name = ?");
$stmtXp->execute([$uuid, $activeServer]);
$totalXp = (int)$stmtXp->fetchColumn();
$currentLevel = floor($totalXp / 900) + 1;

// Zistenie servera pre hlasy (ak je global, použijeme aktuálny z relácie)
$serverForVotes = $quest['server_name'];
if ($serverForVotes === 'global') {
    $serverForVotes = $activeServer;
}

// Zistenie hlasov
$stmtVotes = $pdo->prepare("SELECT COUNT(id) FROM coresync_votes WHERE uuid = ? AND server_name = ?");
$stmtVotes->execute([$uuid, $serverForVotes]);
$totalVotes = (int)$stmtVotes->fetchColumn();

// 2. Ochrana: Advancements a Štatistiky pre daný server
$stmtStats = $pdo->prepare("SELECT stats_json, advancements_json FROM coresync_player_data WHERE uuid = ? AND server_name = ? LIMIT 1");
$stmtStats->execute([$uuid, $activeServer]);
$rowStats = $stmtStats->fetch();

$mcStats = json_decode($rowStats['stats_json'] ?? '{}', true)['stats'] ?? [];
$advsRaw = json_decode($rowStats['advancements_json'] ?? '{}', true) ?: [];

$playerAdvs = [];

foreach ($advsRaw as $id => $advData) {
    $playerAdvs[$id] = !empty($advData['done']) ? 1 : 0;
}

$reqs = json_decode($quest['requirements_json'], true) ?? [];

foreach($reqs as $r) {
    $category = $r['source'];
    $goal = (int)$r['goal'];

    if ($category === 'journey') {
        $curr = $currentLevel;
    } elseif ($category === 'votes') {
        $curr = $totalVotes;
    } elseif ($category === 'advancement') {
        $advKeyFull = strpos($r['stat'], ':') !== false ? $r['stat'] : 'minecraft:' . $r['stat'];
        $parts = explode(':', $r['stat']);
        $advKeyClean = end($parts);

        if (isset($advsRaw[$advKeyFull]) && !empty($advsRaw[$advKeyFull]['done'])) $curr = 1;
        elseif (isset($advsRaw[$advKeyClean]) && !empty($advsRaw[$advKeyClean]['done'])) $curr = 1;
        else $curr = 0;
    } else {
        $mcCat = str_starts_with($category, 'minecraft:') ? $category : 'minecraft:' . $category;

        $statKeyFull = strpos($r['stat'], ':') !== false ? $r['stat'] : 'minecraft:' . $r['stat'];
        $parts = explode(':', $r['stat']);
        $cleanStat = end($parts);

        if (isset($mcStats[$mcCat][$statKeyFull])) {
            $curr = (int)$mcStats[$mcCat][$statKeyFull];
        } elseif (isset($mcStats[$mcCat][$r['stat']])) {
            $curr = (int)$mcStats[$mcCat][$r['stat']];
        } else {
            $curr = (int)($mcStats[$mcCat][$cleanStat] ?? 0);
        }
    }

    if($curr < $goal) {
        echo json_encode(['success' => false, 'message' => 'Nespĺňaš podmienky pre túto výzvu.']);
        exit;
    }
}

// Zápis Claimu a vytvorenie fronty pre Javu
try {
    $pdo->beginTransaction();

    // Zápis claimu pre daný server
    $stmtInsClaim = $pdo->prepare("INSERT INTO coresync_quest_claims (uuid, quest_id, server_name) VALUES (?, ?, ?)");
    $stmtInsClaim->execute([$uuid, $questId, $activeServer]);

    $rewards = json_decode($quest['rewards_json'], true) ?? [];

    // DOPLNENÉ: Pridaný stĺpec server_name aj sem, aby plugin vedel, kde má odmenu udeliť
    $stmtInsReward = $pdo->prepare("INSERT INTO coresync_rewards (uuid, reward_command, reward_description, server_name, claimed) VALUES (?, ?, ?, ?, FALSE)");

    foreach($rewards as $rew) {
        if (!empty($rew['commands'])) {
            foreach($rew['commands'] as $cmd) {
                // Vykoná uloženie vrátane premennej $activeServer
                $stmtInsReward->execute([$uuid, $cmd, $quest['title'] . ' - ' . $rew['title'], $activeServer]);
            }
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch(Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Chyba databázy: ' . $e->getMessage()]);
}
