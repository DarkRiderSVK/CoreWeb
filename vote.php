<?php
// vote.php - Hlasovacia stránka pre hráčov (Server Votifier ALEbo Globálne stránky)
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

require_once 'includes/db.php';
require_once 'includes/skins.php';
require_once 'includes/auth.php';
require_once 'includes/language.php';

if (isset($_GET['action']) && $_GET['action'] === 'reset') {
    unset($_SESSION['voting_nick']);
    header("Location: vote.php");
    exit;
}

if (isset($_POST['voting_nick']) && !empty(trim($_POST['voting_nick']))) {
    $_SESSION['voting_nick'] = trim($_POST['voting_nick']);
    header("Location: vote.php");
    exit;
}

$pageTitle = __('vote_page_title');
require_once 'includes/header.php'; // Toto zabezpečí $activeServer a $activeWebModules

// 1. Zistenie, či má aktuálny server zapnutý CoreVotifier web addon
$hasServerVotifier = in_array('corevotifier', $activeWebModules ?? []);

// 2. Zistenie, či existuje aspoň jedna aktívna globálna hlasovacia stránka v databáze
$hasGlobalSites = false;
if (isset($pdo)) {
    try {
        $stGlobal = $pdo->query("SELECT COUNT(*) FROM coresync_vote_sites WHERE is_active = 1 AND server_name = 'global'");
        if ((int)$stGlobal->fetchColumn() > 0) {
            $hasGlobalSites = true;
        }
    } catch (Exception $e) {}
}

$isVoteAllowed = $hasServerVotifier || $hasGlobalSites;

// Načítanie údajov prihláseného hráča
$currentUserData = currentUser();
$loggedUsername = '';
if (is_array($currentUserData)) {
    $loggedUsername = $currentUserData['username'] ?? '';
} elseif (is_string($currentUserData)) {
    $loggedUsername = $currentUserData;
}

$activeNick = !empty($loggedUsername) ? $loggedUsername : ($_SESSION['voting_nick'] ?? '');

// Načítanie hlasovacích stránok, odmien a TOP hlasujúcich (Case-insensitive per server)
$voteSites = [];
$voteRewards = [];
$topVoters = [];
$playerVotesCount = 0;
$playerUuid = '';

if ($isVoteAllowed && isset($pdo)) {
    try {
        $stSrvCheck = $pdo->prepare("SELECT server_name FROM coresync_servers_config WHERE LOWER(server_name) = LOWER(?) LIMIT 1");
        $stSrvCheck->execute([$activeServer]);
        $realServerName = $stSrvCheck->fetchColumn() ?: $activeServer;

        $stSites = $pdo->prepare("SELECT * FROM coresync_vote_sites WHERE is_active = 1 AND (LOWER(server_name) = LOWER(?) OR server_name = 'global') ORDER BY sort_order ASC, id DESC");
        $stSites->execute([$realServerName]);
        $voteSites = $stSites->fetchAll();

        $stRew = $pdo->prepare("SELECT * FROM coresync_vote_rewards WHERE (LOWER(server_name) = LOWER(?) OR server_name = 'global') ORDER BY sort_order ASC, id DESC");
        $stRew->execute([$realServerName]);
        $voteRewards = $stRew->fetchAll();

        // TOP HLASUJÚCI STRICTLY PER SERVER
        $stTop = $pdo->prepare("
            SELECT
                v.uuid,
                COALESCE(NULLIF(u.player_name, ''), v.uuid) as player_name,
                COUNT(v.id) as vote_count
            FROM coresync_votes v
            LEFT JOIN coresync_users u ON v.uuid = u.uuid
            WHERE LOWER(v.server_name) = LOWER(?)
            GROUP BY v.uuid, player_name
            ORDER BY vote_count DESC, MAX(v.vote_time) ASC
            LIMIT 10
        ");
        $stTop->execute([$realServerName]);
        $topVoters = $stTop->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($activeNick)) {
            $stU = $pdo->prepare("SELECT uuid FROM coresync_users WHERE LOWER(player_name) = LOWER(?) LIMIT 1");
            $stU->execute([$activeNick]);
            $playerUuid = $stU->fetchColumn() ?: '';

            if (!empty($playerUuid)) {
                $stV = $pdo->prepare("SELECT COUNT(*) FROM coresync_votes WHERE uuid = ? AND LOWER(server_name) = LOWER(?)");
                $stV->execute([$playerUuid, $realServerName]);
                $playerVotesCount = (int)$stV->fetchColumn();
            }
        }
    } catch (Exception $e) {}
}
?>

