<?php
// webshop.php - Globálny trh, Admin Shop a Výmenný obchod (FULL LOCALIZED & SERVER CHECK)
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
require_once 'includes/icon_helper.php';

$currentUser = currentUser();
$isGuest = !$currentUser;

// --- KONTROLA INŠTALÁCIE WEBSHOPU ---
$installedServers = [];
try {
    $stInst = $pdo->query("SELECT DISTINCT server_name FROM corewebshop_settings UNION SELECT DISTINCT server_name FROM corewebshop_listings");
    $installedServers = $stInst->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$activeServerName = $_SESSION['active_server'] ?? ($installedServers[0] ?? 'Survival');
$uuid = $isGuest ? '' : ($currentUser['uuid'] ?? '');

$isVaultOpen = false;
if (!$isGuest) {
    $stmtLock = $pdo->prepare("SELECT is_open FROM corewebshop_sessions WHERE uuid = ? LIMIT 1");
    $stmtLock->execute([$uuid]);
    $rowLock = $stmtLock->fetch();
    if ($rowLock && $rowLock['is_open'] == 1) {
        $isVaultOpen = true;
    }
}

// Helper na zistenie mena predajcu
if (!function_exists('getSellerName')) {
    function getSellerName($sellerUuid, $pdo) {
        if ($sellerUuid === 'ADMIN') return __('webshop_admin_shop');
        $st = $pdo->prepare("SELECT player_name FROM coresync_users WHERE uuid = ? LIMIT 1");
        $st->execute([$sellerUuid]);
        $res = $st->fetchColumn();
        return $res ? $res : __('webshop_unknown_player');
    }
}

// --- AJAX OBSLUHA TRHU (PAGINATION & FILTERS) ---
if (isset($_GET['ajax_market'])) {
    header('Content-Type: application/json');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 6;
    $search = strtolower($_GET['search'] ?? '');
    $sellerFilter = $_GET['seller'] ?? 'all';
    $priceFilter = $_GET['price'] ?? 'all';
    $showSold = ($_GET['showSold'] ?? 'false') === 'true';

    $stmtMarket = $pdo->prepare("SELECT * FROM corewebshop_listings WHERE status IN ('active', 'sold') AND (server_name = ? OR server_name = 'global') ORDER BY id DESC");
    $stmtMarket->execute([$activeServerName]);
    $marketListings = $stmtMarket->fetchAll(PDO::FETCH_ASSOC);

    $filteredListings = [];
    foreach ($marketListings as $listing) {
        $isSold = ($listing['status'] === 'sold' || $listing['stock_available'] <= 0);
        $isAdmin = ($listing['seller_uuid'] === 'ADMIN');
        $sellerType = $isAdmin ? 'admin' : 'players';
        $sellerName = getSellerName($listing['seller_uuid'], $pdo);

        $isBundle = ($listing['listing_type'] === 'bundle');
        $parsedNBT = parseForgeNbt($listing['item_nbt'] ?? '');
        $customName = $parsedNBT['custom_name'];
        $nameDisplay = $isBundle ? __('webshop_bundle_title') : (!empty($customName) ? $customName : ($listing['display_name'] ?: formatMcName($listing['icon_id'])));

        if (!$showSold && $isSold) continue;
        if ($sellerFilter !== 'all' && $sellerType !== $sellerFilter) continue;
        if ($priceFilter !== 'all' && $listing['price_type'] !== $priceFilter) continue;
        if ($search !== '' && strpos(strtolower($nameDisplay . ' ' . $sellerName), $search) === false) continue;

        $listing['sellerName'] = $sellerName;
        $listing['nameDisplay'] = $nameDisplay;
        $listing['isSold'] = $isSold;
        $listing['isAdmin'] = $isAdmin;
        $filteredListings[] = $listing;
    }

    $totalItems = count($filteredListings);
    $totalPages = ceil($totalItems / $limit);
    if ($totalPages == 0) $totalPages = 1;
    if ($page > $totalPages) $page = $totalPages;

    $offset = ($page - 1) * $limit;
    $paginatedListings = array_slice($filteredListings, $offset, $limit);

    ob_start();
    if (empty($paginatedListings)) {
        echo '<div style="grid-column: 1/-1; text-align: center; padding: 40px; color: #888; font-weight: bold; font-size: 16px;">' . __('webshop_no_market_items') . '</div>';
    } else {
        foreach ($paginatedListings as $listing) {
            $isSpecial = ($listing['listing_type'] === 'special');
            $isBundle = ($listing['listing_type'] === 'bundle');
            $isMoney = ($listing['price_type'] === 'money');
            $isSold = $listing['isSold'];
            $sellerName = $listing['sellerName'];
            $isAdmin = $listing['isAdmin'];
            $nameDisplay = $listing['nameDisplay'];
            $nameDisplayHtml = htmlspecialchars($nameDisplay);

            $stockDisplay = ($listing['stock_available'] >= 999999) ? '∞' : $listing['amount_per_package'] . 'x <span class="stock">(' . __('webshop_out_of') . ' ' . ($listing['stock_available'] * $listing['amount_per_package']) . ')</span>';
            if ($isBundle) $stockDisplay = __('badge_bundle');
            if ($isSold) $stockDisplay = __('badge_sold');
            ?>
                <div class="item-card <?= $isSpecial ? 'special' : '' ?> <?= $isSold ? 'sold-item' : '' ?>">
                    <?php if ($isSpecial && !$isSold): ?>
                        <div class="special-badge"><i class="fa-solid fa-star"></i> <?= __('badge_special') ?></div>
                    <?php endif; ?>

                    <div class="item-server"><?= htmlspecialchars($listing['server_name']) ?></div>
                    <div class="item-amount <?= ($listing['stock_available'] >= 999999 && !$isSold) ? 'infinite' : '' ?>"><?= $stockDisplay ?></div>

                    <div class="item-visual <?= $isBundle ? 'bundle-visual' : '' ?>">
                        <?php if ($isBundle):
                            $stmtBndl = $pdo->prepare("SELECT * FROM corewebshop_bundle_items WHERE listing_id = ?");
                            $stmtBndl->execute([$listing['id']]);
                            foreach ($stmtBndl->fetchAll() as $bItem) {
                                echo renderShopItem($bItem['item_id'], $bItem['display_name'] ?: formatMcName($bItem['item_id']), $activeServerName, [], 48, $bItem['amount'], $bItem['item_nbt'] ?? null);
                            }
                        else:
                            echo renderShopItem($listing['icon_id'], $nameDisplayHtml, $activeServerName, [], 70, $listing['amount_per_package'], $listing['item_nbt'] ?? null);
                        endif; ?>
                    </div>

                    <div>
                        <div class="item-name" style="<?= $isSpecial ? 'color: #ff55ff;' : ($isBundle ? 'color: #ffaa00; font-size: 16px;' : '') ?>"><?= $nameDisplayHtml ?></div>

                        <div class="item-seller <?= $isAdmin ? 'admin-shop-seller' : '' ?>">
                            <?php if ($isAdmin): ?>
                                <i class="fa-solid fa-server"></i> <?= __('webshop_admin_shop') ?>
                            <?php else: ?>
                                <img src="<?= getPlayerAvatar($sellerName, 20) ?>"> <?= htmlspecialchars($sellerName) ?>
                            <?php endif; ?>
                        </div>

                        <?php if ($isMoney): ?>
                            <div class="price-money" style="<?= $isSpecial ? 'color: #ff55ff; background: rgba(255,85,255,0.05); border-color: rgba(255,85,255,0.3);' : '' ?>">
                                <i class="fa-solid fa-coins"></i> <?= number_format($listing['price_money'], 2) ?> $
                            </div>
                        <?php else:
                            $reqs = json_decode($listing['price_barter_json'], true) ?: [];
                        ?>
                            <div class="price-barter">
                                <span style="color: #aaa; text-transform: uppercase; font-size: 11px;"><?= __('webshop_req_barter') ?></span>
                                <?php foreach ($reqs as $req):
                                    $rParsed = parseForgeNbt($req['item_nbt'] ?? '');
                                    $rName = !empty($rParsed['custom_name']) ? $rParsed['custom_name'] : $req['name'];
                                ?>
                                    <div class="barter-req-row">
                                        <?= renderShopItem($req['icon'], $rName, $activeServerName, [], 20, $req['amount'], $req['item_nbt'] ?? null) ?> <?= $req['amount'] ?>x <?= htmlspecialchars($rName) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($isSold): ?>
                            <button class="btn-action-shop btn-action-disabled" disabled><?= __('webshop_btn_sold') ?></button>
                        <?php elseif (!$isGuest && $listing['seller_uuid'] === $uuid): ?>
                            <button class="btn-action-shop btn-action-disabled" disabled><?= __('webshop_btn_yours') ?></button>
                        <?php else:
                            $reqsData = $isMoney ? $listing['price_money'] : json_decode($listing['price_barter_json'], true);
                            $safeReqs = htmlspecialchars(json_encode($reqsData), ENT_QUOTES, 'UTF-8');
                            $safeName = htmlspecialchars(json_encode($nameDisplayHtml), ENT_QUOTES, 'UTF-8');
                            $safeSeller = htmlspecialchars(json_encode($sellerName), ENT_QUOTES, 'UTF-8');

                            if ($isMoney) {
                                $btnTxt = $isSpecial ? __('webshop_btn_buy') : ($isBundle ? __('webshop_btn_buy_bundle') : __('webshop_btn_buy'));
                                $btnIcon = $isSpecial ? 'fa-key' : ($isBundle ? 'fa-boxes-packing' : 'fa-cart-shopping');
                            } else {
                                $btnTxt = __('webshop_btn_barter');
                                $btnIcon = 'fa-handshake';
                            }

                            if ($isGuest):
                        ?>
                            <button class="btn-action-shop <?= $isSpecial ? 'btn-action-special' : ($isMoney ? '' : 'btn-action-barter') ?>"
                                onclick="openGuestModal()">
                                <i class="fa-solid <?= $btnIcon ?>"></i> <?= $btnTxt ?>
                            </button>
                        <?php else: ?>
                            <button class="btn-action-shop <?= $isSpecial ? 'btn-action-special' : ($isMoney ? '' : 'btn-action-barter') ?>"
                                <?= $isVaultOpen ? 'disabled style="opacity:0.5;"' : '' ?>
                                onclick="openBuyModal(<?= $listing['id'] ?>, <?= $safeName ?>, <?= $listing['amount_per_package'] ?>, <?= $safeReqs ?>, <?= $listing['stock_available'] ?>, <?= $isBundle ? 'true' : 'false' ?>, '<?= $listing['price_type'] ?>', <?= $safeSeller ?>, '<?= $listing['server_name'] ?>', '<?= $listing['icon_id'] ?>', this)">
                                <i class="fa-solid <?= $btnIcon ?>"></i> <?= $btnTxt ?>
                            </button>
                        <?php endif; endif; ?>
                    </div>
                </div>
            <?php
        }
    }

    $html = ob_get_clean();

    ob_start();
    if ($totalPages > 1) {
        echo '<div class="pagination-controls" style="grid-column: 1/-1; display: flex; justify-content: center; gap: 10px; margin-top: 30px; margin-bottom: 20px;">';
        if ($page > 1) {
            echo '<button class="btn-action-vault" style="padding: 10px 18px; border-radius: 12px; cursor: pointer; font-weight: bold;" onclick="loadMarketPage(' . ($page - 1) . ')"><i class="fa-solid fa-chevron-left"></i></button>';
        }

        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);

        for ($i = $startPage; $i <= $endPage; $i++) {
            $activeStyle = ($i == $page) ? 'background: var(--primary-color); color: #000; border-color: var(--primary-color); transform: scale(1.05);' : '';
            echo '<button class="btn-action-vault" style="padding: 10px 18px; border-radius: 12px; cursor: pointer; font-weight: bold; transition: 0.2s; ' . $activeStyle . '" onclick="loadMarketPage(' . $i . ')">' . $i . '</button>';
        }

        if ($page < $totalPages) {
            echo '<button class="btn-action-vault" style="padding: 10px 18px; border-radius: 12px; cursor: pointer; font-weight: bold;" onclick="loadMarketPage(' . ($page + 1) . ')"><i class="fa-solid fa-chevron-right"></i></button>';
        }
        echo '</div>';
    }
    $paginationHtml = ob_get_clean();

    echo json_encode(['html' => $html, 'pagination' => $paginationHtml]);
    exit;
}

