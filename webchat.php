<?php
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
require_once 'includes/auth.php';
require_once 'includes/skins.php';
require_once 'includes/language.php';

$activeServer = $_SESSION['active_server'] ?? ($_GET['server'] ?? '');

// Načítanie konfigurácie servera (dynmapa, povolenie chatu, min. hodiny) z DB
$serverConfig = []; $enableDynmap = false;
$dynmapUrl = ''; $allowWebchatTyping = false;
$allowGlobalWebchat = true;
$webchatMinPlaytimeHours = 1;

if (isset($pdo) && !empty($activeServer)) {
    try {
        $stConf = $pdo->prepare("SELECT enable_dynmap, dynmap_url, allow_webchat_typing, allow_global_webchat, webchat_min_playtime_hours FROM coresync_servers_config WHERE server_name = ? LIMIT 1");
        $stConf->execute([$activeServer]);
        $serverConfig = $stConf->fetch(PDO::FETCH_ASSOC);
        if ($serverConfig) {
            $enableDynmap = !empty($serverConfig['enable_dynmap']) && !empty($serverConfig['dynmap_url']);
            $dynmapUrl = $serverConfig['dynmap_url'] ?? '';
            $allowWebchatTyping = isset($serverConfig['allow_webchat_typing']) ? (bool)$serverConfig['allow_webchat_typing'] : true;
            $allowGlobalWebchat = isset($serverConfig['allow_global_webchat']) ? (bool)$serverConfig['allow_global_webchat'] : true;
            $webchatMinPlaytimeHours = max(0, (int)($serverConfig['webchat_min_playtime_hours'] ?? 1));
        }
    } catch (Exception $e) {}
}

$cooldownSeconds = 10;

