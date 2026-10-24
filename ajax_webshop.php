<?php
// ajax_webshop.php - Spracovanie AJAX požiadaviek pre CoreWebshop
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

header('Content-Type: application/json; charset=utf-8');

require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/language.php';

$currentUser = currentUser();
if (!$currentUser) {
    echo json_encode(['success' => false, 'error' => __('webshop_ajax_err_not_logged')]);
    exit;
}

$uuid = $currentUser['uuid'] ?? '';
$activeServerName = $_SESSION['active_server'] ?? null;
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if($activeServerName == null) {
    echo json_encode(['success' => false, 'error' => 'NO_SERVER_SELECTED']);
    exit;
}

// --- 1. KONTROLA BEZPEČNOSTNÉHO ZÁMKU (Ochrana proti dupe) ---
$stmtLock = $pdo->prepare("SELECT is_open FROM corewebshop_sessions WHERE uuid = ? LIMIT 1");
$stmtLock->execute([$uuid]);
$rowLock = $stmtLock->fetch();
if ($rowLock && $rowLock['is_open'] == 1) {
    echo json_encode(['success' => false, 'error' => __('webshop_ajax_err_locked')]);
    exit;
}

// --- 2. VYSTAVENIE PONUKY (SELL) ---
if ($action === 'sell') {
    // Dynamické načítanie limitu MAX ponúk pre daný server zo stĺpca max_listings_per_player
    $maxOffers = 10;
    try {
        $stSet = $pdo->prepare("SELECT max_listings_per_player FROM corewebshop_settings WHERE server_name = ? LIMIT 1");
        $stSet->execute([$activeServerName]);
        $val = $stSet->fetchColumn();
        if ($val !== false && $val !== null) $maxOffers = (int)$val;
    } catch (Exception $e) {}

    // Overenie aktuálneho počtu ponúk hráča
    if ($maxOffers > 0) {
        $stCnt = $pdo->prepare("SELECT COUNT(*) FROM corewebshop_listings WHERE seller_uuid = ? AND status IN ('active', 'pending') AND server_name = ?");
        $stCnt->execute([$uuid, $activeServerName]);
        $currentOffersCount = (int)$stCnt->fetchColumn();

        if ($currentOffersCount >= $maxOffers) {
            echo json_encode(['success' => false, 'error' => __('webshop_ajax_err_max_offers', ['max' => $maxOffers])]);
            exit;
        }
    }

    $listingType = $_POST['listing_type'] ?? 'standard'; // standard alebo bundle
    $priceType = $_POST['price_type'] ?? 'money';       // money alebo barter

    if ($priceType === 'money') {
        $priceMoney = (float)($_POST['price_money'] ?? 0);
        if ($priceMoney <= 0) {
            echo json_encode(['success' => false, 'error' => __('webshop_ajax_err_invalid_price')]);
            exit;
        }
        $priceBarterJson = null;
    } else {
        $priceMoney = 0.00;
        $barterRaw = $_POST['price_barter'] ?? '[]';
        $priceBarterJson = $barterRaw;
    }

    try {
        $pdo->beginTransaction();

        if ($listingType === 'standard') {
            $vaultId = (int)($_POST['vault_id'] ?? 0);
            $amountPerPackage = (int)($_POST['amount_per_package'] ?? 1);
            $stockPackages = (int)($_POST['stock_packages'] ?? 1);
            $totalQuantityToSell = $amountPerPackage * $stockPackages;

            if ($amountPerPackage <= 0 || $stockPackages <= 0) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => __('webshop_ajax_err_invalid_qty')]);
                exit;
            }

            // 1. Zistíme, čo za predmet hráč vybral
            $stmtV = $pdo->prepare("SELECT * FROM corewebshop_vault WHERE id = ? AND uuid = ? AND server_name = ? LIMIT 1 FOR UPDATE");
            $stmtV->execute([$vaultId, $uuid, $activeServerName]);
            $targetItem = $stmtV->fetch(PDO::FETCH_ASSOC);

            if (!$targetItem) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => __('webshop_ajax_err_not_in_vault')]);
                exit;
            }

            // Ošetrenie MAX stacku
            $itemIdLower = strtolower($targetItem['item_id']);
            $maxAllowedStack = 64;

            if (str_contains($itemIdLower, 'sword') || str_contains($itemIdLower, 'pickaxe') ||
                str_contains($itemIdLower, 'axe') || str_contains($itemIdLower, 'shovel') ||
                str_contains($itemIdLower, 'hoe') || str_contains($itemIdLower, 'helmet') ||
                str_contains($itemIdLower, 'chestplate') || str_contains($itemIdLower, 'leggings') ||
                str_contains($itemIdLower, 'boots') || str_contains($itemIdLower, 'shield') ||
                str_contains($itemIdLower, 'elytra') || str_contains($itemIdLower, 'totem')) {
                $maxAllowedStack = 1;
            } elseif (str_contains($itemIdLower, 'pearl') || str_contains($itemIdLower, 'potion') ||
                      str_contains($itemIdLower, 'sign') || str_contains($itemIdLower, 'bucket') ||
                      str_contains($itemIdLower, 'snowball') || str_contains($itemIdLower, 'egg')) {
                $maxAllowedStack = 16;
            }

            if ($amountPerPackage > $maxAllowedStack) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => __('webshop_ajax_err_max_stack', ['max' => $maxAllowedStack])]);
                exit;
            }

            // 2. Spočítame CELKOVÉ množstvo tohto istého predmetu
            $stmtSum = $pdo->prepare("SELECT SUM(amount) FROM corewebshop_vault WHERE uuid = ? AND server_name = ? AND item_id = ? AND item_nbt <=> ? FOR UPDATE");
            $stmtSum->execute([$uuid, $activeServerName, $targetItem['item_id'], $targetItem['item_nbt']]);
            $totalAvailable = (int)$stmtSum->fetchColumn();

            if ($totalAvailable < $totalQuantityToSell) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => __('webshop_ajax_err_insufficient_vault', ['want' => $totalQuantityToSell, 'have' => $totalAvailable])]);
                exit;
            }

            // 3. Postupne odrátavame s prísnou kontrolou
            $remainingToDeduct = $totalQuantityToSell;

            $stmtStartSlot = $pdo->prepare("SELECT id, amount FROM corewebshop_vault WHERE id = ? FOR UPDATE");
            $stmtStartSlot->execute([$vaultId]);
            $startSlot = $stmtStartSlot->fetch(PDO::FETCH_ASSOC);

            if ($startSlot && $remainingToDeduct > 0) {
                $slotAmount = (int)$startSlot['amount'];
                if ($slotAmount <= $remainingToDeduct) {
                    $pdo->prepare("DELETE FROM corewebshop_vault WHERE id = ?")->execute([$vaultId]);
                    $remainingToDeduct -= $slotAmount;
                } else {
                    $pdo->prepare("UPDATE corewebshop_vault SET amount = amount - ? WHERE id = ?")->execute([$remainingToDeduct, $vaultId]);
                    $remainingToDeduct = 0;
                }
            }

            if ($remainingToDeduct > 0) {
                $stmtSlots = $pdo->prepare("SELECT id, amount FROM corewebshop_vault WHERE uuid = ? AND server_name = ? AND item_id = ? AND item_nbt <=> ? AND id != ? ORDER BY amount ASC FOR UPDATE");
                $stmtSlots->execute([$uuid, $activeServerName, $targetItem['item_id'], $targetItem['item_nbt'], $vaultId]);
                $slots = $stmtSlots->fetchAll(PDO::FETCH_ASSOC);

                foreach ($slots as $slot) {
                    if ($remainingToDeduct <= 0) break;
                    $slotId = (int)$slot['id'];
                    $slotAmount = (int)$slot['amount'];

                    if ($slotAmount <= $remainingToDeduct) {
                        $pdo->prepare("DELETE FROM corewebshop_vault WHERE id = ?")->execute([$slotId]);
                        $remainingToDeduct -= $slotAmount;
                    } else {
                        $pdo->prepare("UPDATE corewebshop_vault SET amount = amount - ? WHERE id = ?")->execute([$remainingToDeduct, $slotId]);
                        $remainingToDeduct = 0;
                    }
                }
            }

            if ($remainingToDeduct > 0) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Kritická chyba pri odrátavaní zo skladu! Skús to znova.']);
                exit;
            }

            // 4. Vytvoríme ponuku na trhu
            $stmtList = $pdo->prepare("INSERT INTO corewebshop_listings (seller_uuid, server_name, listing_type, icon_id, amount_per_package, stock_available, item_nbt, price_type, price_money, price_barter_json, status) VALUES (?, ?, 'standard', ?, ?, ?, ?, ?, ?, ?, 'active')");
            $stmtList->execute([
                $uuid, $activeServerName, $targetItem['item_id'], $amountPerPackage, $stockPackages, $targetItem['item_nbt'], $priceType, $priceMoney, $priceBarterJson
            ]);

        } else {
            // --- BUNDLE (Balíček) ---
            $bundleData = json_decode($_POST['bundle_data'] ?? '[]', true);
            if (empty($bundleData)) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => __('webshop_ajax_err_empty_bundle')]);
                exit;
            }

            $firstId = (int)$bundleData[0]['id'];
            $stmtFirst = $pdo->prepare("SELECT * FROM corewebshop_vault WHERE id = ? AND uuid = ? LIMIT 1 FOR UPDATE");
            $stmtFirst->execute([$firstId, $uuid]);
            $firstItem = $stmtFirst->fetch(PDO::FETCH_ASSOC);

            if (!$firstItem) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => __('webshop_ajax_err_bundle_read')]);
                exit;
            }

            $stmtList = $pdo->prepare("INSERT INTO corewebshop_listings (seller_uuid, server_name, listing_type, icon_id, amount_per_package, stock_available, item_nbt, price_type, price_money, price_barter_json, status) VALUES (?, ?, 'bundle', ?, 1, 1, ?, ?, ?, ?, 'active')");
            $stmtList->execute([
                $uuid, $activeServerName, $firstItem['item_id'], $firstItem['item_nbt'], $priceType, $priceMoney, $priceBarterJson
            ]);
            $listingId = $pdo->lastInsertId();

            foreach ($bundleData as $bEntry) {
                $vId = (int)$bEntry['id'];
                $qty = (int)$bEntry['amount'];

                $stmtItm = $pdo->prepare("SELECT * FROM corewebshop_vault WHERE id = ? AND uuid = ? LIMIT 1 FOR UPDATE");
                $stmtItm->execute([$vId, $uuid]);
                $vRow = $stmtItm->fetch(PDO::FETCH_ASSOC);

                if (!$vRow || $vRow['amount'] < $qty) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => __('webshop_ajax_err_bundle_insufficient')]);
                    exit;
                }

                $stmtBIns = $pdo->prepare("INSERT INTO corewebshop_bundle_items (listing_id, item_id, amount, item_nbt) VALUES (?, ?, ?, ?)");
                $stmtBIns->execute([$listingId, $vRow['item_id'], $qty, $vRow['item_nbt']]);

                if ($vRow['amount'] == $qty) {
                    $pdo->prepare("DELETE FROM corewebshop_vault WHERE id = ?")->execute([$vId]);
                } else {
                    $pdo->prepare("UPDATE corewebshop_vault SET amount = amount - ? WHERE id = ?")->execute([$qty, $vId]);
                }
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