<style>
    .vote-container, .vote-container * {
        box-sizing: border-box;
    }

    .vote-container { max-width: 1200px; margin: 40px auto 80px auto; padding: 0 20px; position: relative; }

    .floating-items { position: absolute; inset: 0; pointer-events: none; overflow: hidden; z-index: 0; }
    .floating-item { position: absolute; font-size: 40px; animation: floatItem 6s ease-in-out infinite; opacity: 0.25; filter: drop-shadow(0 0 10px var(--primary-color)); }
    .item-1 { top: 5%; left: 3%; animation-delay: 0s; }
    .item-2 { top: 25%; right: 5%; animation-delay: 1.5s; font-size: 50px; }
    .item-3 { bottom: 40%; left: 8%; animation-delay: 3s; font-size: 45px; }
    .item-4 { bottom: 10%; right: 10%; animation-delay: 4.5s; }

    @keyframes floatItem {
        0% { transform: translateY(0px) rotate(0deg); }
        50% { transform: translateY(-15px) rotate(10deg); }
        100% { transform: translateY(0px) rotate(0deg); }
    }

    .vote-hero {
        position: relative;
        background: linear-gradient(135deg, color-mix(in srgb, var(--primary-color) 15%, transparent) 0%, rgba(12, 12, 12, 0.9) 100%);
        border: 1px solid var(--border-color, rgba(255,255,255,0.08));
        border-radius: 24px;
        padding: 50px;
        margin-bottom: 40px;
        backdrop-filter: blur(15px);
        text-align: center;
        box-shadow: 0 20px 40px rgba(0,0,0,0.6);
        z-index: 1;
    }

    .player-vote-card {
        background: rgba(15, 15, 15, 0.85);
        border: 1px solid color-mix(in srgb, var(--primary-color) 40%, transparent);
        border-radius: 20px;
        padding: 30px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 20px;
        margin-bottom: 40px;
        backdrop-filter: blur(10px);
        box-shadow: 0 15px 35px rgba(0,0,0,0.5);
        z-index: 1;
        position: relative;
    }

    .player-head-large {
        width: 80px;
        height: 80px;
        border-radius: 16px;
        border: 3px solid var(--primary-color);
        box-shadow: 0 0 20px color-mix(in srgb, var(--primary-color) 60%, transparent);
        image-rendering: pixelated;
        background: rgba(0,0,0,0.5);
    }

    .rewards-showcase { margin-bottom: 50px; z-index: 1; position: relative; }
    .rewards-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px; margin-top: 20px; }
    .reward-showcase-card {
        background: rgba(12, 12, 12, 0.75);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 18px;
        padding: 25px;
        text-align: center;
        position: relative;
        overflow: hidden;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        backdrop-filter: blur(10px);
        box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    }
    .reward-showcase-card:hover {
        transform: translateY(-8px) scale(1.02);
        border-color: var(--primary-color);
        box-shadow: 0 15px 40px color-mix(in srgb, var(--primary-color) 30%, transparent);
        background: rgba(18, 18, 18, 0.85);
    }
    .reward-img-container {
        width: 70px;
        height: 70px;
        margin: 0 auto 15px auto;
        background: radial-gradient(circle, color-mix(in srgb, var(--primary-color) 20% , transparent) 0%, rgba(0,0,0,0.6) 100%);
        border: 2px solid color-mix(in srgb, var(--primary-color) 40%, transparent);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        animation: pulseReward 3s ease-in-out infinite;
    }
    .reward-item-image {
        width: 40px;
        height: 40px;
        object-fit: contain;
        image-rendering: pixelated;
        animation: floatReward 4s ease-in-out infinite;
        filter: drop-shadow(0 5px 10px rgba(0,0,0,0.6));
    }

    @keyframes floatReward {
        0%, 100% { transform: translateY(0px); }
        50% { transform: translateY(-6px); }
    }
    @keyframes pulseReward {
        0%, 100% { box-shadow: 0 0 10px color-mix(in srgb, var(--primary-color) 20%, transparent); }
        50% { box-shadow: 0 0 25px color-mix(in srgb, var(--primary-color) 60%, transparent); }
    }

    .vote-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 25px; z-index: 1; position: relative; margin-bottom: 50px; }
    .vote-card {
        background: rgba(12, 12, 12, 0.8);
        border: 1px solid var(--border-color, rgba(255,255,255,0.08));
        border-radius: 20px;
        padding: 30px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        transition: all 0.3s ease;
        backdrop-filter: blur(12px);
        box-shadow: 0 10px 30px rgba(0,0,0,0.4);
    }
    .vote-card:hover {
        transform: translateY(-8px);
        border-color: var(--primary-color);
        box-shadow: 0 15px 35px color-mix(in srgb, var(--primary-color) 25%, transparent);
    }

    .vote-banner { width: 100%; height: 90px; object-fit: cover; border-radius: 12px; margin-bottom: 20px; border: 1px solid rgba(255,255,255,0.1); background: rgba(0,0,0,0.4); }

    .btn-vote {
        background: var(--primary-color);
        color: #000;
        border: none;
        padding: 14px 25px;
        border-radius: 12px;
        font-weight: 900;
        text-transform: uppercase;
        font-size: 14px;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        transition: 0.2s;
        box-shadow: 0 5px 15px color-mix(in srgb, var(--primary-color) 40%, transparent);
    }
    .btn-vote:hover {
        background: #fff;
        color: #000;
        box-shadow: 0 0 20px rgba(255,255,255,0.6);
        transform: scale(1.02);
    }
    .btn-vote.disabled {
        background: rgba(255,255,255,0.05);
        color: #666;
        cursor: not-allowed;
        box-shadow: none;
        pointer-events: none;
    }

    /* TOP VIVTERS PODIUM & LIST DESIGN */
    .top-voters-section {
        background: rgba(12, 12, 14, 0.85);
        border: 1px solid var(--border-color, rgba(255,255,255,0.08));
        border-radius: 24px;
        padding: 40px;
        backdrop-filter: blur(15px);
        box-shadow: 0 20px 40px rgba(0,0,0,0.6);
        z-index: 1;
        position: relative;
    }

    .podium-container {
        display: flex;
        align-items: flex-end;
        justify-content: center;
        gap: 20px;
        margin-bottom: 40px;
        flex-wrap: wrap;
    }

    .podium-card {
        background: rgba(20, 20, 25, 0.7);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 20px;
        padding: 25px 20px;
        text-align: center;
        position: relative;
        flex: 1;
        min-width: 220px;
        max-width: 280px;
        transition: transform 0.3s ease;
        backdrop-filter: blur(10px);
    }
    .podium-card:hover { transform: translateY(-8px); }

    /* 1. Miesto - Zlato */
    .podium-card.rank-1 {
        order: 1;
        background: linear-gradient(180deg, rgba(245, 158, 11, 0.15) 0%, rgba(20, 20, 25, 0.85) 100%);
        border-color: rgba(245, 158, 11, 0.5);
        box-shadow: 0 15px 35px rgba(245, 158, 11, 0.2);
        transform: scale(1.05);
        z-index: 3;
    }
    /* 2. Miesto - Striebro */
    .podium-card.rank-2 {
        order: 0;
        background: linear-gradient(180deg, rgba(148, 163, 184, 0.15) 0%, rgba(20, 20, 25, 0.85) 100%);
        border-color: rgba(148, 163, 184, 0.4);
        box-shadow: 0 15px 35px rgba(148, 163, 184, 0.15);
        z-index: 2;
    }
    /* 3. Miesto - Bronz */
    .podium-card.rank-3 {
        order: 2;
        background: linear-gradient(180deg, rgba(217, 119, 6, 0.15) 0%, rgba(20, 20, 25, 0.85) 100%);
        border-color: rgba(217, 119, 6, 0.4);
        box-shadow: 0 15px 35px rgba(217, 119, 6, 0.15);
        z-index: 1;
    }

    .podium-badge {
        position: absolute;
        top: -15px;
        left: 50%;
        transform: translateX(-50%);
        padding: 4px 14px;
        border-radius: 20px;
        font-weight: 900;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 1px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.5);
    }
    .rank-1 .podium-badge { background: #f59e0b; color: #000; }
    .rank-2 .podium-badge { background: #94a3b8; color: #000; }
    .rank-3 .podium-badge { background: #d97706; color: #fff; }

    .podium-avatar {
        width: 75px;
        height: 75px;
        border-radius: 14px;
        margin: 10px auto 12px auto;
        image-rendering: pixelated;
        border: 2px solid rgba(255,255,255,0.2);
        background: rgba(0,0,0,0.4);
    }
    .rank-1 .podium-avatar { width: 90px; height: 90px; border-color: #f59e0b; box-shadow: 0 0 20px rgba(245, 158, 11, 0.5); }
    .rank-2 .podium-avatar { border-color: #94a3b8; }
    .rank-3 .podium-avatar { border-color: #d97706; }

    .other-voters-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
        border-top: 1px solid rgba(255,255,255,0.06);
        padding-top: 25px;
    }

    .voter-row {
        background: rgba(255, 255, 255, 0.02);
        border: 1px solid rgba(255, 255, 255, 0.05);
        border-radius: 14px;
        padding: 12px 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        transition: 0.2s;
    }
    .voter-row:hover { background: rgba(255, 255, 255, 0.05); border-color: rgba(255,255,255,0.1); }

    .disabled-notice {
        background: rgba(255, 68, 68, 0.1);
        border: 1px solid rgba(255, 68, 68, 0.3);
        color: #ff4444;
        padding: 40px;
        border-radius: 20px;
        text-align: center;
        backdrop-filter: blur(10px);
    }

    @media(max-width: 768px) {
        .vote-hero { padding: 30px 20px; }
        .player-vote-card { padding: 20px; flex-direction: column; text-align: center; }
        .player-vote-card > div { justify-content: center; }
        .player-vote-card form { flex-direction: column; width: 100%; }
        .player-vote-card form input { width: 100%; }

        .top-voters-section { padding: 25px 15px; }
        .podium-container { flex-direction: column; align-items: center; gap: 20px; }
        .podium-card.rank-1 { order: 0; transform: none; }
        .podium-card.rank-2 { order: 1; }
        .podium-card.rank-3 { order: 2; }

        /* Ochrana pred rozbitím šírky na mobile a lepšie prispôsobenie dizajnu */
        .podium-card {
            width: 100%;
            max-width: 280px;
            min-width: 0;
            padding: 20px 15px;
        }

        .vote-grid { grid-template-columns: 1fr; gap: 15px; }
    }
</style>

<div class="vote-container">
    <div class="floating-items">
        <span class="floating-item item-1">💎</span>
        <span class="floating-item item-2">⚔️</span>
        <span class="floating-item item-3">🍎</span>
        <span class="floating-item item-4">📦</span>
    </div>

    <div class="vote-hero">
        <h1 style="font-size: 42px; font-weight: 900; text-transform: uppercase; margin: 0 0 15px 0;"><?= __('vote_main_title') ?></h1>
        <p style="color: #aaa; font-size: 16px; margin: 0; line-height: 1.6;"><?= __('vote_main_desc') ?></p>
    </div>

    <?php if (!$isVoteAllowed): ?>
        <div class="disabled-notice">
            <i class="fa-solid fa-triangle-exclamation" style="font-size: 48px; margin-bottom: 15px;"></i>
            <h2 style="font-size: 24px; font-weight: 900; text-transform: uppercase; margin: 0 0 10px 0;"><?= __('vote_module_disabled') ?></h2>
            <p style="margin: 0; color: #ccc;"><?= __('vote_module_disabled_desc') ?></p>
        </div>
    <?php else: ?>

        <div class="player-vote-card">
            <?php if (!empty($activeNick)): ?>
                <div style="display: flex; align-items: center; gap: 20px;">
                    <img src="<?= getPlayerAvatar($activeNick, 130) ?>" alt="<?= htmlspecialchars($activeNick) ?>" class="player-head-large">
                    <div>
                        <span style="font-size: 11px; font-weight: 800; text-transform: uppercase; color: var(--primary-color);"><?= __('profile_member_badge') ?></span>
                        <h2 style="font-size: 24px; font-weight: 900; color: #fff; margin: 2px 0;"><?= htmlspecialchars($activeNick) ?></h2>
                        <span style="font-size: 13px; color: #aaa;"><?= __('vote_total_count') ?> <strong style="color: #4ade80; font-size: 16px;"><?= $playerVotesCount ?></strong></span>
                    </div>
                </div>
                <div>
                    <?php if (empty($loggedUsername)): ?>
                        <a href="vote.php?action=reset" class="btn-action" style="background: rgba(255,255,255,0.05); color: #fff; border: 1px solid var(--border-color); padding: 10px 18px; font-size: 12px; text-decoration: none;">
                            <i class="fa-solid fa-rotate-left"></i> Zmeniť nick
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div style="width: 100%;">
                    <h3 style="font-size: 18px; font-weight: 800; color: #fff; margin-top: 0; margin-bottom: 12px; text-transform: uppercase;"><?= __('vote_login_prompt') ?></h3>
                    <form method="POST" style="display: flex; gap: 12px; max-width: 500px;">
                        <input type="text" name="voting_nick" placeholder="<?= __('vote_nick_placeholder') ?>" required style="flex: 1; background: rgba(0,0,0,0.6); border: 1px solid var(--border-color); padding: 12px 18px; border-radius: 12px; color: #fff; font-size: 14px; outline: none;">
                        <button type="submit" class="btn-vote" style="padding: 12px 25px; margin: 0;"><?= __('vote_confirm_btn') ?></button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($voteRewards)): ?>
            <div class="rewards-showcase">
                <div style="text-align: center; margin-bottom: 25px;">
                    <h2 style="font-size: 24px; font-weight: 900; text-transform: uppercase; margin: 0 0 8px 0;"><?= __('vote_rewards_section_title') ?></h2>
                    <p style="color: #aaa; font-size: 14px; max-width: 600px; margin: 0 auto;"><?= __('vote_rewards_section_desc') ?></p>
                </div>
                <div class="rewards-grid">
                    <?php foreach ($voteRewards as $rew): ?>
                        <div class="reward-showcase-card">
                            <div class="reward-img-container">
                                <?php if (!empty($rew['image'])): ?>
                                    <img src="<?= htmlspecialchars(BASE_URL . '/' . $rew['image']) ?>" alt="Reward Item" class="reward-item-image">
                                <?php else: ?>
                                    <span style="font-size: 24px;">🎁</span>
                                <?php endif; ?>
                            </div>
                            <h3 style="font-size: 16px; font-weight: 800; color: #fff; margin: 0 0 6px 0;"><?= htmlspecialchars($rew['title']) ?></h3>
                            <?php if (!empty($rew['description'])): ?>
                                <p style="font-size: 12px; color: #aaa; margin: 0; line-height: 1.4;"><?= htmlspecialchars($rew['description']) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="vote-grid">
            <?php if (empty($voteSites)): ?>
                <div class="vote-card" style="grid-column: 1 / -1; text-align: center; padding: 40px;">
                    <p style="color: #888; margin: 0;"><?= __('no_data') ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($voteSites as $site):
                    $targetUrl = '#';
                    $hasNick = !empty($activeNick);
                    if ($hasNick) {
                        $targetUrl = str_replace('{user}', urlencode($activeNick), $site['site_url']);
                    }
                ?>
                    <div class="vote-card">
                        <div>
                            <?php if (!empty($site['banner_image'])): ?>
                                <img src="<?= htmlspecialchars($site['banner_image']) ?>" alt="<?= htmlspecialchars($site['site_name']) ?>" class="vote-banner">
                            <?php endif; ?>
                            <h3 style="font-size: 20px; font-weight: 900; color: #fff; margin-top: 0; margin-bottom: 10px;"><?= htmlspecialchars($site['site_name']) ?></h3>
                            <p style="color: #aaa; font-size: 13px; line-height: 1.5; margin-bottom: 25px;">Kliknutím na tlačidlo nižšie prejdete na hlasovací portál.</p>
                        </div>
                        <?php if ($hasNick): ?>
                            <a href="<?= htmlspecialchars($targetUrl) ?>" target="_blank" class="btn-vote">
                                <i class="fa-solid fa-check-to-slot"></i> <?= __('vote_site_btn') ?>
                            </a>
                        <?php else: ?>
                            <button type="button" class="btn-vote disabled" onclick="alert(<?= json_encode(__('vote_btn_enter_nick_first')) ?>)">
                                <i class="fa-solid fa-lock"></i> <?= __('vote_btn_enter_nick_first') ?>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- TOP HLASUJÚCI (PODIUM & LIST 4-10) -->
        <?php if (!empty($topVoters)): ?>
            <div class="top-voters-section">
                <div style="text-align: center; margin-bottom: 35px;">
                    <h2 style="font-size: 26px; font-weight: 900; text-transform: uppercase; margin: 0 0 8px 0;"><?= __('vote_top_title') ?? 'TOP HLASUJÚCI' ?></h2>
                    <p style="color: #aaa; font-size: 14px; margin: 0;"><?= __('vote_top_desc') ?? 'Najaktívnejší hráči na tomto serveri' ?></p>
                </div>

                <!-- 1, 2, 3 MIESTO (PODIUM) -->
                <?php if (count($topVoters) > 0): ?>
                    <div class="podium-container">
                        <?php
                            $podiumOrder = [];
                            if (isset($topVoters[1])) $podiumOrder[] = [2, $topVoters[1]]; // Striebro
                            if (isset($topVoters[0])) $podiumOrder[] = [1, $topVoters[0]]; // Zlato
                            if (isset($topVoters[2])) $podiumOrder[] = [3, $topVoters[2]]; // Bronz
                        ?>
                        <?php foreach ($podiumOrder as [$rank, $voter]):
                            $crownIcon = $rank === 1 ? '<i class="fa-solid fa-crown" style="color: #fbbf24; margin-right: 4px;"></i>' : '';
                        ?>
                            <div class="podium-card rank-<?= $rank ?>">
                                <div class="podium-badge"><?= $rank ?>. <?= __('vote_rank_place') ?></div>
                                <img src="<?= getPlayerAvatar($voter['player_name'], 120) ?>" alt="<?= htmlspecialchars($voter['player_name']) ?>" class="podium-avatar">
                                <h3 style="font-size: 16px; font-weight: 900; color: #fff; margin: 0 0 4px 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    <?= $crownIcon ?><?= htmlspecialchars($voter['player_name']) ?>
                                </h3>
                                <div style="font-size: 13px; color: var(--primary-color); font-weight: 800;">
                                    <?= number_format($voter['vote_count']) ?> hlasov
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- MIESTA 4 až 10 (ZOZNAM) -->
                <?php if (count($topVoters) > 3): ?>
                    <div class="other-voters-list">
                        <?php for ($i = 3; $i < count($topVoters); $i++): $voter = $topVoters[$i]; ?>
                            <div class="voter-row">
                                <div style="display: flex; align-items: center; gap: 15px;">
                                    <span style="font-size: 14px; font-weight: 900; color: #777; width: 25px; text-align: center;"><?= ($i + 1) ?>.</span>
                                    <img src="<?= getPlayerAvatar($voter['player_name'], 40) ?>" alt="Avatar" style="width: 32px; height: 32px; border-radius: 8px; image-rendering: pixelated; background: rgba(0,0,0,0.4);">
                                    <span style="font-weight: 800; color: #fff; font-size: 14px;"><?= htmlspecialchars($voter['player_name']) ?></span>
                                </div>
                                <span style="font-weight: 900; color: #4ade80; font-size: 13px; background: rgba(74, 222, 128, 0.1); padding: 4px 10px; border-radius: 8px; border: 1px solid rgba(74, 222, 128, 0.2);">
                                    <?= number_format($voter['vote_count']) ?> hlasov
                                </span>
                            </div>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>

            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>