// Pomocná funkcia na komplexnú kontrolu trestov (Ban, Tempban, Mute, Jail)
function checkIsPlayerRestricted($pdo, $username, $serverName) {
    if (empty($username) || !isset($pdo)) return false;
    try {
        // 1. Kontrola v špeciálnej tabuľke pre chat bany
        $stBan = $pdo->prepare("SELECT 1 FROM coresync_chat_bans WHERE player_name = ? LIMIT 1");
        $stBan->execute([$username]);
        if ($stBan->fetchColumn()) {
            return true;
        }

        // 2. Kontrola v tabuľke trestov (coresync_punishments) pre BAN, BANIP, MUTE, JAIL
        $stPunish = $pdo->prepare("
            SELECT expires_at FROM coresync_punishments
            WHERE LOWER(player_name) = LOWER(?)
              AND server_name IN (?, 'global')
              AND punish_type IN ('BAN', 'BANIP', 'MUTE', 'JAIL')
              AND active = 1
            LIMIT 1
        ");
        $stPunish->execute([$username, $serverName]);
        $punishment = $stPunish->fetch(PDO::FETCH_ASSOC);

        if ($punishment) {
            // Ak je expires_at prázdne (trvalý trest) alebo v budúcnosti, hráč má obmedzenie
            if (empty($punishment['expires_at']) || strtotime($punishment['expires_at']) > time()) {
                return true;
            }
        }
    } catch (Exception $e) {}
    return false;
}

if (isset($_GET['ajax_action'])) {
    header('Content-Type: application/json');

    $currentUserData = function_exists('currentUser') ? currentUser() : null;
    $currentUsername = '';
    if (is_array($currentUserData)) {
        $currentUsername = $currentUserData['username'] ?? '';
    } elseif (is_string($currentUserData)) {
        $currentUsername = $currentUserData;
    }

    $isRestricted = checkIsPlayerRestricted($pdo, $currentUsername, $activeServer);

    if ($_GET['ajax_action'] === 'load_chat') {
        try {
            $isLoggedIn = !empty($currentUsername);
            $timeLimit = $isLoggedIn ? '48 HOUR' : '1 HOUR';

            $stmt = $pdo->prepare("
                SELECT id, server_name, sender_name, sender_prefix, message, source, created_at
                FROM coresync_chat
                WHERE (server_name = ? OR source = 'GLOBAL') AND created_at >= NOW() - INTERVAL $timeLimit
                ORDER BY created_at ASC
            ");
            $stmt->execute([$activeServer]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Preložíme pôvodný server_name na pekný displejný názov
            $stCfgAll = $pdo->query("SELECT server_name, display_name FROM coresync_servers_config");
            $serverNamesMap = [];
            while ($row = $stCfgAll->fetch(PDO::FETCH_ASSOC)) {
                $serverNamesMap[$row['server_name']] = !empty($row['display_name']) ? $row['display_name'] : $row['server_name'];
            }

            foreach ($messages as &$msg) {
                // Ak je odosielateľ "Server", pre avatar použijeme "Console"
                $avatarTarget = ($msg['sender_name'] === 'Server') ? 'Console' : $msg['sender_name'];
                $msg['avatar'] = getPlayerAvatar($avatarTarget, 30);
                $msg['time'] = formatCorrectTime($msg['created_at'], null, false, 'd.m. H:i');

                if ($msg['source'] === 'GLOBAL') {
                    $originDisplayName = $serverNamesMap[$msg['server_name']] ?? $msg['server_name'];
                    $msg['origin_display'] = $originDisplayName;
                }
            }

            $currentCd = 0;
            if (isset($_SESSION['last_chat_message'])) {
                $el = time() - $_SESSION['last_chat_message'];
                if ($el < $cooldownSeconds) {
                    $currentCd = $cooldownSeconds - $el;
                }
            }

            echo json_encode(['success' => true, 'messages' => $messages, 'currentUser' => $currentUsername, 'cooldown' => $currentCd]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($_GET['ajax_action'] === 'send_message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$allowWebchatTyping) {
            echo json_encode(['success' => false, 'error' => __('webchat_disabled_notice')]);
            exit;
        }

        $messageText = trim($_POST['message'] ?? '');
        $isGlobal = !empty($_POST['is_global']) && $_POST['is_global'] === '1';

        if (empty($currentUsername)) {
            echo json_encode(['success' => false, 'error' => __('webchat_err_login')]);
            exit;
        }
        if ($isRestricted) {
            echo json_encode(['success' => false, 'error' => __('webchat_err_ban')]);
            exit;
        }

        if ($isGlobal && !$allowGlobalWebchat) {
            echo json_encode(['success' => false, 'error' => __('webchat_err_global_disabled')]);
            exit;
        }

        // Kontrola odohraných hodín
        if ($webchatMinPlaytimeHours > 0 && isset($pdo)) {
            try {
                $stTime = $pdo->prepare("
                    SELECT pd.playtime_ticks
                    FROM coresync_player_data pd
                    JOIN coresync_users u ON pd.uuid = u.uuid
                    WHERE LOWER(u.player_name) = LOWER(?) AND pd.server_name = ?
                    LIMIT 1
                ");
                $stTime->execute([$currentUsername, $activeServer]);
                $ticks = (int)$stTime->fetchColumn();

                $playerHoursPlayed = $ticks / 72000;

                if ($playerHoursPlayed < $webchatMinPlaytimeHours) {
                    echo json_encode([
                        'success' => false,
                        'error' => __('webchat_err_min_playtime', [
                            'hours' => $webchatMinPlaytimeHours,
                            'current' => round($playerHoursPlayed, 1)
                        ])
                    ]);
                    exit;
                }
            } catch (Exception $e) {}
        }

        if (isset($_SESSION['last_chat_message'])) {
            $elapsed = time() - $_SESSION['last_chat_message'];
            if ($elapsed < $cooldownSeconds) {
                echo json_encode(['success' => false, 'error' => __('webchat_err_cooldown', ['seconds' => ($cooldownSeconds - $elapsed)])]);
                exit;
            }
        }

        if (empty($messageText)) {
            echo json_encode(['success' => false, 'error' => __('webchat_err_empty')]);
            exit;
        }

        try {
            if ($isGlobal) {
                // Odoslanie ako Globálna správa (server_name nastavíme na 'Web', aby ju všetky herné servery stiahli ako globálnu)
                $stmt = $pdo->prepare("INSERT INTO coresync_chat (server_name, sender_name, message, source, delivered) VALUES ('Web', ?, ?, 'GLOBAL', TRUE)");
                $stmt->execute([$currentUsername, $messageText]);
            } else {
                // Odoslanie ako bežná správa na aktuálny server
                $stmt = $pdo->prepare("INSERT INTO coresync_chat (server_name, sender_name, message, source, delivered) VALUES (?, ?, ?, 'WEB', FALSE)");
                $stmt->execute([$activeServer, $currentUsername, $messageText]);
            }
            $_SESSION['last_chat_message'] = time();

            echo json_encode(['success' => true, 'cooldown' => $cooldownSeconds]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => __('webchat_err_db')]);
        }
        exit;
    }
}

// Kontrola, či má server aktívny webchat modul
$activeModules = $_SESSION['active_web_modules'] ?? []; $hasWebChatModule = !empty($activeServer) && in_array('corewebchat', $activeModules);

if (!$hasWebChatModule && !$enableDynmap) {
    echo "<script>window.location.href = 'index.php';</script>";
    exit;
}

$currentUserData = function_exists('currentUser') ? currentUser() : null;
$currentUsername = '';
if (is_array($currentUserData)) {
    $currentUsername = $currentUserData['username'] ?? '';
} elseif (is_string($currentUserData)) {
    $currentUsername = $currentUserData;
}

$isRestricted = checkIsPlayerRestricted($pdo, $currentUsername, $activeServer);

$remainingCooldown = 0;
if (isset($_SESSION['last_chat_message'])) {
    $elapsed = time() - $_SESSION['last_chat_message'];
    if ($elapsed < $cooldownSeconds) {
        $remainingCooldown = $cooldownSeconds - $elapsed;
    }
}

if ($hasWebChatModule && $enableDynmap) {
    $pageHeaderTitle = __('webchat_title_both'); $pageTitle = __('nav_webchat_and_map');
} elseif ($hasWebChatModule) {
    $pageHeaderTitle = __('webchat_title_chat_only'); $pageTitle = __('nav_webchat');
} else {
    $pageHeaderTitle = __('webchat_title_map_only'); $pageTitle = __('nav_map');
}

require_once 'includes/header.php';
?>

<style>
    .webchat-layout {
        display: grid;
        grid-template-columns: <?= ($hasWebChatModule && $enableDynmap) ? '420px 1fr' : '1fr' ?>;
        gap: 30px;
        height: calc(100vh - 120px);
        min-height: 600px;
        margin-bottom: 45px;
    }

    @media(max-width: 900px) {
        .webchat-layout {
            grid-template-columns: 1fr;
            height: auto;
        }
        .chat-panel {
            height: 70vh;
            min-height: 450px;
        }
    }

    .chat-panel {
        background: rgba(12, 12, 12, 0.85);
        border: 1px solid var(--border-color);
        border-radius: 20px;
        display: flex;
        flex-direction: column;
        backdrop-filter: blur(15px);
        overflow: hidden;
    }

    .chat-header {
        background: rgba(0, 0, 0, 0.4);
        padding: 20px;
        border-bottom: 1px solid var(--border-color);
        font-weight: 900;
        text-transform: uppercase;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .chat-messages {
        flex: 1;
        padding: 20px;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 15px;
    }

    .chat-msg {
        display: flex;
        gap: 12px;
        animation: fadeIn 0.3s ease;
        max-width: 85%;
        align-self: flex-start;
    }

    @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

    /* VLASTNÉ SPRÁVY ZAROVNANÉ VPRAVO */
    .chat-msg.own-message {
        align-self: flex-end;
        flex-direction: row-reverse;
    }
    .chat-msg.own-message .chat-content {
        background: color-mix(in srgb, var(--primary-color) 10%, rgba(255,255,255,0.02));
        border-color: color-mix(in srgb, var(--primary-color) 40%, transparent);
    }

    .chat-msg.own-message .chat-author-row {
        justify-content: flex-end;
    }
    .chat-msg.own-message .chat-text {
        text-align: right;
    }

    /* SYSTEM SPRÁVY */
    .chat-msg.msg-source-system {
        max-width: 85%;
    }
    .chat-msg.msg-source-system .chat-content {
        background: rgba(255, 255, 255, 0.02);
        border: 1px dashed rgba(255, 255, 255, 0.08);
    }
    .msg-source-system .chat-author-row .author {
        color: #aaa;
    }

    /* WEB SPRÁVY */
    .msg-source-web .chat-content {
        border-color: color-mix(in srgb, var(--primary-color) 30%, transparent);
        background: color-mix(in srgb, var(--primary-color) 5%, transparent);
    }

    /* VOTIFIER / HLASOVANIE - ZLATÉ ORÁMOVANIE & BADGE */
    .chat-msg.msg-source-votifier .chat-content {
        background: linear-gradient(135deg, rgba(255, 215, 0, 0.08), rgba(255, 165, 0, 0.03));
        border: 1px solid rgba(255, 215, 0, 0.4);
        box-shadow: 0 0 15px rgba(255, 215, 0, 0.1);
    }

    .vote-badge {
        background: rgba(255, 215, 0, 0.15);
        color: #ffd700;
        border: 1px solid rgba(255, 215, 0, 0.35);
        padding: 2px 7px;
        border-radius: 6px;
        font-size: 10px;
        font-weight: 800;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* --- ŠTRUKTÚRA TEXTOVEJ BUBLINY --- */
    .chat-content {
        background: rgba(255, 255, 255, 0.03);
        padding: 12px 16px;
        border-radius: 12px;
        border: 1px solid rgba(255, 255, 255, 0.05);
        flex: 1;
        min-width: 0;
    }

    .chat-top-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 5px;
        font-size: 11px;
        color: #888;
        gap: 15px;
    }

    .chat-author-row {
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
    }

    .chat-text {
        font-size: 14px;
        color: #ddd;
        word-break: break-word;
        line-height: 1.4;
    }

    .global-tag {
        background: rgba(255, 105, 180, 0.15);
        color: #ff69b4;
        border: 1px solid rgba(255, 105, 180, 0.3);
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 10px;
        font-weight: 800;
        white-space: nowrap;
    }

    .global-server-name {
        color: #aaa;
        font-size: 12px;
        white-space: nowrap;
    }

    .chat-author-row .author {
        font-weight: 800;
        color: var(--primary-color);
        word-break: normal;
        font-size: 13px;
    }

    .chat-avatar img {
        width: 38px;
        height: 38px;
        border-radius: 8px;
        border: 1px solid rgba(255, 255, 255, 0.1);
        image-rendering: pixelated;
        flex-shrink: 0;
    }

    .chat-input-area {
        padding: 15px;
        background: rgba(0, 0, 0, 0.3);
        border-top: 1px solid var(--border-color);
        position: relative;
    }

    .chat-input-form {
        display: flex;
        gap: 10px;
    }

    .chat-input {
        flex: 1;
        background: rgba(0, 0, 0, 0.5);
        border: 1px solid var(--border-color);
        padding: 12px 15px;
        border-radius: 10px;
        color: #fff;
        outline: none;
        font-family: inherit;
    }

    .chat-input:focus {
        border-color: var(--primary-color);
    }

    .btn-send {
        background: var(--primary-color);
        color: #000;
        border: none;
        padding: 0 20px;
        border-radius: 10px;
        font-weight: 900;
        cursor: pointer;
        transition: 0.2s;
    }

    .btn-send:hover:not(:disabled) {
        transform: scale(1.05);
        box-shadow: 0 0 15px color-mix(in srgb, var(--primary-color) 50%, transparent);
    }

    .btn-send:disabled {
        background: #333;
        color: #777;
        cursor: not-allowed;
    }

    .chat-alert {
        text-align: center;
        padding: 15px;
        border-radius: 10px;
        font-weight: 700;
        font-size: 13px;
    }

    .chat-alert.login { background: rgba(0, 191, 255, 0.1); border: 1px solid rgba(0, 191, 255, 0.3); color: #fff; }
    .chat-alert.ban { background: rgba(255, 68, 68, 0.1); border: 1px solid rgba(255, 68, 68, 0.3); color: #ff4444; }
    .chat-alert.disabled { background: rgba(255, 170, 0, 0.1); border: 1px solid rgba(255, 170, 0, 0.3); color: #ffaa00; }

    .chat-notification-banner {
        display: none;
        background: rgba(255, 68, 68, 0.12);
        border: 1px solid rgba(255, 68, 68, 0.35);
        color: #ff6b6b;
        padding: 10px 15px;
        border-radius: 10px;
        font-size: 13px;
        font-weight: 700;
        margin-bottom: 12px;
        align-items: center;
        gap: 10px;
        animation: fadeIn 0.3s ease;
    }

    .dynmap-panel {
        background: rgba(12, 12, 12, 0.6);
        border: 1px solid var(--border-color);
        border-radius: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-direction: column;
        backdrop-filter: blur(10px);
        overflow: hidden;
        position: relative;
        height: 100%;
        min-height: 500px;
    }
    .dynmap-iframe {
        width: 100%;
        height: 100%;
        border: none;
    }

    /* CSS pre zaškrtávacie políčko pre globálny chat */
    .global-toggle-label {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 10px;
        cursor: pointer;
        width: fit-content;
        user-select: none;
    }
    .global-toggle-label input[type="checkbox"] {
        accent-color: #ff69b4;
        cursor: pointer;
        width: 15px;
        height: 15px;
        margin: 0;
    }
</style>

<div style="margin-bottom: 25px;">
    <h2 class="section-title"><?= $pageHeaderTitle ?></h2>
    <div class="section-subtitle"><?= __('webchat_subtitle', ['server' => htmlspecialchars($serverDisplayName)]) ?></div>
</div>

<div class="webchat-layout">

    <?php if ($hasWebChatModule): ?>
    <div class="chat-panel">
        <div class="chat-header">
            <span><i class="fa-solid fa-comments"></i> <?= __('webchat_live') ?></span>
            <span style="font-size: 11px; color: #888; font-weight: normal;">
                <?= !empty($currentUsername) ? __('webchat_history_login') : __('webchat_history_guest') ?>
            </span>
        </div>

        <div class="chat-messages" id="chatMessages">
            <div id="loadingChat" style="text-align: center; color: #888; font-size: 13px; margin-top: auto; margin-bottom: auto;">
                <?= __('webchat_loading') ?> <i class="fa-solid fa-circle-notch fa-spin"></i>
            </div>
        </div>

        <div class="chat-input-area">
            <div id="chatNotification" class="chat-notification-banner">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span id="chatNotificationText"></span>
            </div>

            <?php if (!$allowWebchatTyping): ?>
                <div class="chat-alert disabled">
                    <i class="fa-solid fa-ban"></i> <?= __('webchat_disabled_notice') ?>
                </div>
            <?php elseif (empty($currentUsername)): ?>
                <div class="chat-alert login">
                    <?= __('webchat_login_req') ?>
                </div>
            <?php elseif ($isRestricted): ?>
                <div class="chat-alert ban">
                    <i class="fa-solid fa-ban"></i> <?= __('webchat_banned') ?>
                </div>
            <?php else: ?>
                <form id="chatForm" style="display: flex; flex-direction: column;">
                    <div class="chat-input-form">
                        <input type="text" id="chatInput" class="chat-input" placeholder="<?= __('webchat_ph') ?>" required autocomplete="off" maxlength="255">
                        <button type="submit" id="sendBtn" class="btn-send"><i class="fa-solid fa-paper-plane"></i></button>
                    </div>
                    <?php if ($allowGlobalWebchat): ?>
                    <label class="global-toggle-label">
                        <input type="checkbox" id="isGlobalCheck" value="1">
                        <span class="global-tag" style="margin: 0; padding: 2px 5px;">[G]</span>
                        <span style="font-size: 12px; color: #aaa; font-weight: bold;"><?= __('webchat_global_checkbox') ?></span>
                    </label>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($enableDynmap): ?>
    <div class="dynmap-panel">
        <iframe src="<?= htmlspecialchars($dynmapUrl) ?>" class="dynmap-iframe" title="Dynmap"></iframe>
    </div>
    <?php endif; ?>

</div>

<?php if ($hasWebChatModule): ?>
<script>
const langWebchat = {
    errSend: <?php echo json_encode(__('webchat_err_send')); ?>,
    cooldownMsg: <?php echo json_encode(__('webchat_err_cooldown', ['seconds' => '{seconds}'])); ?>,
    voteLabel: <?php echo json_encode(__('votifier_tag')); ?>
};

function parseMinecraftColors(text) {
    if (!text) return '';
    let safeHtml = text.replace(/</g, "&lt;").replace(/>/g, "&gt;");

    const colorMap = {
        '0': '#000000', '1': '#0000AA', '2': '#00AA00', '3': '#00AAAA',
        '4': '#AA0000', '5': '#AA00AA', '6': '#FFAA00', '7': '#AAAAAA',
        '8': '#555555', '9': '#5555FF', 'a': '#55FF55', 'b': '#55FFFF',
        'c': '#FF5555', 'd': '#FF55FF', 'e': '#FFFF55', 'f': '#FFFFFF'
    };
    const formatMap = {
        'l': 'font-weight: bold;',
        'm': 'text-decoration: line-through;',
        'n': 'text-decoration: underline;',
        'o': 'font-style: italic;'
    };

    let openSpans = 0;

    let parsed = safeHtml.replace(/(?:§|&)([0-9a-fk-or])/ig, (match, code) => {
        code = code.toLowerCase();

        if (code === 'r') {
            let closes = '</span>'.repeat(openSpans);
            openSpans = 0;
            return closes;
        } else if (colorMap[code]) {
            let closes = '</span>'.repeat(openSpans);
            openSpans = 1;
            return closes + `<span style="color: ${colorMap[code]}; text-shadow: 1px 1px 0px rgba(0,0,0,0.5);">`;
        } else if (formatMap[code]) {
            openSpans++;
            return `<span style="${formatMap[code]}">`;
        }
        return '';
    });

    parsed += '</span>'.repeat(openSpans);
    return parsed;
}

document.addEventListener('DOMContentLoaded', () => {
    const chatMessages = document.getElementById('chatMessages');
    const chatForm = document.getElementById('chatForm');
    const chatInput = document.getElementById('chatInput');
    const sendBtn = document.getElementById('sendBtn');
    const notificationBanner = document.getElementById('chatNotification');
    const notificationText = document.getElementById('chatNotificationText');
    const isGlobalCheck = document.getElementById('isGlobalCheck');

    let isAutoScrolling = true;
    let renderedIds = new Set();
    let isFirstLoad = true;
    let cooldownInterval = null;

    function showNotification(message) {
        if (!notificationBanner || !notificationText) return;
        notificationText.textContent = message;
        notificationBanner.style.display = 'flex';
    }

    function hideNotification() {
        if (!notificationBanner) return;
        notificationBanner.style.display = 'none';
    }

    let initialCooldown = <?= $remainingCooldown ?>;
    if (initialCooldown > 0) {
        startCooldown(initialCooldown);
    }

    if (chatMessages) {
        chatMessages.addEventListener('scroll', () => {
            const atBottom = chatMessages.scrollHeight - chatMessages.scrollTop <= chatMessages.clientHeight + 50;
            isAutoScrolling = atBottom;
        });
    }

    function startCooldown(seconds) {
        if (!sendBtn || !chatInput) return;
        chatInput.disabled = true;
        sendBtn.disabled = true;

        let timeLeft = seconds;
        sendBtn.innerHTML = `<span style="font-size:11px;">${timeLeft}s</span>`;

        let formattedCdMsg = langWebchat.cooldownMsg.replace('{seconds}', timeLeft);
        showNotification(formattedCdMsg);

        if (cooldownInterval) clearInterval(cooldownInterval);

        cooldownInterval = setInterval(() => {
            timeLeft--;
            if (timeLeft > 0) {
                sendBtn.innerHTML = `<span style="font-size:11px;">${timeLeft}s</span>`;
                let formattedCdMsg = langWebchat.cooldownMsg.replace('{seconds}', timeLeft);
                showNotification(formattedCdMsg);
            } else {
                clearInterval(cooldownInterval);
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i>';
                chatInput.disabled = false;
                hideNotification();
                chatInput.focus();
            }
        }, 1000);
    }

    function loadMessages() {
        fetch('webchat.php?ajax_action=load_chat')
            .then(res => res.json())
            .then(data => {
                if (!data.success || !chatMessages) return;

                if (isFirstLoad) {
                    const loading = document.getElementById('loadingChat');
                    if(loading) loading.remove();
                    isFirstLoad = false;

                    if (data.messages.length === 0) {
                        chatMessages.innerHTML = '<div id="emptyChat" style="text-align: center; color: #888; font-size: 13px; margin-top: auto; margin-bottom: auto;"><?= __("webchat_empty") ?></div>';
                    }
                }

                let hasNewMessages = false;
                const loggedUser = data.currentUser || '';

                data.messages.forEach(msg => {
                    if (!renderedIds.has(msg.id)) {
                        const emptyChat = document.getElementById('emptyChat');
                        if (emptyChat) emptyChat.remove();

                        let cssClass = 'chat-msg';

                        let topTagHtml = '';
                        let authorRowHtml = '';
                        let formattedText = parseMinecraftColors(msg.message);

                        if (msg.source === 'WEB') {
                            cssClass += ' msg-source-web';
                            topTagHtml = '<i class="fa-solid fa-globe" title="Web"></i>';
                            authorRowHtml = `<span class="author">${parseMinecraftColors(msg.sender_name)}</span>`;
                        } else if (msg.source === 'SYSTEM') {
                            cssClass += ' msg-source-system';
                            topTagHtml = '<i class="fa-solid fa-server" title="Server"></i>';
                            authorRowHtml = `<span class="author">${parseMinecraftColors(msg.sender_name)}</span>`;
                        } else if (msg.source === 'VOTIFIER') {
                            cssClass += ' msg-source-votifier';
                            topTagHtml = `<span class="vote-badge">${langWebchat.voteLabel}</span>`;
                            authorRowHtml = '';
                        } else if (msg.source === 'GLOBAL') {
                            topTagHtml = '<span class="global-tag">[G]</span>';
                            let prefixText = msg.sender_prefix ? msg.sender_prefix + ' ' : '';
                            authorRowHtml = `
                                <span class="global-server-name">${msg.origin_display} <span style="color: #555;">|</span></span>
                                <span class="author">${parseMinecraftColors(prefixText + msg.sender_name)}</span>
                            `;
                        } else {
                            topTagHtml = '<i class="fa-solid fa-cube" title="Hra"></i>';
                            let prefixText = msg.sender_prefix ? msg.sender_prefix + ' ' : '';
                            authorRowHtml = `<span class="author">${parseMinecraftColors(prefixText + msg.sender_name)}</span>`;
                        }

                        if (loggedUser && msg.sender_name.toLowerCase() === loggedUser.toLowerCase() && msg.source !== 'SYSTEM' && msg.source !== 'VOTIFIER') {
                            cssClass += ' own-message';
                        }

                        let authorSectionHtml = authorRowHtml ? `<div class="chat-author-row">${authorRowHtml}</div>` : '';

                        const msgDiv = document.createElement('div');
                        msgDiv.className = cssClass;

                        msgDiv.innerHTML = `
                            <div class="chat-avatar">
                                <img src="${msg.avatar}" alt="Avatar">
                            </div>
                            <div class="chat-content">
                                <div class="chat-top-row">
                                    <span class="chat-source">${topTagHtml}</span>
                                    <span class="chat-time">${msg.time}</span>
                                </div>
                                ${authorSectionHtml}
                                <div class="chat-text">
                                    ${formattedText}
                                </div>
                            </div>
                        `;

                        chatMessages.appendChild(msgDiv);
                        renderedIds.add(msg.id);
                        hasNewMessages = true;
                    }
                });

                if (hasNewMessages && isAutoScrolling) {
                    chatMessages.scrollTop = chatMessages.scrollHeight;
                }
            })
            .catch(err => console.log('Chat Sync Error:', err));
    }

    if (chatForm) {
        chatForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const text = chatInput.value.trim();
            if (!text || (sendBtn && sendBtn.disabled)) return;

            const formData = new FormData();
            formData.append('message', text);

            if (isGlobalCheck && isGlobalCheck.checked) {
                formData.append('is_global', '1');
            }

            fetch('webchat.php?ajax_action=send_message', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    chatInput.value = '';
                    loadMessages();
                    setTimeout(() => chatMessages.scrollTop = chatMessages.scrollHeight, 100);
                    startCooldown(data.cooldown || 10);
                } else {
                    showNotification(data.error || langWebchat.errSend);
                    chatInput.focus();
                }
            })
            .catch(err => {
                console.error(err);
            });
        });
    }

    loadMessages();
    setInterval(loadMessages, 3000);
});
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