// --- 3. ZRUŠENIE PONUKY (CANCEL) ---
if ($action === 'cancel') {
    $listingId = (int)($_POST['listing_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        $stmtL = $pdo->prepare("SELECT * FROM corewebshop_listings WHERE id = ? AND seller_uuid = ? AND status = 'active' LIMIT 1 FOR UPDATE");
        $stmtL->execute([$listingId, $uuid]);
        $listing = $stmtL->fetch(PDO::FETCH_ASSOC);

        if (!$listing) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => __('webshop_ajax_err_cancel_not_found')]);
            exit;
        }

        if ($listing['server_name'] !== 'global' && $listing['server_name'] !== $activeServerName) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => __('webshop_ajax_err_server_mismatch')]);
            exit;
        }

        if ($listing['listing_type'] === 'standard') {
            $totalReturnAmount = (int)$listing['amount_per_package'] * (int)$listing['stock_available'];

            $stmtRet = $pdo->prepare("INSERT INTO corewebshop_vault (uuid, server_name, item_id, amount, item_nbt) VALUES (?, ?, ?, ?, ?)");
            $stmtRet->execute([
                $uuid, $listing['server_name'], $listing['icon_id'], $totalReturnAmount, $listing['item_nbt']
            ]);
        } else {
            $stmtB = $pdo->prepare("SELECT * FROM corewebshop_bundle_items WHERE listing_id = ?");
            $stmtB->execute([$listingId]);
            foreach ($stmtB->fetchAll() as $bItm) {
                $pdo->prepare("INSERT INTO corewebshop_vault (uuid, server_name, item_id, amount, item_nbt) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$uuid, $listing['server_name'], $bItm['item_id'], $bItm['amount'], $bItm['item_nbt']]);
            }
        }

        $pdo->prepare("UPDATE corewebshop_listings SET status = 'cancelled' WHERE id = ?")->execute([$listingId]);

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'DB Error: ' . $e->getMessage()]);
    }
    exit;
}