$pageTitle = __('webshop_title');
require_once 'includes/header.php';

// --- OVERENIE ČI JE MODUL AKTÍVNY NA ZVOLENOM SERVERY ---
if (empty($activeServer) || !in_array('corewebshop', $activeWebModules ?? [])) {
    echo "<script>window.location.href = 'index.php';</script>";
    exit;
}

$activeNick = $isGuest ? __('webshop_guest_label') : ($currentUser['username'] ?? __('webshop_player_default'));

$playerBalance = 0.00;
$vaultItems = [];
$myListings = [];
$historyLog = [];

if (!$isGuest) {
    // 1. NAČÍTANIE ZOSTATKU HRÁČA
    $stmtBal = $pdo->prepare("SELECT vault_balance FROM coresync_player_data WHERE uuid = ? AND server_name = ? LIMIT 1");
    $stmtBal->execute([$uuid, $activeServerName]);
    $rowBal = $stmtBal->fetch();
    if ($rowBal) {
        $playerBalance = (float)$rowBal['vault_balance'];
    }

    // 4. NAČÍTANIE SKLADU (Vault)
    $stmtVault = $pdo->prepare("SELECT * FROM corewebshop_vault WHERE uuid = ? AND server_name = ? ORDER BY id DESC");
    $stmtVault->execute([$uuid, $activeServerName]);
    $vaultItems = $stmtVault->fetchAll(PDO::FETCH_ASSOC);

    // 5. NAČÍTANIE MOJICH PONÚK (Listings & Pending)
    $stmtMyListings = $pdo->prepare("SELECT * FROM corewebshop_listings WHERE seller_uuid = ? AND status IN ('active', 'pending') AND server_name = ? ORDER BY id DESC");
    $stmtMyListings->execute([$uuid, $activeServerName]);
    $myListings = $stmtMyListings->fetchAll(PDO::FETCH_ASSOC);

    // 6. NAČÍTANIE HISTÓRIE
    $stmtHistory = $pdo->prepare("SELECT * FROM corewebshop_history WHERE (buyer_uuid = ? OR seller_uuid = ?) AND server_name = ? ORDER BY created_at DESC LIMIT 50");
    $stmtHistory->execute([$uuid, $uuid, $activeServerName]);
    $historyLog = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!-- Načítanie pluginu Choices.js -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css" />
<script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>

<style>
/* Ponechané pôvodné CSS bez zmien */
.shop-container { max-width: 1400px; margin: 40px auto 120px auto; padding: 0 20px; }
.shop-hero { position: relative; background: linear-gradient(135deg, color-mix(in srgb, var(--primary-color) 15%, transparent) 0%, rgba(12, 12, 12, 0.8) 100%); border: 1px solid var(--border-color); border-radius: 24px; padding: 40px 50px; margin-bottom: 40px; backdrop-filter: blur(15px); box-shadow: 0 20px 40px rgba(0,0,0,0.5); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; }
.shop-hero-text h1 { font-size: 38px; font-weight: 900; text-transform: uppercase; margin: 0 0 10px 0; color: #fff; }
.shop-hero-text h1 span { color: var(--primary-color); }
.shop-hero-text p { color: #aaa; margin: 0; font-size: 15px; max-width: 500px; line-height: 1.6; }
.shop-balance-card { background: rgba(0,0,0,0.4); border: 1px solid color-mix(in srgb, var(--primary-color) 30%, transparent); padding: 20px 30px; border-radius: 18px; text-align: right; box-shadow: inset 0 0 20px rgba(0,0,0,0.5); }
.shop-balance-card .label { font-size: 12px; font-weight: 800; color: #888; text-transform: uppercase; margin-bottom: 5px; }
.shop-balance-card .amount { font-size: 28px; font-weight: 900; color: #4ade80; display: flex; align-items: center; gap: 10px; justify-content: flex-end; }
.shop-balance-card .amount-guest { color: #aaa; font-size: 24px; }
.lock-warning { background: rgba(255, 68, 68, 0.1); border: 1px dashed rgba(255, 68, 68, 0.3); color: #ff4444; padding: 20px; border-radius: 16px; margin-bottom: 30px; display: flex; align-items: center; gap: 15px; font-size: 14px; font-weight: bold; }
.lock-warning i { font-size: 24px; }

/* Flexibilný obal pre taby a tlačidlo nápovedy */
.shop-tabs-container { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 30px; }
.shop-tabs { display: flex; gap: 12px; overflow-x: auto; padding-bottom: 5px; flex: 1; }
.shop-tab { background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); color: #aaa; padding: 14px 25px; border-radius: 14px; font-weight: 800; cursor: pointer; transition: 0.3s; text-transform: uppercase; font-size: 13px; display: flex; align-items: center; gap: 8px; white-space: nowrap; }
.shop-tab:hover { background: rgba(255,255,255,0.08); color: #fff; }
.shop-tab.active { background: var(--primary-color); color: #000; border-color: var(--primary-color); box-shadow: 0 5px 20px color-mix(in srgb, var(--primary-color) 40%, transparent); }
.shop-tab .badge { background: rgba(0,0,0,0.5); color: #fff; padding: 2px 8px; border-radius: 6px; font-size: 11px; margin-left: 5px; }
.shop-tab.active .badge { background: rgba(0,0,0,0.8); color: var(--primary-color); }

.shop-filters { display: flex; gap: 15px; margin-bottom: 30px; background: rgba(0,0,0,0.3); padding: 15px; border-radius: 16px; border: 1px solid var(--border-color); flex-wrap: wrap; align-items: center; }
.shop-search { flex: 1; min-width: 250px; background: rgba(0,0,0,0.5); border: 1px solid var(--border-color); color: #fff; padding: 12px 20px; border-radius: 10px; outline: none; font-family: inherit; font-size: 14px; box-sizing: border-box; }
.shop-search:focus { border-color: var(--primary-color); }
.shop-select { background: rgba(0,0,0,0.5); border: 1px solid var(--border-color); color: #fff; padding: 12px 20px; border-radius: 10px; outline: none; font-family: inherit; cursor: pointer; }
.item-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin-top: 25px; }
.item-card { background: rgba(12,12,12,0.7); border: 1px solid var(--border-color); border-radius: 20px; padding: 25px; position: relative; transition: 0.3s; display: flex; flex-direction: column; justify-content: space-between; backdrop-filter: blur(10px); box-sizing: border-box; }
.item-card:hover { transform: translateY(-6px); border-color: var(--primary-color); box-shadow: 0 15px 35px color-mix(in srgb, var(--primary-color) 15%, transparent); background: rgba(15,15,15,0.9); z-index: 10; }
.item-card.pending { border-color: #ffaa00; opacity: 0.8; }
.item-card.pending:hover { transform: none; box-shadow: none; cursor: not-allowed; }
.item-card.sold-item { opacity: 0.45; filter: grayscale(90%); border-style: dashed; }
.item-card.sold-item:hover { transform: none; box-shadow: none; border-color: var(--border-color); cursor: not-allowed; background: rgba(12,12,12,0.7); }
.item-card.special { border: 1px solid rgba(255, 85, 255, 0.4); background: radial-gradient(circle at top, rgba(255, 85, 255, 0.08) 0%, rgba(12,12,12,0.8) 100%); }
.item-card.special:hover { border-color: #ff55ff; box-shadow: 0 15px 40px rgba(255, 85, 255, 0.2); }
.special-badge { position: absolute; top: -14px; left: 50%; transform: translateX(-50%); background: linear-gradient(135deg, #ff55ff, #aa00ff); color: #fff; font-size: 11px; font-weight: 900; padding: 6px 16px; border-radius: 10px; text-transform: uppercase; letter-spacing: 1px; box-shadow: 0 5px 15px rgba(170,0,255,0.4); z-index: 10; display: flex; align-items: center; gap: 6px; white-space: nowrap; }
.item-server { position: absolute; top: 15px; left: 15px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); font-size: 10px; font-weight: 900; padding: 4px 8px; border-radius: 6px; color: var(--primary-color); text-transform: uppercase; z-index: 2; }
.item-amount { position: absolute; top: 15px; right: 15px; background: #fff; color: #000; font-size: 12px; font-weight: 900; padding: 4px 10px; border-radius: 8px; box-shadow: 0 2px 10px rgba(255,255,255,0.2); z-index: 2; }
.item-amount .stock { color: #666; font-size: 10px; font-weight: 700; margin-left: 4px; }
.item-amount.infinite { background: linear-gradient(135deg, #ffaa00, #ffdd00); color: #000; font-size: 14px; padding: 2px 10px; }
.item-status { position: absolute; top: 15px; left: 15px; font-size: 10px; font-weight: 900; padding: 4px 8px; border-radius: 6px; text-transform: uppercase; z-index: 2; }
.status-pending { background: rgba(255, 170, 0, 0.2); border: 1px solid #ffaa00; color: #ffaa00; }
.status-active { background: rgba(74, 222, 128, 0.2); border: 1px solid #4ade80; color: #4ade80; }
.vault-checkbox { position: absolute; top: 15px; left: 15px; z-index: 5; }
.vault-checkbox input[type="checkbox"] { width: 22px; height: 22px; cursor: pointer; accent-color: var(--primary-color); }
.item-visual { height: 120px; display: flex; align-items: center; justify-content: center; margin: 15px 0 10px 0; background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%); border-radius: 50%; }
.bundle-visual { display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: -10px; height: 120px; }
.item-name { font-size: 17px; font-weight: 900; color: #fff; margin-bottom: 6px; text-align: center; }
.item-seller { font-size: 12px; color: #888; text-align: center; margin-bottom: 20px; display: flex; align-items: center; justify-content: center; gap: 8px; }
.item-seller img { width: 20px; height: 20px; border-radius: 4px; }
.admin-shop-seller { color: #ffaa00; font-weight: 900; text-transform: uppercase; letter-spacing: 0.5px; }
.shop-item-wrapper { position: relative; display: inline-flex; cursor: help; align-items: center; justify-content: center; margin-right: 5px; }
.item-icon-img { image-rendering: pixelated; object-fit: contain; filter: drop-shadow(0 4px 8px rgba(0,0,0,0.5)); transition: transform 0.2s; }
@keyframes enchantGlow { 0% { filter: drop-shadow(0 0 5px rgba(170, 0, 255, 0.6)) contrast(1.1); } 100% { filter: drop-shadow(0 0 15px rgba(170, 0, 255, 1)) contrast(1.2) brightness(1.2); } }
.enchanted-item { animation: enchantGlow 2s infinite alternate; }
.mc-tooltip { position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); background: rgba(16, 0, 16, 0.95); border: 2px solid #220044; border-radius: 6px; padding: 10px 14px; z-index: 1000; visibility: hidden; opacity: 0; pointer-events: none; transition: 0.2s opacity, 0.2s margin-bottom; margin-bottom: 0px; box-shadow: 0 10px 30px rgba(0,0,0,0.8); min-width: max-content; text-align: left; }
.mc-tooltip-rare { border-color: #5500AA; box-shadow: 0 0 15px rgba(85,0,170,0.5), 0 10px 30px rgba(0,0,0,0.8); }
.shop-item-wrapper:hover { z-index: 50; }
.shop-item-wrapper:hover .mc-tooltip { visibility: visible; opacity: 1; margin-bottom: 10px; }
.shop-item-wrapper:hover .item-icon-img { transform: scale(1.1); }
.mc-tooltip-name { font-weight: 800; font-size: 14px; margin-bottom: 5px; font-family: monospace, sans-serif; text-shadow: 1px 1px 0 #000; }
.mc-color-aqua { color: #55FFFF; }
.mc-color-white { color: #FFFFFF; }
.mc-tooltip-enchant { color: #55FFFF; font-size: 13px; font-family: monospace, sans-serif; margin-bottom: 2px; }
.bundle-visual .shop-item-wrapper { margin: 0 -8px; transition: 0.3s; z-index: 1; }
.item-card:hover .bundle-visual .shop-item-wrapper { margin: 0 5px; transform: scale(1.05); z-index: 5; }
.price-money { display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 20px; font-weight: 900; color: #4ade80; margin-bottom: 20px; background: rgba(74, 222, 128, 0.05); padding: 10px; border-radius: 12px; border: 1px dashed rgba(74, 222, 128, 0.2); }
.price-barter { display: flex; flex-direction: column; align-items: center; gap: 8px; font-size: 13px; color: #ffaa00; margin-bottom: 20px; background: rgba(255, 170, 0, 0.05); padding: 10px; border-radius: 12px; border: 1px dashed rgba(255, 170, 0, 0.2); }
.barter-req-row { display: flex; align-items: center; gap: 8px; font-weight: 800; color: #fff; background: rgba(0,0,0,0.4); padding: 4px 12px; border-radius: 8px; }
.btn-action-shop { background: var(--primary-color); color: #000; border: none; padding: 14px 20px; width: 100%; border-radius: 12px; font-weight: 900; text-transform: uppercase; cursor: pointer; transition: 0.2s; font-size: 14px; display: flex; align-items: center; justify-content: center; gap: 10px; box-sizing: border-box; }
.btn-action-shop:hover { background: #fff; box-shadow: 0 0 15px rgba(255,255,255,0.4); transform: scale(1.02); }
.btn-action-barter { background: #ffaa00; }
.btn-action-special { background: #ff55ff; color: #fff; }
.btn-action-special:hover { background: #fff; color: #000; box-shadow: 0 0 20px rgba(255,85,255,0.5); }
.btn-action-vault { background: rgba(255,255,255,0.05); color: #fff; border: 1px solid var(--border-color); }
.btn-action-vault:hover { background: var(--primary-color); color: #000; border-color: var(--primary-color); }
.btn-action-cancel { background: rgba(255,68,68,0.1); color: #ff4444; border: 1px solid rgba(255,68,68,0.3); }
.btn-action-cancel:hover { background: #ff4444; color: #fff; }
.btn-action-disabled { background: rgba(255,255,255,0.05); color: #666; cursor: not-allowed; border: 1px dashed rgba(255,255,255,0.1); }
.btn-action-disabled:hover { transform: none; box-shadow: none; background: rgba(255,255,255,0.05); color: #666; }
table { width: 100%; border-collapse: collapse; min-width: 800px; }
th, td { padding: 15px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 14px; color: #ccc; }
th { color: var(--primary-color); text-transform: uppercase; font-size: 12px; font-weight: 800; background: rgba(0,0,0,0.3); }
tr:hover td { background: rgba(255,255,255,0.02); }
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); z-index: 9999; align-items: center; justify-content: center; padding: 20px; overflow-y: auto; }
.modal-box { background: rgba(15, 15, 15, 0.95); border: 1px solid var(--primary-color); border-radius: 24px; padding: 35px; width: 100%; max-width: 650px; position: relative; box-shadow: 0 25px 50px rgba(0,0,0,0.8); margin: auto; }
.sell-options { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 25px 0; }
.sell-option-btn { background: rgba(0,0,0,0.4); border: 2px solid var(--border-color); padding: 20px; border-radius: 16px; text-align: center; cursor: pointer; transition: 0.2s; }
.sell-option-btn:hover { border-color: rgba(255,255,255,0.3); }
.sell-option-btn.active { border-color: var(--primary-color); background: color-mix(in srgb, var(--primary-color) 5%, transparent); }
.sell-option-btn i { font-size: 28px; margin-bottom: 12px; color: #aaa; transition: 0.2s; }
.sell-option-btn.active i { color: var(--primary-color); }
.buy-calculator { background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.1); border-radius: 16px; padding: 20px; margin: 25px 0; display: flex; justify-content: space-between; align-items: center; }
.calc-controls { display: flex; align-items: center; gap: 15px; }
.calc-btn { width: 44px; height: 44px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); color: #fff; font-size: 20px; border-radius: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.2s; }
.calc-btn:hover:not(:disabled) { background: var(--primary-color); color: #000; border-color: var(--primary-color); }
.calc-btn:disabled { opacity: 0.3; cursor: not-allowed; }
.calc-input { width: 80px; height: 44px; background: rgba(0,0,0,0.6); border: 1px solid var(--primary-color); color: var(--primary-color); font-size: 20px; font-weight: 900; text-align: center; border-radius: 12px; outline: none; }
.calc-result { background: rgba(74,222,128,0.1); border: 1px solid rgba(74,222,128,0.3); padding: 15px; border-radius: 16px; text-align: center; margin-bottom: 25px; }
.proc-spinner { width: 70px; height: 70px; border: 5px solid rgba(0,191,255,0.15); border-top-color: var(--primary-color); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 25px auto; }
@keyframes spin { 100% { transform: rotate(360deg); } }
.proc-step { display: flex; align-items: center; gap: 12px; color: #666; margin-bottom: 15px; font-weight: 800; font-size: 14px; transition: 0.3s; }
.proc-step.active { color: #fff; }
.proc-step.active i { color: var(--primary-color); }
.proc-step.done { color: #4ade80; }
.proc-step.done i { color: #4ade80; }
.vault-action-bar { position: fixed; bottom: -150px; left: 50%; transform: translateX(-50%); background: rgba(15,15,15,0.95); border: 1px solid var(--primary-color); padding: 15px 30px; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.8); display: flex; align-items: center; gap: 30px; transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); z-index: 100; backdrop-filter: blur(10px); opacity: 0; visibility: hidden; pointer-events: none; }
.vault-action-bar.show { bottom: 40px; opacity: 1; visibility: visible; pointer-events: auto; }
#iconModalOverlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.9); backdrop-filter: blur(10px); z-index: 10000; align-items: center; justify-content: center; padding: 20px; }
#iconModal { background: rgba(15, 15, 15, 0.95); border: 1px solid var(--primary-color); border-radius: 20px; width: 100%; max-width: 900px; height: 80vh; display: flex; flex-direction: column; box-shadow: 0 20px 50px rgba(0,0,0,0.8); overflow: hidden; }
.icon-modal-top-bar { display: flex; gap: 15px; padding: 20px 25px; border-bottom: 1px solid var(--border-color); background: rgba(0,0,0,0.2); align-items: center; }
.icon-modal-header { padding: 20px 25px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.3); }
.icon-modal-grid { flex: 1; padding: 25px; overflow-y: auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(65px, 1fr)); gap: 12px; align-content: start; }
.icon-item { padding: 10px; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: 12px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s; position: relative; }
.icon-item img { width: 36px; height: 36px; image-rendering: pixelated; object-fit: contain; }
.icon-item .mc-tooltip { bottom: auto; top: 100%; margin-bottom: 0 !important; margin-top: 0px; z-index: 99999 !important; }
.icon-item:hover { transform: scale(1.15); z-index: 10001; border-color: var(--primary-color); background: rgba(255,255,255,0.1); box-shadow: 0 5px 15px color-mix(in srgb, var(--primary-color) 30%, transparent); }
.icon-item:hover .mc-tooltip { margin-top: 10px !important; visibility: visible; opacity: 1; }
.icon-modal-grid::-webkit-scrollbar { width: 8px; }
.icon-modal-grid::-webkit-scrollbar-track { background: rgba(0,0,0,0.2); }
.icon-modal-grid::-webkit-scrollbar-thumb { background: var(--primary-color); border-radius: 4px; }
.choices { margin-bottom: 0; width: 100%; }
.choices[data-type*="select-one"] .choices__inner { background-color: rgba(0,0,0,0.6) !important; border: 1px solid var(--border-color) !important; border-radius: 12px !important; color: #fff !important; padding: 10px 15px !important; min-height: 52px !important; display: flex; align-items: center; }
.choices.is-focused .choices__inner, .choices.is-open .choices__inner { border-color: var(--primary-color) !important; box-shadow: 0 0 10px color-mix(in srgb, var(--primary-color) 20%, transparent) !important; }
.choices__list--single { padding: 0 !important; color: #fff !important; font-weight: 800; }
.choices__list--dropdown { background-color: rgba(15,15,15,0.98) !important; border: 1px solid var(--primary-color) !important; border-radius: 12px !important; color: #fff !important; z-index: 10005 !important; box-shadow: 0 10px 30px rgba(0,0,0,0.8) !important; padding: 5px !important; word-break: break-word; }
.choices__list--dropdown .choices__item { background-color: transparent !important; color: #ccc !important; font-size: 14px !important; border-radius: 8px !important; padding: 10px 15px !important; transition: background-color 0.2s, color 0.2s; }
.choices__list--dropdown .choices__item.is-highlighted, .choices__list--dropdown .choices__item--selectable.is-highlighted, .choices__list--dropdown .choices__item[aria-selected="true"] { background-color: color-mix(in srgb, var(--primary-color) 20%, transparent) !important; color: var(--primary-color) !important; font-weight: 800 !important; }
.choices__input.choices__input--cloned { background-color: rgba(0,0,0,0.5) !important; border: 1px solid rgba(255,255,255,0.1) !important; color: #fff !important; border-radius: 8px !important; padding: 10px 15px !important; font-family: inherit !important; margin-bottom: 10px !important; }
.choices__input.choices__input--cloned::placeholder { color: #888 !important; }
.choices[data-type*="select-one"]::after { border-color: #888 transparent transparent transparent !important; right: 15px !important; }
.choices.is-open[data-type*="select-one"]::after { border-color: border-color: transparent transparent #888 transparent !important; margin-top: -7.5px !important; }
.sell-quantity-wrap { display: flex; align-items: center; gap: 10px; background: rgba(0,0,0,0.5); border: 1px solid var(--border-color); padding: 5px 15px; border-radius: 12px; transition: 0.2s; }
.sell-quantity-wrap:focus-within { border-color: var(--primary-color); box-shadow: 0 0 10px color-mix(in srgb, var(--primary-color) 20%, transparent); }
.sell-quantity-input { width: 100%; background: transparent; border: none; color: #fff; font-size: 18px; font-weight: 900; outline: none; font-family: inherit; }

/* Help Modal špecifické štýly */
.help-section { margin-bottom: 25px; background: rgba(0,0,0,0.3); padding: 20px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05); }
.help-section h4 { color: var(--primary-color); font-size: 16px; margin: 0 0 10px 0; display: flex; align-items: center; gap: 10px; }
.help-section p { margin: 0 0 10px 0; }
.help-section ul { margin: 0; padding-left: 20px; }
.help-section li { margin-bottom: 8px; }
</style>

<div class="shop-container">

    <div class="shop-hero">
        <div class="shop-hero-text">
            <h1><?= __('webshop_title') ?></h1>
            <p><?= __('webshop_desc') ?></p>
        </div>
        <div class="shop-balance-card">
            <div class="label"><?= __('webshop_balance') ?></div>
            <?php if ($isGuest): ?>
                <div class="amount amount-guest"><i class="fa-solid fa-user-lock"></i> <?= __('webshop_guest_balance') ?></div>
            <?php else: ?>
                <div class="amount" id="playerBalanceDisplay"><i class="fa-solid fa-wallet"></i> <?= number_format($playerBalance, 2) ?> $</div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isVaultOpen): ?>
        <div class="lock-warning">
            <i class="fa-solid fa-lock"></i>
            <div><?= __('webshop_locked_warning') ?></div>
        </div>
    <?php endif; ?>

    <div class="shop-tabs-container">
        <div class="shop-tabs">
            <div class="shop-tab active" id="tab_market" onclick="switchTab('market', this)"><i class="fa-solid fa-store"></i> <?= __('webshop_tab_market') ?></div>
            <?php if (!$isGuest): ?>
            <div class="shop-tab" id="tab_vault" onclick="switchTab('vault', this)"><i class="fa-solid fa-box-open"></i> <?= __('webshop_tab_vault') ?> <span class="badge"><?= count($vaultItems) ?></span></div>
            <div class="shop-tab" id="tab_listings" onclick="switchTab('listings', this)"><i class="fa-solid fa-tags"></i> <?= __('webshop_tab_listings') ?> <span class="badge"><?= count($myListings) ?></span></div>
            <div class="shop-tab" id="tab_history" onclick="switchTab('history', this)"><i class="fa-solid fa-clock-rotate-left"></i> <?= __('webshop_tab_history') ?></div>
            <?php endif; ?>
        </div>

        <button class="btn-action-shop" style="width: auto; padding: 12px 20px; font-size: 13px;" onclick="document.getElementById('helpModal').style.display='flex'">
            <i class="fa-solid fa-circle-question"></i> <?= __('btn_help') ?? 'Nápoveda' ?>
        </button>
    </div>

    <!-- POHĽAD 1: TRHOVISKO (Market) -->
    <div id="view_market" style="display: block;">
        <div class="shop-filters">
            <div style="font-size: 13px; color: #aaa; background: rgba(0,0,0,0.5); padding: 12px 20px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.1);">
                <?= __('webshop_filter_server') ?> <strong style="color: var(--primary-color);"><?= htmlspecialchars($activeServerName) ?></strong>
            </div>
            <input type="text" class="shop-search" id="marketSearch" onkeyup="filterMarket()" placeholder="<?= __('webshop_search_ph') ?>">
            <select class="shop-select" id="marketSellerFilter" onchange="filterMarket()">
                <option value="all"><?= __('webshop_filter_all_sellers') ?></option>
                <option value="players"><?= __('webshop_filter_players') ?></option>
                <option value="admin"><?= __('webshop_filter_admin') ?></option>
            </select>
            <select class="shop-select" id="marketPriceFilter" onchange="filterMarket()">
                <option value="all"><?= __('webshop_filter_all_prices') ?></option>
                <option value="money"><?= __('webshop_filter_money') ?></option>
                <option value="barter"><?= __('webshop_filter_barter') ?></option>
            </select>
            <label style="color:#aaa; font-size:13px; display:flex; align-items:center; gap:8px; cursor:pointer; background:rgba(0,0,0,0.3); padding:12px 15px; border-radius:10px; border:1px solid rgba(255,255,255,0.05); font-weight:700;">
                <input type="checkbox" id="showSoldToggle" onchange="filterMarket()" style="accent-color: var(--primary-color); width:18px; height:18px; cursor:pointer;"> <?= __('webshop_show_sold') ?>
            </label>
        </div>

        <div id="marketGridContainer" style="position: relative; min-height: 300px;">
            <div id="marketLoader" style="display: flex; position: absolute; inset: 0; background: rgba(15,15,15,0.8); backdrop-filter: blur(5px); z-index: 50; align-items: center; justify-content: center; border-radius: 20px;">
                <div style="text-align: center;">
                    <div class="proc-spinner" style="margin-bottom: 15px;"></div>
                    <div style="color: var(--primary-color); font-weight: 800; text-transform: uppercase;"><?= __('webshop_loading_market') ?></div>
                </div>
            </div>
            <div class="item-grid" id="marketGrid">
                <!-- Položky sa načítavajú dynamicky cez AJAX -->
            </div>
            <div id="marketPagination"></div>
        </div>
    </div>

    <?php if (!$isGuest): ?>
    <!-- POHĽAD 2: MÔJ VIRTUÁLNY SKLAD (Vault) -->
    <div id="view_vault" style="display: none;">
        <div style="background: rgba(0,191,255,0.05); border: 1px dashed rgba(0,191,255,0.3); padding: 20px; border-radius: 16px; margin-bottom: 30px; display: flex; align-items: center; gap: 15px;">
            <i class="fa-solid fa-circle-info" style="font-size: 24px; color: var(--primary-color);"></i>
            <p style="margin: 0; color: #ccc; font-size: 14px; line-height: 1.6;"><?= __('webshop_vault_info') ?></p>
        </div>

        <div class="item-grid">
            <?php if (empty($vaultItems)): ?>
                <div style="grid-column: 1/-1; text-align: center; padding: 40px; color: #888;"><?= __('webshop_no_vault_items') ?></div>
            <?php endif; ?>

            <?php foreach ($vaultItems as $vItem):
                $parsedNBT = parseForgeNbt($vItem['item_nbt'] ?? '');
                $customName = $parsedNBT['custom_name'];
                $name = !empty($customName) ? $customName : formatMcName($vItem['item_id']);
                $safeName = htmlspecialchars(json_encode($name), ENT_QUOTES, 'UTF-8');
            ?>
                <div class="item-card">
                    <div class="vault-checkbox"><input type="checkbox" onchange="updateBundleSelection(this, <?= $vItem['id'] ?>, <?= $safeName ?>, '<?= $vItem['item_id'] ?>', <?= $vItem['amount'] ?>)"></div>
                    <div class="item-amount"><?= $vItem['amount'] ?>x</div>
                    <div class="item-visual"><?= renderShopItem($vItem['item_id'], $name, $activeServerName, [], 70, 1, $vItem['item_nbt'] ?? null) ?></div>
                    <div>
                        <div class="item-name"><?= htmlspecialchars($name) ?></div>
                        <div class="item-seller" style="color: #4ade80;"><i class="fa-solid fa-check-circle"></i> <?= __('webshop_vault_label') ?> <?= htmlspecialchars($activeServerName) ?></div>
                        <button class="btn-action-shop btn-action-vault" <?= $isVaultOpen ? 'disabled style="opacity:0.5;"' : '' ?> onclick="openSellModal(<?= $vItem['id'] ?>, <?= $safeName ?>, '<?= $vItem['item_id'] ?>', <?= $vItem['amount'] ?>)"><i class="fa-solid fa-tag"></i> <?= __('webshop_btn_sell_single') ?></button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- POHĽAD 3: MOJE PONUKY -->
    <div id="view_listings" style="display: none;">
        <div class="item-grid">
            <?php if (empty($myListings)): ?>
                <div style="grid-column: 1/-1; text-align: center; padding: 40px; color: #888;"><?= __('webshop_no_my_listings') ?></div>
            <?php endif; ?>

            <?php foreach ($myListings as $mL):
                $isPending = ($mL['status'] === 'pending');
                $isBundle = ($mL['listing_type'] === 'bundle');

                $parsedNBT = parseForgeNbt($mL['item_nbt'] ?? '');
                $customName = $parsedNBT['custom_name'];
                $nameDisplay = $isBundle ? __('webshop_bundle_title') : (!empty($customName) ? $customName : ($mL['display_name'] ?: formatMcName($mL['icon_id'])));
            ?>
                <div class="item-card <?= $isPending ? 'pending' : '' ?>">
                    <?php if ($isPending): ?>
                        <div class="item-status status-pending"><i class="fa-solid fa-circle-notch fa-spin"></i> <?= __('webshop_status_processing') ?></div>
                    <?php else: ?>
                        <div class="item-status status-active"><i class="fa-solid fa-circle-check"></i> <?= __('webshop_status_active') ?></div>
                    <?php endif; ?>

                    <div class="item-amount"><?= $isBundle ? __('badge_bundle') : ($mL['stock_available'] * $mL['amount_per_package']) . 'x' ?></div>

                    <div class="item-visual <?= $isBundle ? 'bundle-visual' : '' ?>">
                        <?php if ($isBundle):
                            $stmtBndl = $pdo->prepare("SELECT * FROM corewebshop_bundle_items WHERE listing_id = ?");
                            $stmtBndl->execute([$mL['id']]);
                            foreach ($stmtBndl->fetchAll() as $bItem) {
                                echo renderShopItem($bItem['item_id'], $bItem['display_name'] ?: formatMcName($bItem['item_id']), $activeServerName, [], 48, $bItem['amount'], $bItem['item_nbt'] ?? null);
                            }
                        else:
                            echo renderShopItem($mL['icon_id'], $nameDisplay, $activeServerName, [], 70, $mL['amount_per_package'], $mL['item_nbt'] ?? null);
                        endif; ?>
                    </div>

                    <div>
                        <div class="item-name" style="<?= $isBundle ? 'color: #ffaa00;' : '' ?>"><?= htmlspecialchars($nameDisplay) ?></div>
                        <?php if ($mL['price_type'] === 'money'): ?>
                            <div class="price-money" style="<?= $isPending ? 'color: #888; border-color: #555; background: rgba(255,255,255,0.05);' : '' ?>"><i class="fa-solid fa-coins"></i> <?= number_format($mL['price_money'], 2) ?> $</div>
                        <?php else: ?>
                            <div class="price-barter" style="<?= $isPending ? 'color: #888; border-color: #555; background: rgba(255,255,255,0.05);' : '' ?>"><i class="fa-solid fa-handshake"></i> <?= __('admin_barter_trade') ?></div>
                        <?php endif; ?>

                        <?php if ($isPending || $isVaultOpen): ?>
                            <button class="btn-action-shop btn-action-disabled" disabled><?= $isVaultOpen ? __('webshop_btn_locked') : __('webshop_btn_someone_buying') ?></button>
                        <?php else: ?>
                            <button class="btn-action-shop btn-action-cancel" onclick="cancelListing(<?= $mL['id'] ?>)"><i class="fa-solid fa-xmark"></i> <?= __('webshop_btn_cancel_offer') ?></button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- POHĽAD 4: HISTÓRIA -->
    <div id="view_history" style="display: none;">
        <div style="background: rgba(12,12,12,0.8); border: 1px solid var(--border-color); border-radius: 20px; overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th><?= __('table_date') ?></th>
                        <th><?= __('table_type_server') ?></th>
                        <th><?= __('table_item_display') ?></th>
                        <th><?= __('table_price') ?></th>
                        <th><?= __('table_seller') ?> / <?= __('table_buyer') ?></th>
                        <th><?= __('table_status') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($historyLog)): ?>
                        <tr><td colspan="6" style="text-align: center; color: #888; padding: 40px;"><?= __('webshop_no_history') ?></td></tr>
                    <?php endif; ?>

                    <?php foreach ($historyLog as $log):
                        $isPurchase = ($log['buyer_uuid'] === $uuid);
                        $otherUuid = $isPurchase ? $log['seller_uuid'] : $log['buyer_uuid'];
                        $otherName = getSellerName($otherUuid, $pdo);
                        $isBarter = ($log['action_type'] === 'barter_trade');
                    ?>
                        <tr>
                            <td><?= date('d. M H:i', strtotime($log['created_at'])) ?></td>
                            <td>
                                <?php if ($isPurchase && $isBarter): ?>
                                    <span style="background: rgba(255,170,0,0.1); color: #ffaa00; padding: 4px 8px; border-radius: 6px; font-weight: 800; font-size: 11px;"><?= __('webshop_type_barter') ?></span>
                                <?php elseif ($isPurchase): ?>
                                    <span style="background: rgba(255,68,68,0.1); color: #ff4444; padding: 4px 8px; border-radius: 6px; font-weight: 800; font-size: 11px;"><?= __('webshop_type_purchase') ?></span>
                                <?php else: ?>
                                    <span style="background: rgba(74,222,128,0.1); color: #4ade80; padding: 4px 8px; border-radius: 6px; font-weight: 800; font-size: 11px;"><?= __('webshop_type_sale') ?></span>
                                <?php endif; ?>
                            </td>
                            <td><strong style="color:#fff;"><?= htmlspecialchars($log['item_summary']) ?></strong></td>
                            <td style="color: <?= $isBarter ? '#ffaa00' : ($isPurchase ? '#ff4444' : '#4ade80') ?>; font-weight: 800;"><?= ($isPurchase && !$isBarter) ? '- ' : '+ ' ?><?= htmlspecialchars($log['cost_summary']) ?></td>
                            <td>
                                <?php if ($otherUuid === 'ADMIN'): ?>
                                    <i class="fa-solid fa-server" style="color: #ffaa00;"></i> <?= __('webshop_admin_shop') ?>
                                <?php else: ?>
                                    <img src="<?= getPlayerAvatar($otherName, 16) ?>" style="border-radius:4px; vertical-align:middle;"> <?= htmlspecialchars($otherName) ?>
                                <?php endif; ?>
                            </td>
                            <td><span style="color: #888;"><i class="fa-solid fa-check"></i> <?= __('webshop_status_completed') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (!$isGuest): ?>
<div id="vaultActionBar" class="vault-action-bar">
    <div>
        <div style="font-size: 12px; color: #888; text-transform: uppercase; font-weight: 800;"><?= __('webshop_marked_items') ?></div>
        <div style="font-size: 24px; font-weight: 900; color: #fff;" id="bundleCountText">0</div>
    </div>
    <div style="display: flex; gap: -5px;" id="bundlePreviewIcons"></div>
    <button class="btn-action-shop" style="width: auto; padding: 12px 25px;" onclick="openBundleSellModal()">
        <i class="fa-solid fa-boxes-packing"></i> <?= __('webshop_btn_sell_bundle') ?>
    </button>
</div>

<!-- MODAL 1: VYSTAVENIE NA TRH -->
<div id="sellModal" class="modal-overlay">
    <div class="modal-box">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="margin: 0; font-weight: 900; color: #fff;"><?= __('webshop_modal_sell_title') ?> <span style="color: var(--primary-color);" id="sellItemName"><?= __('webshop_placeholder_item') ?></span></h2>
            <i class="fa-solid fa-xmark" style="font-size: 24px; cursor: pointer; color: #aaa; transition: 0.2s;" onclick="this.closest('#sellModal').style.display='none'"></i>
        </div>

        <input type="hidden" id="sellItemType" value="standard">
        <input type="hidden" id="sellVaultId" value="">

        <div id="bundleWarning" style="display: none; background: rgba(255,170,0,0.1); border: 1px dashed rgba(255,170,0,0.3); color: #ffaa00; padding: 15px; border-radius: 12px; margin-bottom: 20px; font-size: 13px;">
            <i class="fa-solid fa-boxes-stacked"></i> <strong><?= __('webshop_modal_bundle_warn') ?></strong>
        </div>

        <div id="bundleConfigSection" style="display: none; margin-bottom: 25px;"></div>

        <div id="sellQuantitySection" style="background: rgba(255,255,255,0.02); padding: 15px; border-radius: 16px; border: 1px solid rgba(255,255,255,0.05); margin-bottom: 25px;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 12px;">
                <div>
                    <label style="font-size: 11px; color: #888; text-transform: uppercase; font-weight: 800; display: block; margin-bottom: 6px;"><?= __('webshop_modal_qty_package') ?></label>
                    <div class="sell-quantity-wrap">
                        <input type="number" id="sellAmountPerPackage" min="1" value="1" class="sell-quantity-input">
                    </div>
                </div>
                <div>
                    <label style="font-size: 11px; color: #888; text-transform: uppercase; font-weight: 800; display: block; margin-bottom: 6px;"><?= __('webshop_modal_qty_stock') ?></label>
                    <div class="sell-quantity-wrap">
                        <input type="number" id="sellStockPackages" min="1" value="1" class="sell-quantity-input">
                    </div>
                </div>
            </div>
            <div style="font-size: 12px; color: #aaa; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid rgba(255,255,255,0.05); margin-top: 10px; padding-top: 8px;">
                <span><?= __('webshop_modal_total_vault') ?> <strong id="sellTotalDeducted" style="color: var(--primary-color);">1</strong> ks</span>
                <span><?= __('webshop_modal_max_vault') ?> <strong id="sellMaxStockLabel" style="color: #fff;">0</strong> ks</span>
            </div>
        </div>

        <div class="sell-options">
            <div class="sell-option-btn active" id="btnOptMoney" onclick="switchSellOption('money')">
                <i class="fa-solid fa-coins"></i>
                <div style="font-weight: 800; font-size: 16px; color:#fff;"><?= __('webshop_modal_sell_money') ?></div>
            </div>
            <div class="sell-option-btn" id="btnOptBarter" onclick="switchSellOption('barter')">
                <i class="fa-solid fa-handshake-angle"></i>
                <div style="font-weight: 800; font-size: 16px; color:#fff;"><?= __('webshop_modal_sell_barter') ?></div>
            </div>
        </div>

        <div id="moneyOptions">
            <label style="font-size: 13px; font-weight: bold; color: #888; text-transform: uppercase;"><?= __('webshop_modal_set_price') ?></label>
            <input type="number" id="sellPriceMoney" placeholder="500" style="width: 100%; box-sizing: border-box; background: rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 15px; border-radius: 12px; font-size: 18px; margin-top: 10px; font-weight: bold; outline: none; font-family: inherit;">
        </div>

        <div id="barterOptions" style="display: none;">
            <label style="font-size: 13px; font-weight: bold; color: #888; text-transform: uppercase;"><?= __('webshop_modal_req_barter') ?></label>
            <div id="barterReqList" style="display: flex; flex-direction: column; gap: 10px; margin-top: 10px;">
                <div class="barter-input-row" style="display: flex; gap: 10px; align-items: center;">
                    <div style="width: 48px; height: 48px; background: rgba(0,0,0,0.5); border: 1px solid var(--border-color); border-radius: 12px; display: flex; align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0;" onclick="openIconModal('init_barter_1')" title="Vybrať ikonu">
                        <div id="preview_init_barter_1" style="display:flex; align-items:center; justify-content:center; width:100%; height:100%;"><i class="fa-solid fa-image" style="color: #666; font-size: 20px;"></i></div>
                        <input type="hidden" class="barter-icon-input" id="input_init_barter_1" value="">
                    </div>
                    <div id="name_init_barter_1" class="barter-name-div" style="flex: 1; background: rgba(0,0,0,0.5); border: 1px solid var(--border-color); padding: 14px 15px; border-radius: 12px; color: #888; font-size: 14px; display: flex; align-items: center; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= __('admin_js_none_selected') ?></div>
                    <input type="number" class="barter-amount-input" placeholder="<?= __('admin_js_pieces') ?>" style="width: 100px; box-sizing: border-box; background: rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 14px 15px; border-radius: 12px; outline: none; font-family: inherit;">
                </div>
            </div>
            <button type="button" onclick="addBarterReqRow()" style="background: transparent; border: 1px dashed var(--primary-color); color: var(--primary-color); padding: 12px; border-radius: 12px; width: 100%; margin-top: 10px; cursor: pointer; font-weight: bold; transition: 0.2s;">
                <i class="fa-solid fa-plus"></i> <?= __('webshop_modal_add_req') ?>
            </button>
        </div>

        <button class="btn-action-shop" onclick="submitListing()" style="margin-top: 30px; font-size: 15px; padding: 15px;"><i class="fa-solid fa-tags"></i> <?= __('webshop_modal_btn_confirm_sell') ?></button>
    </div>
</div>

<!-- MODAL 2: KÚPA POTVRDENIE -->
<div id="buyModal" class="modal-overlay">
    <div class="modal-box" style="max-width: 500px;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; text-align: left; padding-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.1);">
            <div style="display: flex; gap: 15px; align-items: center;">
                <div id="buyModalIcon" style="width: 64px; height: 64px; background: rgba(0,0,0,0.5); border: 1px solid var(--border-color); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 28px; box-shadow: inset 0 0 15px rgba(0,0,0,0.5);"></div>
                <div>
                    <h2 style="margin: 0 0 8px 0; font-weight: 900; color: #fff; font-size: 22px;" id="buyModalItemName"><?= __('webshop_placeholder_item') ?></h2>
                    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                        <span id="buyModalServer" style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); font-size: 10px; font-weight: 900; padding: 4px 8px; border-radius: 6px; color: var(--primary-color); text-transform: uppercase;"><?= __('webshop_js_server_label') ?></span>
                        <span id="buyModalSeller" style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 800; color: #888;"></span>
                    </div>
                </div>
            </div>
            <i class="fa-solid fa-xmark" style="font-size: 24px; cursor: pointer; color: #aaa; transition: 0.2s; margin-top: -5px;" onclick="this.closest('#buyModal').style.display='none'"></i>
        </div>

        <input type="hidden" id="buyListingId" value="">

        <p style="color: #aaa; margin-bottom: 20px; line-height: 1.6; text-align: center;"><?= __('webshop_modal_buy_warn') ?> <span id="buyBundleText" style="display:none; color:#ffaa00; font-weight:bold;"><?= __('webshop_modal_buy_warn_bundle') ?></span></p>

        <div id="buyMultiplierSection" class="buy-calculator">
            <div style="text-align: left;">
                <div style="font-weight: 800; font-size: 14px; color: #fff;"><?= __('webshop_modal_buy_qty') ?></div>
                <div style="font-size: 12px; color: #888;"><?= __('webshop_modal_buy_avail') ?> <strong id="buyMaxStock" style="color:#fff;">0</strong></div>
                <div style="font-size: 12px; color: var(--primary-color); margin-top: 4px;"><?= __('webshop_modal_buy_total_get') ?> <strong id="buyTotalItemsReceived">0x</strong></div>
            </div>
            <div class="calc-controls">
                <button class="calc-btn" onclick="changeBuyMultiplier(-1)" id="btnMultMinus"><i class="fa-solid fa-minus"></i></button>
                <input type="number" id="buyMultiplier" class="calc-input" value="1" readonly>
                <button class="calc-btn" onclick="changeBuyMultiplier(1)" id="btnMultPlus"><i class="fa-solid fa-plus"></i></button>
            </div>
        </div>

        <div class="calc-result" id="buyResultMoney">
            <div style="font-size: 12px; color: #888; text-transform: uppercase; font-weight: 900; margin-bottom: 5px;"><?= __('webshop_modal_buy_total_price') ?></div>
            <div id="buyModalTotalPrice" style="font-size: 26px; font-weight: 900; color: #4ade80;">0 $</div>
        </div>

        <div class="calc-result" id="buyResultBarter" style="display: none; background: rgba(255, 170, 0, 0.05); border-color: rgba(255, 170, 0, 0.3);">
            <div style="font-size: 12px; color: #ffaa00; text-transform: uppercase; font-weight: 900; margin-bottom: 15px;"><?= __('webshop_modal_buy_total_barter') ?></div>
            <div id="buyModalTotalBarter" style="display: flex; flex-direction: column; gap: 8px;"></div>
        </div>

        <button class="btn-action-shop" onclick="confirmPurchase()" style="padding: 16px; font-size: 16px;"><i class="fa-solid fa-check"></i> <?= __('webshop_modal_btn_confirm_buy') ?></button>
    </div>
</div>
<?php endif; ?>

<!-- MODAL: NÁPOVEDA (HELP) -->
<div id="helpModal" class="modal-overlay" style="z-index: 10001;">
    <div class="modal-box" style="max-width: 800px; padding: 0; display: flex; flex-direction: column; max-height: 90vh; text-align: left;">
        <div class="icon-modal-header">
            <h3 style="margin: 0; font-size: 20px; font-weight: 900; color: #fff; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-question" style="color: var(--primary-color);"></i> <?= __('webshop_help_title') ?>
            </h3>
            <i class="fa-solid fa-xmark" style="cursor: pointer; font-size: 24px; color: #aaa; transition: 0.2s;" onclick="document.getElementById('helpModal').style.display='none'"></i>
        </div>

        <div style="padding: 30px; overflow-y: auto; color: #ccc; font-size: 14px; line-height: 1.7;">
            <div class="help-section">
                <h4><i class="fa-solid fa-store"></i> <?= __('webshop_help_q1') ?></h4>
                <p><?= __('webshop_help_a1_p') ?></p>
                <ul>
                    <li><?= __('webshop_help_a1_l1') ?></li>
                    <li><?= __('webshop_help_a1_l2') ?></li>
                </ul>
            </div>

            <div class="help-section">
                <h4><i class="fa-solid fa-cart-shopping"></i> <?= __('webshop_help_q2') ?></h4>
                <p><?= __('webshop_help_a2_p') ?></p>
                <ul>
                    <li><?= __('webshop_help_a2_l1') ?></li>
                    <li><?= __('webshop_help_a2_l2') ?></li>
                    <li><?= __('webshop_help_a2_l3') ?></li>
                </ul>
            </div>

            <div class="help-section">
                <h4><i class="fa-solid fa-box-open"></i> <?= __('webshop_help_q3') ?></h4>
                <p><?= __('webshop_help_a3_p') ?></p>
                <ul>
                    <li><?= __('webshop_help_a3_l1') ?></li>
                    <li><?= __('webshop_help_a3_l2') ?></li>
                    <li><?= __('webshop_help_a3_l3') ?></li>
                </ul>
            </div>

            <div class="help-section">
                <h4><i class="fa-solid fa-tag"></i> <?= __('webshop_help_q4') ?></h4>
                <p><?= __('webshop_help_a4_p') ?></p>
                <ul>
                    <li><?= __('webshop_help_a4_l1') ?></li>
                    <li><?= __('webshop_help_a4_l2') ?></li>
                    <li><?= __('webshop_help_a4_l3') ?></li>
                </ul>
            </div>

            <div class="help-section">
                <h4><i class="fa-solid fa-boxes-packing"></i> <?= __('webshop_help_q5') ?></h4>
                <p><?= __('webshop_help_a5_p') ?></p>
                <ul>
                    <li><?= __('webshop_help_a5_l1') ?></li>
                    <li><?= __('webshop_help_a5_l2') ?></li>
                    <li><?= __('webshop_help_a5_l3') ?></li>
                    <li><?= __('webshop_help_a5_l4') ?></li>
                </ul>
            </div>

            <div class="help-section">
                <h4><i class="fa-solid fa-xmark"></i> <?= __('webshop_help_q6') ?></h4>
                <p><?= __('webshop_help_a6_p') ?></p>
                <ul>
                    <li><?= __('webshop_help_a6_l1') ?></li>
                    <li><?= __('webshop_help_a6_l2') ?></li>
                </ul>
            </div>

            <div class="help-section">
                <h4><i class="fa-solid fa-lock"></i> <?= __('webshop_help_q7') ?></h4>
                <p><?= __('webshop_help_a7_p') ?></p>
                <ul>
                    <li><?= __('webshop_help_a7_l1') ?></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: PRE HOSTÍ / NEPRIHLÁSENÝCH HRÁČOV -->
<div id="guestModal" class="modal-overlay" style="z-index: 10001;">
    <div class="modal-box" style="max-width: 450px; text-align: center;">
        <i class="fa-solid fa-user-lock" style="font-size: 64px; color: var(--primary-color); margin-bottom: 20px;"></i>
        <h2 style="margin: 0 0 10px 0; font-weight: 900; color: #fff;"><?= __('webshop_guest_modal_title') ?></h2>
        <p style="color: #aaa; margin-bottom: 25px; font-size: 14px; line-height: 1.6;"><?= __('webshop_guest_modal_desc') ?></p>
        <div style="display: flex; gap: 15px; justify-content: center;">
            <button class="btn-action-shop btn-action-cancel" onclick="document.getElementById('guestModal').style.display='none'" style="width: auto;"><i class="fa-solid fa-xmark"></i> <?= __('webshop_btn_close') ?></button>
            <a href="login.php" class="btn-action-shop" style="width: auto; text-decoration: none; padding-top: 14px; padding-bottom: 14px;"><i class="fa-solid fa-right-to-bracket"></i> <?= __('webshop_btn_login') ?></a>
        </div>
    </div>
</div>

<!-- MODAL: ZAMITNUTÁ TRANSAKCIA (FAILED MODAL) -->
<div id="failedModal" class="modal-overlay" style="z-index: 10001;">
    <div class="modal-box" style="max-width: 450px; text-align: center;">
        <i id="failedModalIcon" class="fa-solid fa-circle-xmark" style="font-size: 64px; color: #ff4444; margin-bottom: 20px;"></i>
        <h2 id="failedModalTitle" style="margin: 0 0 10px 0; font-weight: 900; color: #fff;"><?= __('webshop_modal_fail_title') ?></h2>
        <p style="color: #aaa; margin-bottom: 25px; font-size: 14px; line-height: 1.6;" id="failedErrorText"><?= __('webshop_modal_fail_desc') ?></p>
        <button id="failedModalBtn" class="btn-action-shop" style="background: rgba(255, 68, 68, 0.1); color: #ff4444; border: 1px solid rgba(255, 68, 68, 0.3);" onclick="document.getElementById('failedModal').style.display='none'"><i class="fa-solid fa-xmark"></i> <?= __('webshop_btn_close') ?></button>
    </div>
</div>

<!-- OSTATNÉ MODALY (Ikony, Procesing, Úspech) -->
<div id="iconModalOverlay" class="modal-overlay">
    <div id="iconModal">
        <div class="icon-modal-header">
            <h3 style="margin: 0; font-size: 20px; font-weight: 900; color: #fff; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-icons" style="color: var(--primary-color);"></i> <?= __('admin_icon_modal_title') ?>
            </h3>
            <i class="fa-solid fa-xmark" style="cursor: pointer; font-size: 24px; color: #aaa; transition: 0.2s;" onclick="closeIconModal()"></i>
        </div>
        <div class="icon-modal-top-bar">
            <div style="flex: 1;"><select id="iconCategorySelect" class="shop-select" style="width: 100%;"></select></div>
            <div style="flex: 1;"><input type="text" id="iconSearchInput" placeholder="<?= __('admin_icon_search') ?>" class="shop-search" style="width: 100%; box-sizing: border-box;" onkeyup="searchIcons(this.value)"></div>
        </div>
        <div class="icon-modal-grid" id="iconGrid"></div>
    </div>
</div>

<div id="processingModal" class="modal-overlay" style="z-index: 10001;">
    <div class="modal-box" style="max-width: 450px; text-align: center;">
        <div class="proc-spinner"></div>
        <h2 style="margin: 0 0 10px 0; font-weight: 900; color: #fff;"><?= __('webshop_modal_proc_title') ?></h2>
        <p style="color: #ffaa00; font-size: 13px; font-weight: 700; margin-bottom: 25px; padding: 10px; background: rgba(255,170,0,0.1); border-radius: 10px; border: 1px solid rgba(255,170,0,0.2);">
            <i class="fa-solid fa-triangle-exclamation"></i> <?= __('webshop_modal_proc_warn') ?>
        </p>
        <div style="text-align: left; background: rgba(0,0,0,0.4); padding: 20px; border-radius: 16px; border: 1px solid var(--border-color);">
            <div id="procStep1" class="proc-step active"><i class="fa-solid fa-spinner fa-spin"></i> <?= __('webshop_modal_proc_step1') ?></div>
            <div id="procStep2" class="proc-step"><i class="fa-solid fa-circle"></i> <?= __('webshop_modal_proc_step2') ?></div>
        </div>
    </div>
</div>

<div id="successModal" class="modal-overlay" style="z-index: 10001;">
    <div class="modal-box" style="max-width: 450px; text-align: center;">
        <i class="fa-solid fa-circle-check" style="font-size: 64px; color: #4ade80; margin-bottom: 20px;"></i>
        <h2 style="margin: 0 0 10px 0; font-weight: 900; color: #fff;"><?= __('webshop_modal_succ_title') ?></h2>
        <p style="color: #aaa; margin-bottom: 25px; line-height: 1.6;" id="successText"></p>
        <button class="btn-action-shop" style="background: rgba(74, 222, 128, 0.1); color: #4ade80; border: 1px solid rgba(74, 222, 128, 0.3);" onclick="location.reload()"><i class="fa-solid fa-check"></i> <?= __('webshop_modal_btn_continue') ?></button>
    </div>
</div>

<script>
const langObj = {
    errorPrefix: "<?= __('admin_js_error_prefix') ?>",
    confirmCancel: "<?= __('webshop_js_confirm_cancel') ?>",
    successCancel: "<?= __('webshop_js_success_cancel') ?>",
    errInvalidAmount: "<?= __('webshop_js_err_invalid_amount') ?>",
    errNoBarterReq: "<?= __('webshop_js_err_no_barter_req') ?>",
    successSell: "<?= __('webshop_js_success_sell') ?>",
    procWrite: "<?= __('webshop_js_proc_write') ?>",
    procWait: "<?= __('webshop_js_proc_wait') ?>",
    successBuy: "<?= __('webshop_js_success_buy') ?>",
    selectCat: "<?= __('admin_icon_select_cat') ?>",
    searchCat: "<?= __('admin_icon_search_cat') ?>",
    notFound: "<?= __('admin_js_not_found') ?>",
    itemName: "<?= __('admin_js_item_name') ?>",
    pieces: "<?= __('admin_js_pieces') ?>",
    noneSelected: "<?= __('admin_js_none_selected') ?>",
    errInvalidPrice: "<?= __('webshop_js_err_invalid_price') ?>",
    bundleName: "<?= __('webshop_js_bundle_name') ?>",
    bundleContent: "<?= __('webshop_js_bundle_content') ?>",
    from: "<?= __('webshop_js_from') ?>",
    pcs: "<?= __('webshop_js_pcs') ?>",
    reqRecorded: "<?= __('webshop_js_req_recorded') ?>",
    serverLabel: "<?= __('webshop_js_server_label') ?>",
    adminShop: "<?= __('webshop_admin_shop') ?>"
};

// --- AJAX MARKET LOGIC ---
let currentMarketPage = 1;
let marketAjaxController = null;
let filterTimeout;

function filterMarket() {
    clearTimeout(filterTimeout);
    filterTimeout = setTimeout(() => {
        loadMarketPage(1);
    }, 300); // 300ms debounce
}

function loadMarketPage(page) {
    currentMarketPage = page;
    const search = document.getElementById('marketSearch').value;
    const sellerFilter = document.getElementById('marketSellerFilter').value;
    const priceFilter = document.getElementById('marketPriceFilter').value;
    const showSold = document.getElementById('showSoldToggle').checked;

    const loader = document.getElementById('marketLoader');
    if(loader) loader.style.display = 'flex';

    if (marketAjaxController) marketAjaxController.abort();
    marketAjaxController = new AbortController();

    const params = new URLSearchParams({
        ajax_market: 1,
        page: page,
        search: search,
        seller: sellerFilter,
        price: priceFilter,
        showSold: showSold
    });

    fetch('webshop.php?' + params.toString(), { signal: marketAjaxController.signal })
        .then(res => res.json())
        .then(data => {
            if(loader) loader.style.display = 'none';
            document.getElementById('marketGrid').innerHTML = data.html;
            document.getElementById('marketPagination').innerHTML = data.pagination;
        })
        .catch(err => {
            if (err.name !== 'AbortError') {
                console.error('Market AJAX Error:', err);
                if(loader) loader.style.display = 'none';
            }
        });
}

document.addEventListener("DOMContentLoaded", () => {
    loadMarketPage(1);
});
// ------------------------

function openGuestModal() {
    document.getElementById('guestModal').style.display = 'flex';
}

function switchTab(viewId, element) {
    ['market', 'vault', 'listings', 'history'].forEach(v => {
        let el = document.getElementById('view_' + v);
        if (el) el.style.display = 'none';
    });
    let targetEl = document.getElementById('view_' + viewId);
    if (targetEl) targetEl.style.display = 'block';
    document.querySelectorAll('.shop-tab').forEach(tab => tab.classList.remove('active'));
    element.classList.add('active');
}

function cancelListing(listingId) {
    if (!confirm(langObj.confirmCancel)) return;
    document.getElementById('processingModal').style.display = 'flex';
    const form = new FormData(); form.append('action', 'cancel'); form.append('listing_id', listingId);

    fetch('ajax_webshop.php', { method: 'POST', body: form }).then(res => res.json()).then(data => {
        document.getElementById('processingModal').style.display = 'none';
        if (data.success) { document.getElementById('successText').innerText = langObj.successCancel; document.getElementById('successModal').style.display = 'flex'; }
        else { alert(langObj.errorPrefix + data.error); }
    });
}

let selectedBundleItems = [];
function updateBundleSelection(checkbox, vaultId, name, icon, maxStock) {
    const card = checkbox.closest('.item-card');
    const imgTag = card ? card.querySelector('.item-visual img') : null;
    const imgSrc = imgTag ? imgTag.src : 'assets/icons/stone.png';

    if(checkbox.checked) {
        selectedBundleItems.push({vaultId, name, icon, maxStock, imgSrc});
    } else {
        selectedBundleItems = selectedBundleItems.filter(item => item.vaultId !== vaultId);
    }

    const actionBar = document.getElementById('vaultActionBar');
    const countText = document.getElementById('bundleCountText');
    const previewContainer = document.getElementById('bundlePreviewIcons');

    if(selectedBundleItems.length > 1) {
        actionBar.classList.add('show');
        countText.innerText = selectedBundleItems.length;
        previewContainer.innerHTML = '';

        selectedBundleItems.slice(0, 4).forEach(item => {
            previewContainer.innerHTML += `<img src="${item.imgSrc}" style="width: 32px; height: 32px; image-rendering: pixelated; margin-left: -10px; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.8)); border-radius: 50%; object-fit: contain;" onerror="this.src='assets/icons/stone.png'">`;
        });

        if(selectedBundleItems.length > 4) {
            previewContainer.innerHTML += `<div style="width:32px; height:32px; background:rgba(0,0,0,0.8); color:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:11px; margin-left:-10px; z-index:10; border: 1px solid #333;">+${selectedBundleItems.length - 4}</div>`;
        }
    } else {
        actionBar.classList.remove('show');
    }
}

let currentSellType = 'money'; let maxVaultStock = 1;

function openSellModal(vaultId, itemName, iconId, maxStock = 1) {
    maxVaultStock = maxStock;
    document.getElementById('sellItemName').innerText = itemName;
    document.getElementById('sellItemType').value = 'standard';
    document.getElementById('sellVaultId').value = vaultId;
    document.getElementById('bundleWarning').style.display = 'none';
    document.getElementById('bundleConfigSection').style.display = 'none';
    document.getElementById('sellQuantitySection').style.display = 'block';
    document.getElementById('sellAmountPerPackage').value = 1;
    document.getElementById('sellStockPackages').value = 1;
    document.getElementById('sellMaxStockLabel').innerText = maxStock;
    updateSellTotalCalc();
    document.getElementById('sellModal').style.display = 'flex';
}

document.addEventListener('input', function (e) {
    if (e.target && (e.target.id === 'sellAmountPerPackage' || e.target.id === 'sellStockPackages')) updateSellTotalCalc();
});

function updateSellTotalCalc() {
    let perPkg = parseInt(document.getElementById('sellAmountPerPackage').value) || 1;
    let pkgs = parseInt(document.getElementById('sellStockPackages').value) || 1;
    let total = perPkg * pkgs;
    const totalElem = document.getElementById('sellTotalDeducted');
    totalElem.innerText = total;
    totalElem.style.color = (total > maxVaultStock) ? '#ff4444' : 'var(--primary-color)';
}

function openBundleSellModal() {
    document.getElementById('sellItemName').innerText = `${langObj.bundleName} (${selectedBundleItems.length})`;
    document.getElementById('sellItemType').value = 'bundle';
    document.getElementById('bundleWarning').style.display = 'block';
    document.getElementById('sellQuantitySection').style.display = 'none';

    let bundleHtml = `<label style="font-size: 13px; font-weight: bold; color: #888; text-transform: uppercase;">${langObj.bundleContent}</label><div style="display:flex; flex-direction:column; gap:10px; margin-top:10px;">`;

    selectedBundleItems.forEach(item => {
        bundleHtml += `<div style="display: flex; align-items: center; justify-content: space-between; background: rgba(0,0,0,0.4); padding: 10px 15px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);"><div style="display: flex; align-items: center; gap: 10px;"><img src="${item.imgSrc}" style="width:28px; height:28px; image-rendering:pixelated; object-fit:contain;" onerror="this.src='assets/icons/stone.png'"><span style="color:#fff; font-weight:bold; font-size:14px;">${item.name}</span></div><div style="display: flex; align-items: center; gap: 8px;"><span style="font-size:11px; color:#888;">${langObj.from} ${item.maxStock} ${langObj.pcs}</span><input type="number" class="bundle-amount-input" data-id="${item.vaultId}" min="1" max="${item.maxStock}" value="${item.maxStock}" style="width: 70px; background: rgba(0,0,0,0.6); border: 1px solid var(--border-color); color: #fff; padding: 6px 10px; border-radius: 8px; outline: none; font-weight: bold;"></div></div>`;
    });

    bundleHtml += '</div>';
    document.getElementById('bundleConfigSection').innerHTML = bundleHtml;
    document.getElementById('bundleConfigSection').style.display = 'block';
    document.getElementById('sellModal').style.display = 'flex';
}

function switchSellOption(opt) {
    currentSellType = opt;
    document.getElementById('btnOptMoney').classList.remove('active');
    document.getElementById('btnOptBarter').classList.remove('active');
    document.getElementById('moneyOptions').style.display = 'none';
    document.getElementById('barterOptions').style.display = 'none';
    if (opt === 'money') { document.getElementById('btnOptMoney').classList.add('active'); document.getElementById('moneyOptions').style.display = 'block'; }
    else { document.getElementById('btnOptBarter').classList.add('active'); document.getElementById('barterOptions').style.display = 'block'; }
}

function addBarterReqRow() {
    const list = document.getElementById('barterReqList');
    const newRow = document.createElement('div');
    const uid = 'barter_' + Math.random().toString(36).substr(2, 9);
    newRow.className = 'barter-input-row';
    newRow.style.cssText = 'display: flex; gap: 10px; align-items: center;';
    newRow.innerHTML = `<div style="width: 48px; height: 48px; background: rgba(0,0,0,0.5); border: 1px solid var(--border-color); border-radius: 12px; display: flex; align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0;" onclick="openIconModal('${uid}')" title="Vybrať ikonu"><div id="preview_${uid}" style="display:flex; align-items:center; justify-content:center; width:100%; height:100%;"><i class="fa-solid fa-image" style="color: #666; font-size: 20px;"></i></div><input type="hidden" class="barter-icon-input" id="input_${uid}" value=""></div><div id="name_${uid}" class="barter-name-div" style="flex: 1; background: rgba(0,0,0,0.5); border: 1px solid var(--border-color); padding: 14px 15px; border-radius: 12px; color: #888; font-size: 14px; display: flex; align-items: center; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${langObj.noneSelected}</div><input type="number" class="barter-amount-input" placeholder="${langObj.pieces}" style="width: 100px; box-sizing: border-box; background: rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 14px 15px; border-radius: 12px; outline: none; font-family: inherit;"><button type="button" onclick="this.parentElement.remove()" style="background: rgba(255,68,68,0.1); color: #ff4444; border: 1px solid rgba(255,68,68,0.2); border-radius: 12px; padding: 0 15px; height: 48px; cursor: pointer; transition: 0.2s;"><i class="fa-solid fa-trash"></i></button>`;
    list.appendChild(newRow);
}

function submitListing() {
    const type = document.getElementById('sellItemType').value;
    const form = new FormData(); form.append('action', 'sell'); form.append('listing_type', type); form.append('price_type', currentSellType);

    if (currentSellType === 'money') {
        const price = document.getElementById('sellPriceMoney').value;
        if (!price || price <= 0) {
            document.getElementById('failedErrorText').innerText = langObj.errorPrefix + langObj.errInvalidPrice;
            document.getElementById('failedModal').style.display = 'flex';
            return;
        }
        form.append('price_money', price);
    } else {
        const reqs = [];
        document.querySelectorAll('.barter-input-row').forEach(row => {
            const icon = row.querySelector('.barter-icon-input').value;
            const amount = row.querySelector('.barter-amount-input').value;
            const name = row.querySelector('div[id^="name_"]').innerText;
            if (icon && amount > 0) reqs.push({icon, amount, name});
        });
        if (reqs.length === 0) {
            document.getElementById('failedErrorText').innerText = langObj.errorPrefix + langObj.errNoBarterReq;
            document.getElementById('failedModal').style.display = 'flex';
            return;
        }
        form.append('price_barter', JSON.stringify(reqs));
    }

    if (type === 'standard') {
        const vaultId = document.getElementById('sellVaultId').value;
        const amountPerPkg = document.getElementById('sellAmountPerPackage').value;
        const stockPkgs = document.getElementById('sellStockPackages').value;
        if (!amountPerPkg || amountPerPkg <= 0 || !stockPkgs || stockPkgs <= 0) {
            document.getElementById('failedErrorText').innerText = langObj.errorPrefix + langObj.errInvalidAmount;
            document.getElementById('failedModal').style.display = 'flex';
            return;
        }
        form.append('vault_id', vaultId); form.append('amount_per_package', amountPerPkg); form.append('stock_packages', stockPkgs);
    } else {
        const bundleData = [];
        document.querySelectorAll('.bundle-amount-input').forEach(input => { bundleData.push({ id: input.dataset.id, amount: input.value }); });
        form.append('bundle_data', JSON.stringify(bundleData));
    }

    document.getElementById('sellModal').style.display = 'none';
    document.getElementById('processingModal').style.display = 'flex';

    fetch('ajax_webshop.php', { method: 'POST', body: form }).then(res => res.json()).then(data => {
        document.getElementById('processingModal').style.display = 'none';
        if (data.success) { document.getElementById('successText').innerText = langObj.successSell; document.getElementById('successModal').style.display = 'flex'; }
        else {
            document.getElementById('failedErrorText').innerText = langObj.errorPrefix + data.error;
            document.getElementById('failedModal').style.display = 'flex';
        }
    });
}

let currentBuyOpt = { id: 0, name: '', baseAmount: 1, priceData: null, stockAmount: 1, isBundle: false, type: 'money', currentMult: 1, maxMult: 1, sellerName: '', itemImgUrl: '' };
function openBuyModal(listingId, itemName, baseAmount, priceData, stock, isBundle, type, sellerName, serverName, iconName, element) {
    if (stock >= 999999) stock = 999999;

    // Zistime src obrazka z elementu co sme klikli
    const itemCard = element.closest('.item-card');
    const imgElement = itemCard ? itemCard.querySelector('.item-visual img') : null;
    const itemImgUrl = imgElement ? imgElement.src : 'assets/icons/stone.png';

    currentBuyOpt = { id: listingId, name: itemName, baseAmount: baseAmount, priceData: priceData, stockAmount: stock, isBundle: isBundle, type: type, currentMult: 1, maxMult: stock, sellerName: sellerName, itemImgUrl: itemImgUrl };
    if(currentBuyOpt.maxMult < 1) currentBuyOpt.maxMult = 1;

    document.getElementById('buyListingId').value = listingId;
    document.getElementById('buyModalItemName').innerText = itemName;
    document.getElementById('buyModalServer').innerText = langObj.serverLabel + ' ' + serverName;
    document.getElementById('buyModalSeller').innerHTML = (sellerName === langObj.adminShop || sellerName === 'Admin Shop') ? `<i class="fa-solid fa-server" style="color: #ffaa00;"></i> <span style="color: #ffaa00; text-transform: uppercase;">${langObj.adminShop}</span>` : `<img src="https://minotar.net/helm/${sellerName}/20.png" style="border-radius:4px; width:20px; height:20px;"> <span style="color:#fff;">${sellerName}</span>`;

    if (isBundle || iconName === 'bundle') {
        document.getElementById('buyModalIcon').innerHTML = '<i class="fa-solid fa-boxes-packing" style="color: #ffaa00;"></i>';
    } else {
        document.getElementById('buyModalIcon').innerHTML = `<img src="${itemImgUrl}" style="width: 36px; height: 36px; image-rendering: pixelated; object-fit: contain;" onerror="this.src='assets/icons/stone.png'">`;
    }
    document.getElementById('buyBundleText').style.display = isBundle ? 'inline' : 'none';

    if (isBundle || currentBuyOpt.maxMult <= 1) {
        document.getElementById('buyMultiplierSection').style.display = 'none';
    } else {
        document.getElementById('buyMultiplierSection').style.display = 'flex';
        document.getElementById('buyMaxStock').innerText = stock >= 999999 ? '∞' : currentBuyOpt.maxMult;
    }

    updateBuyModalPrice(); document.getElementById('buyModal').style.display = 'flex';
}

function changeBuyMultiplier(change) {
    let newMult = currentBuyOpt.currentMult + change;
    if (newMult >= 1 && newMult <= currentBuyOpt.maxMult) { currentBuyOpt.currentMult = newMult; updateBuyModalPrice(); }
}

function updateBuyModalPrice() {
    document.getElementById('buyMultiplier').value = currentBuyOpt.currentMult;
    document.getElementById('buyTotalItemsReceived').innerText = (currentBuyOpt.currentMult * currentBuyOpt.baseAmount) + "x";

    if(document.getElementById('btnMultMinus')) document.getElementById('btnMultMinus').disabled = (currentBuyOpt.currentMult <= 1);
    if(document.getElementById('btnMultPlus')) document.getElementById('btnMultPlus').disabled = (currentBuyOpt.currentMult >= currentBuyOpt.maxMult);

    if (currentBuyOpt.type === 'money') {
        document.getElementById('buyResultMoney').style.display = 'block'; document.getElementById('buyResultBarter').style.display = 'none';
        document.getElementById('buyModalTotalPrice').innerHTML = `<i class="fa-solid fa-coins"></i> ` + (currentBuyOpt.currentMult * currentBuyOpt.priceData).toLocaleString('en-US', {minimumFractionDigits: 2}).replace(/,/g, ' ') + ` $`;
    } else {
        document.getElementById('buyResultMoney').style.display = 'none'; document.getElementById('buyResultBarter').style.display = 'block';
        let html = '';
        currentBuyOpt.priceData.forEach(req => {
            const cleanIcon = req.icon.includes(':') ? req.icon.split(':')[1] : req.icon;
            html += `<div style="display: flex; align-items: center; gap: 10px; background: rgba(0,0,0,0.4); padding: 8px 15px; border-radius: 12px; border: 1px solid rgba(255,170,0,0.2);"><img src="assets/icons/${cleanIcon}.png" style="width: 28px; height: 28px; image-rendering: pixelated; object-fit: contain;" onerror="this.src='assets/icons/stone.png'"><span style="color: #fff; font-weight: 800; font-size: 14px;">${req.amount * currentBuyOpt.currentMult}x ${req.name}</span></div>`;
        });
        document.getElementById('buyModalTotalBarter').innerHTML = html;
    }
}

function confirmPurchase() {
    document.getElementById('buyModal').style.display = 'none';
    document.getElementById('processingModal').style.display = 'flex';
    document.getElementById('procStep1').className = 'proc-step active'; document.getElementById('procStep1').innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> ${langObj.procWrite}`;
    document.getElementById('procStep2').className = 'proc-step'; document.getElementById('procStep2').innerHTML = `<i class="fa-solid fa-circle"></i> ${langObj.procWait}`;

    const form = new FormData(); form.append('action', 'buy'); form.append('listing_id', currentBuyOpt.id); form.append('multiplier', currentBuyOpt.currentMult);

    fetch('ajax_webshop.php', { method: 'POST', body: form }).then(res => res.json()).then(data => {
        if (data.success) {
            document.getElementById('procStep1').className = 'proc-step done'; document.getElementById('procStep1').innerHTML = '<i class="fa-solid fa-check"></i> ' + langObj.reqRecorded;
            document.getElementById('procStep2').className = 'proc-step active'; document.getElementById('procStep2').innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> ${langObj.procWait}`;

            let attempts = 0;
            const interval = setInterval(() => {
                attempts++;
                if (attempts > 20) {
                    clearInterval(interval);
                    document.getElementById('processingModal').style.display = 'none';

                    document.getElementById('failedModalIcon').className = 'fa-solid fa-triangle-exclamation';
                    document.getElementById('failedModalIcon').style.color = '#ffaa00';
                    document.getElementById('failedModalTitle').innerText = "<?= __('webshop_modal_unconfirmed_title') ?>";
                    document.getElementById('failedErrorText').innerText = "<?= __('webshop_modal_timeout_desc') ?>";

                    const btn = document.getElementById('failedModalBtn');
                    btn.style.background = 'rgba(255, 170, 0, 0.1)';
                    btn.style.color = '#ffaa00';
                    btn.style.border = '1px solid rgba(255, 170, 0, 0.3)';

                    document.getElementById('failedModal').style.display = 'flex';
                    return;
                }

                fetch(`ajax_webshop.php?action=check_queue&queue_id=${data.queue_id}`).then(r => r.json()).then(qData => {
                    if (qData.status === 'success') {
                        clearInterval(interval);
                        document.getElementById('processingModal').style.display = 'none';
                        if (qData.new_balance !== undefined) {
                            document.getElementById('playerBalanceDisplay').innerHTML = `<i class="fa-solid fa-wallet"></i> ${parseFloat(qData.new_balance).toLocaleString('en-US', {minimumFractionDigits: 2}).replace(/,/g, ' ')} $`;
                        }

                        if (currentBuyOpt.sellerName === langObj.adminShop || currentBuyOpt.sellerName === 'Admin Shop') {
                            document.getElementById('successText').innerText = "<?= __('webshop_success_adminshop') ?>";
                        } else {
                            document.getElementById('successText').innerText = "<?= __('webshop_success_vault') ?>";
                        }

                        document.getElementById('successModal').style.display = 'flex';
                    } else if (qData.status === 'failed') {
                        clearInterval(interval);

                        document.getElementById('failedModalIcon').className = 'fa-solid fa-circle-xmark';
                        document.getElementById('failedModalIcon').style.color = '#ff4444';
                        document.getElementById('failedModalTitle').innerText = "<?= __('webshop_modal_fail_title') ?>";
                        document.getElementById('failedErrorText').innerText = "<?= __('webshop_modal_fail_desc') ?>";
                        const btn = document.getElementById('failedModalBtn');
                        btn.style.background = 'rgba(255, 68, 68, 0.1)';
                        btn.style.color = '#ff4444';
                        btn.style.border = '1px solid rgba(255, 68, 68, 0.3)';

                        document.getElementById('processingModal').style.display = 'none';
                        document.getElementById('failedModal').style.display = 'flex';
                    }
                });
            }, 1000);
        } else { document.getElementById('processingModal').style.display = 'none'; alert(langObj.errorPrefix + data.error); }
    });
}

const iconData = { "Vanilla": [{id: "minecraft:stone", display: "Stone", path: "assets/icons/stone.png", category: "Vanilla"}] };
let allIconsFlat = []; let choicesInstance = null;
window.onload = function() {
    const catSelect = document.getElementById('iconCategorySelect'); let choicesOptions = [];
    <?php echo "const loadedIconData = " . json_encode(getGroupedIcons($activeServerName) ?? []) . ";"; ?>
    const useData = loadedIconData || iconData;
    for (const category in useData) {
        choicesOptions.push({ value: category, label: category + " (" + useData[category].length + ")", selected: category === 'Vanilla' });
        useData[category].forEach(icon => { icon.category = category; allIconsFlat.push(icon); });
    }
    choicesInstance = new Choices(catSelect, { searchEnabled: true, itemSelectText: '', shouldSort: false, placeholderValue: langObj.selectCat, searchPlaceholderValue: langObj.searchCat });
    choicesInstance.setChoices(choicesOptions, 'value', 'label', true);
    catSelect.addEventListener('change', function(e) { renderIconCategory(e.detail.value, useData); });
    if(useData['Vanilla']) renderIconCategory('Vanilla', useData);
};

function renderIconCategory(category, srcData) {
    const grid = document.getElementById('iconGrid'); grid.innerHTML = ''; if(!srcData[category]) return;
    let html = '';
    srcData[category].slice(0, 500).forEach(icon => { html += `<div class="icon-item" onclick="selectIcon('${icon.path}', '${icon.id}', '${icon.display}')"><img src="${icon.path}" loading="lazy" alt="${icon.display}"><div class="mc-tooltip"><div class="mc-tooltip-name mc-color-white">${icon.display}</div></div></div>`; });
    grid.innerHTML = html;
}

function searchIcons(query) {
    query = query.toLowerCase(); const grid = document.getElementById('iconGrid');
    if (!query) { const catValue = document.getElementById('iconCategorySelect').value; const useData = window.loadedIconData || iconData; renderIconCategory(catValue, useData); return; }
    let html = '', matched = 0;
    allIconsFlat.forEach(icon => {
        if (icon.id.toLowerCase().includes(query) || icon.display.toLowerCase().includes(query)) {
            html += `<div class="icon-item" onclick="selectIcon('${icon.path}', '${icon.id}', '${icon.display}')"><img src="${icon.path}" loading="lazy" alt="${icon.display}"><div class="mc-tooltip"><div class="mc-tooltip-name mc-color-white">${icon.display}</div><div class="mc-tooltip-enchant" style="color:#ffaa00;">${icon.category}</div></div></div>`;
            matched++; if (matched >= 200) return;
        }
    });
    grid.innerHTML = matched === 0 ? `<div style="color:#aaa; text-align:center; padding: 40px; font-weight: 800;">${langObj.notFound}</div>` : html;
}

let currentIconTargetId = null;
function openIconModal(targetId) { currentIconTargetId = targetId; document.getElementById('iconModalOverlay').style.display = 'flex'; document.getElementById('iconSearchInput').focus(); }
function closeIconModal() { document.getElementById('iconModalOverlay').style.display = 'none'; document.getElementById('iconSearchInput').value = ''; const catValue = document.getElementById('iconCategorySelect').value; renderIconCategory(catValue, window.loadedIconData || iconData); currentIconTargetId = null; }
function selectIcon(path, id, displayName) {
    if (currentIconTargetId) {
        document.getElementById('input_' + currentIconTargetId).value = id;
        document.getElementById('preview_' + currentIconTargetId).innerHTML = `<img src="${path}" style="width: 28px; height: 28px; image-rendering: pixelated; object-fit: contain;">`;
        let nameElem = document.getElementById('name_' + currentIconTargetId);
        if (nameElem) { if(nameElem.tagName === 'INPUT') { nameElem.value = displayName; } else { nameElem.innerText = displayName; nameElem.style.color = '#fff'; } }
    }
    closeIconModal();
}

document.querySelectorAll('.modal-overlay').forEach(overlay => { overlay.addEventListener('click', function(e) { if (this.id === 'processingModal' || this.id === 'successModal' || this.id === 'failedModal') return; if (e.target === this) { this.style.display = 'none'; } }); });
document.querySelectorAll('input').forEach(input => { input.addEventListener('keypress', function(e) { if(e.key === 'Enter') e.preventDefault(); }); });
</script>

<?php require_once 'includes/footer.php'; ?>
