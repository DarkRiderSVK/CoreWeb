<?php
// admin/ajax_admin_webshop.php - Backend pre administráciu webshopu (ZABEZPEČENÝ S OPRÁVNENIAMI)
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

require_once '../includes/db.php';
require_once '../includes/auth.php';

$currentUserData = currentUser();
if (!$currentUserData) {
    echo json_encode(['success' => false, 'error' => 'Nie si prihlásený!']);
    exit;
}

$currentUsername = is_array($currentUserData) ? ($currentUserData['username'] ?? '') : $currentUserData;

// Načítanie oprávnení administrátora
$myPermissions = [];
if (isset($pdo) && !empty($currentUsername)) {
    try {
        $stMyPerms = $pdo->prepare("SELECT permissions FROM coresync_admins WHERE LOWER(username) = LOWER(?) LIMIT 1");
        $stMyPerms->execute([$currentUsername]);
        $rowPerms = $stMyPerms->fetch();
        if ($rowPerms && !empty($rowPerms['permissions'])) {
            $decoded = json_decode($rowPerms['permissions'], true);
            if (is_array($decoded)) {
                $myPermissions = $decoded;
            }
        }
    } catch (Exception $e) {}
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// --- 1. VYTVORENIE ADMIN SHOP PONUKY ---
if ($action === 'create_admin_listing') {
    if (!in_array('addAdminShop', $myPermissions)) {
        echo json_encode(['success' => false, 'error' => 'Nemáš právo pridávať ponuky do Admin Shopu (addAdminShop)!']);
        exit;
    }

    $itemType = $_POST['item_type'] ?? 'standard'; // standard, bundle, special
    $serverName = $_POST['server_name'] ?? 'global';
    $displayName = trim($_POST['display_name'] ?? '');
    $iconId = trim($_POST['icon_id'] ?? 'minecraft:stone');
    $amountPerPackage = (int)($_POST['amount_per_package'] ?? 1);
    $itemNbt = trim($_POST['item_nbt'] ?? '');
    $hasGlow = isset($_POST['has_glow']) ? 1 : 0;
    $adminCommands = trim($_POST['admin_commands'] ?? '');
    $priceType = $_POST['price_type'] ?? 'money'; // money, barter
    $isLimited = isset($_POST['is_limited']) ? (int)$_POST['is_limited'] : 0; // 0 = neobmedzené, 1 = jednorazové (súťaž)
    $stockAvailable = $isLimited ? 1 : 999999;

    if ($priceType === 'money') {
        $priceMoney = (float)($_POST['price_money'] ?? 0);
        $priceBarterJson = null;
    } else {
        $priceMoney = 0.00;
        $priceBarterJson = $_POST['price_barter'] ?? '[]';
    }

    if (empty($adminCommands)) {
        echo json_encode(['success' => false, 'error' => 'Musíš zadať aspoň jeden príkaz pre odovzdanie v Admin Shope!']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO corewebshop_listings (seller_uuid, server_name, listing_type, display_name, icon_id, amount_per_package, stock_available, item_nbt, has_glow, admin_commands, price_type, price_money, price_barter_json, is_limited, status) VALUES ('ADMIN', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
        $stmt->execute([
            $serverName, $itemType, $displayName, $iconId, $amountPerPackage, $stockAvailable,
            $itemNbt, $hasGlow, $adminCommands, $priceType, $priceMoney, $priceBarterJson, $isLimited
        ]);
        $listingId = $pdo->lastInsertId();

        if ($itemType === 'bundle') {
            $bundleEntries = json_decode($_POST['bundle_data'] ?? '[]', true);
            foreach ($bundleEntries as $bEntry) {
                $bIcon = $bEntry['icon'] ?? 'minecraft:stone';
                $bName = $bEntry['name'] ?? '';
                $bAmt = (int)($bEntry['amount'] ?? 1);
                $bNbt = $bEntry['nbt'] ?? '';

                $stmtB = $pdo->prepare("INSERT INTO corewebshop_bundle_items (listing_id, item_id, display_name, amount, item_nbt) VALUES (?, ?, ?, ?, ?)");
                $stmtB->execute([$listingId, $bIcon, $bName, $bAmt, $bNbt]);
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'DB Error: ' . $e->getMessage()]);
    }
    exit;
}

// --- 2. ZMAZANIE / ODSTRÁNENIE PONUKY ---
if ($action === 'delete_listing') {
    $listingId = (int)($_POST['listing_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM corewebshop_listings WHERE id = ? LIMIT 1");
        $stmt->execute([$listingId]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$listing) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Ponuka nebola nájdená!']);
            exit;
        }

        // Dynamická kontrola oprávnení na základe toho, či mažeme Admin Shop alebo moderujeme Hráčsky trh
        if ($listing['seller_uuid'] === 'ADMIN') {
            if (!in_array('deleteAdminShop', $myPermissions)) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Nemáš právo mazať ponuky z Admin Shopu (deleteAdminShop)!']);
                exit;
            }
            $pdo->prepare("DELETE FROM corewebshop_bundle_items WHERE listing_id = ?")->execute([$listingId]);
            $pdo->prepare("DELETE FROM corewebshop_listings WHERE id = ?")->execute([$listingId]);
        } else {
            if (!in_array('moderatePlayerMarket', $myPermissions)) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Nemáš právo moderovať hráčsky trh (moderatePlayerMarket)!']);
                exit;
            }

            // Vrátenie predmetov do skladu hráča
            if ($listing['listing_type'] === 'standard') {
                $totalReturnAmount = (int)$listing['amount_per_package'] * (int)$listing['stock_available'];
                $pdo->prepare("INSERT INTO corewebshop_vault (uuid, server_name, item_id, amount, item_nbt) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$listing['seller_uuid'], $listing['server_name'], $listing['icon_id'], $totalReturnAmount, $listing['item_nbt']]);
            } else {
                $stmtB = $pdo->prepare("SELECT * FROM corewebshop_bundle_items WHERE listing_id = ?");
                $stmtB->execute([$listingId]);
                foreach ($stmtB->fetchAll() as $bItm) {
                    $pdo->prepare("INSERT INTO corewebshop_vault (uuid, server_name, item_id, amount, item_nbt) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$listing['seller_uuid'], $listing['server_name'], $bItm['item_id'], $bItm['amount'], $bItm['item_nbt']]);
                }
            }
            $pdo->prepare("UPDATE corewebshop_listings SET status = 'cancelled' WHERE id = ?")->execute([$listingId]);
        }

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'DB Error: ' . $e->getMessage()]);
    }
    exit;
}

// --- 3. ULOŽENIE NASTAVENÍ SERVEROV ---
if ($action === 'save_settings') {
    if (!in_array('manageWebshopSettings', $myPermissions)) {
        echo json_encode(['success' => false, 'error' => 'Nemáš právo meniť nastavenia webshopu (manageWebshopSettings)!']);
        exit;
    }

    $settingsData = $_POST['settings'] ?? [];

    try {
        $pdo->beginTransaction();

        $stmtUpsert = $pdo->prepare("
            INSERT INTO corewebshop_settings (server_name, is_enabled, tax_percent_money, tax_barter_enabled, tax_barter_fee, max_listings_per_player, expire_days)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                is_enabled = VALUES(is_enabled),
                tax_percent_money = VALUES(tax_percent_money),
                tax_barter_enabled = VALUES(tax_barter_enabled),
                tax_barter_fee = VALUES(tax_barter_fee),
                max_listings_per_player = VALUES(max_listings_per_player),
                expire_days = VALUES(expire_days)
        ");

        foreach ($settingsData as $srvName => $fields) {
            $isEnabled = isset($fields['is_enabled']) ? 1 : 0;
            $taxPercentMoney = (float)($fields['tax_percent_money'] ?? 0.00);
            $taxBarterEnabled = isset($fields['tax_barter_enabled']) ? 1 : 0;
            $taxBarterFee = (float)($fields['tax_barter_fee'] ?? 0.00);
            $maxListings = (int)($fields['max_listings_per_player'] ?? 10);
            $expireDays = (int)($fields['expire_days'] ?? 14);

            $stmtUpsert->execute([$srvName, $isEnabled, $taxPercentMoney, $taxBarterEnabled, $taxBarterFee, $maxListings, $expireDays]);
        }

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'DB Error: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Neplatná akcia!']);