// --- 4. VYVOLANIE NÁKUPU (BUY) ---
if ($action === 'buy') {
    $listingId = (int)($_POST['listing_id'] ?? 0);
    $multiplier = (int)($_POST['multiplier'] ?? 1);
    if ($multiplier < 1) $multiplier = 1;

    try {
        $stmtL = $pdo->prepare("SELECT * FROM corewebshop_listings WHERE id = ? AND status = 'active' LIMIT 1 FOR UPDATE");
        $stmtL->execute([$listingId]);
        $listing = $stmtL->fetch(PDO::FETCH_ASSOC);

        if (!$listing) {
            echo json_encode(['success' => false, 'error' => __('webshop_ajax_err_buy_not_found')]);
            exit;
        }

        if ($listing['seller_uuid'] === $uuid) {
            echo json_encode(['success' => false, 'error' => __('webshop_ajax_err_buy_own')]);
            exit;
        }

        $totalCost = 0.00;
        if ($listing['price_type'] === 'money') {
            $totalCost = (float)$listing['price_money'] * $multiplier;
        }

        $stmtQ = $pdo->prepare("INSERT INTO corewebshop_queue (listing_id, server_name, buyer_uuid, packages_bought, total_money_cost, status) VALUES (?, ?, ?, ?, ?, 'processing')");
        $stmtQ->execute([$listingId, $activeServerName, $uuid, $multiplier, $totalCost]);
        $queueId = $pdo->lastInsertId();

        $pdo->prepare("UPDATE corewebshop_listings SET status = 'pending' WHERE id = ?")->execute([$listingId]);

        echo json_encode(['success' => true, 'queue_id' => $queueId]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'DB Error: ' . $e->getMessage()]);
    }
    exit;
}

// --- 5. KONTROLA STAVU FRONTY (POLLING PRE JS) ---
if ($action === 'check_queue') {
    $queueId = (int)($_GET['queue_id'] ?? 0);
    $stmtQ = $pdo->prepare("SELECT status FROM corewebshop_queue WHERE id = ? LIMIT 1");
    $stmtQ->execute([$queueId]);
    $status = $stmtQ->fetchColumn();

    echo json_encode(['status' => $status ? $status : 'processing']);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Neplatná akcia!']);
