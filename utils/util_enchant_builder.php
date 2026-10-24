<?php
// utils/index.php - Rozcestník pre vývojárske a administrátorské nástroje Core Network
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// BEZPEČNOSTNÁ KONTROLA: Overenie či je používateľ admin
$currentUserData = currentUser();
$currentUsername = is_array($currentUserData) ? ($currentUserData['username'] ?? '') : (is_string($currentUserData) ? $currentUserData : '');
$isUserAdmin = false;

if (!empty($currentUsername) && isset($pdo)) {
    try {
        $stAdminCheck = $pdo->prepare("SELECT permissions FROM coresync_admins WHERE LOWER(username) = LOWER(?) LIMIT 1");
        $stAdminCheck->execute([$currentUsername]);
        $rowAdmin = $stAdminCheck->fetch();
        if ($rowAdmin && !empty($rowAdmin['permissions'])) {
            $decodedPerms = json_decode($rowAdmin['permissions'], true);
            if (is_array($decodedPerms) && !empty($decodedPerms)) {
                $isUserAdmin = true;
            }
        }
    } catch (Exception $e) {}
}

// Ak používateľ nie je prihlásený alebo nie je admin, preč s ním na hlavnú stránku!
if (!$isUserAdmin) {
    header("Location: ../index.php");
    exit;
}


$availableLangs = ['sk', 'cz', 'en', 'pl', 'fr', 'it'];$currentLang = $_GET['lang'] ?? $_SESSION['builder_lang'] ?? 'sk';
if (!in_array($currentLang, $availableLangs)) {$currentLang = 'sk'; }
$_SESSION['builder_lang'] =$currentLang;

$translations = [
    'sk' => [
        'title' => 'Enchant Builder & Item Generator',
        'badge' => 'Administrátorský nástroj',
        'back' => '← Späť na prehľad utilít',
        'item_settings' => 'Nastavenia predmetu',
        'item_material' => 'Materiál predmetu (ID)',
        'item_count' => 'Počet (Count)',
        'item_damage' => 'Poškodenie / Durability (Damage)',
        'enchants_title' => 'Všetky Minecraft enchanty (s limitmi)',
        'add_enchant' => 'Pridať enchant',
        'select_enchant' => 'Vyber enchant...',
        'output_nbt_title' => 'ADMIN SHOP ITEM NBT',
        'output_cmd_title' => 'GIVE PLAYER COMMAND',
        'copy_btn' => 'Skopírovať do schránky',
        'copied' => 'Skopírované!',
        'footer_text' => 'Multi-verzná Minecraft sieť so synchronizovaným webshopom a databázou.',
        'rights' => 'Všetky práva vyhradené.'
    ],
    'cz' => [
        'title' => 'Enchant Builder & Item Generator',
        'badge' => 'Administrátorský nástroj',
        'back' => '← Zpět na přehled utilit',
        'item_settings' => 'Nastavení předmětu',
        'item_material' => 'Materiál předmětu (ID)',
        'item_count' => 'Počet (Count)',
        'item_damage' => 'Poškození / Durability (Damage)',
        'enchants_title' => 'Všechny Minecraft enchanty (s limity)',
        'add_enchant' => 'Přidat enchant',
        'select_enchant' => 'Vyber enchant...',
        'output_nbt_title' => 'ADMIN SHOP ITEM NBT',
        'output_cmd_title' => 'GIVE PLAYER COMMAND',
        'copy_btn' => 'Zkopírovat do schránky',
        'copied' => 'Zkopírováno!',
        'footer_text' => 'Multi-verzní Minecraft síť se synchronizovaným webshopem a databází.',
        'rights' => 'Všechna práva vyhrazena.'
    ],
    'en' => [
        'title' => 'Enchant Builder & Item Generator',
        'badge' => 'Administrator Tool',
        'back' => '← Back to Utilities',
        'item_settings' => 'Item Settings',
        'item_material' => 'Item Material (ID)',
        'item_count' => 'Count',
        'item_damage' => 'Damage / Durability',
        'enchants_title' => 'All Minecraft Enchantments (with limits)',
        'add_enchant' => 'Add Enchantment',
        'select_enchant' => 'Select enchantment...',
        'output_nbt_title' => 'ADMIN SHOP ITEM NBT',
        'output_cmd_title' => 'GIVE PLAYER COMMAND',
        'copy_btn' => 'Copy to Clipboard',
        'copied' => 'Copied!',
        'footer_text' => 'Multi-version Minecraft network with a synced webshop and database.',
        'rights' => 'All rights reserved.'
    ],
    'pl' => [
        'title' => 'Enchant Builder & Item Generator',
        'badge' => 'Narzędzie administratora',
        'back' => '← Powrót do narzędzi',
        'item_settings' => 'Ustawienia przedmiotu',
        'item_material' => 'Materiał (ID)',
        'item_count' => 'Ilość',
        'item_damage' => 'Uszkodzenie (Damage)',
        'enchants_title' => 'Wszystkie zaklęcia Minecraft (z limitami)',
        'add_enchant' => 'Dodaj zaklęcie',
        'select_enchant' => 'Wybierz zaklęcie...',
        'output_nbt_title' => 'ADMIN SHOP ITEM NBT',
        'output_cmd_title' => 'GIVE PLAYER COMMAND',
        'copy_btn' => 'Skopiuj do schowka',
        'copied' => 'Skopiowano!',
        'footer_text' => 'Wersjonowana sieć Minecraft ze zsynchronizowanym sklepem i bazą danych.',
        'rights' => 'Wszelkie prawa zastrzeżone.'
    ],
    'fr' => [
        'title' => 'Enchant Builder & Item Generator',
        'badge' => 'Outil Administrateur',
        'back' => '← Retour aux utilitaires',
        'item_settings' => 'Paramètres de l\'objet',
        'item_material' => 'Matériau (ID)',
        'item_count' => 'Quantité',
        'item_damage' => 'Dégâts (Damage)',
        'enchants_title' => 'Tous les enchantements (avec limites)',
        'add_enchant' => 'Ajouter un enchantement',
        'select_enchant' => 'Sélectionner un enchantement...',
        'output_nbt_title' => 'ADMIN SHOP ITEM NBT',
        'output_cmd_title' => 'GIVE PLAYER COMMAND',
        'copy_btn' => 'Copier',
        'copied' => 'Copié !',
        'footer_text' => 'Réseau Minecraft multi-version avec boutique et base de données synchronisées.',
        'rights' => 'Tous droits réservés.'
    ],
    'it' => [
        'title' => 'Enchant Builder & Item Generator',
        'badge' => 'Strumento Amministratore',
        'back' => '← Torna alle utilità',
        'item_settings' => 'Impostazioni Oggetto',
        'item_material' => 'Materiale (ID)',
        'item_count' => 'Quantità',
        'item_damage' => 'Danno (Damage)',
        'enchants_title' => 'Tutti gli incantesimi (con limiti)',
        'add_enchant' => 'Aggiungi incantesimo',
        'select_enchant' => 'Seleziona incantesimo...',
        'output_nbt_title' => 'ADMIN SHOP ITEM NBT',
        'output_cmd_title' => 'GIVE PLAYER COMMAND',
        'copy_btn' => 'Copia negli appunti',
        'copied' => 'Copiato!',
        'footer_text' => 'Rete Minecraft multi-versione con shop e database sincronizzati.',
        'rights' => 'Tutti i diritti riservati.'
    ]
];

$t = $translations[$currentLang];
?>
<!DOCTYPE html>
<html lang="<?= $currentLang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $t['title'] ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap');
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body {
            background-color: #050505; color: #fff; display: flex; flex-direction: column; min-height: 100vh; padding: 40px 20px 0 20px;
        }
        .container {
            max-width: 900px; margin: 0 auto; width: 100%; background: rgba(15, 15, 20, 0.85);
            backdrop-filter: blur(15px); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 20px;
            padding: 40px; box-shadow: 0 20px 50px rgba(0,0,0,0.8); flex: 1;
        }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 20px; }
        .badge { background: rgba(56, 189, 248, 0.1); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); padding: 6px 14px; border-radius: 8px; font-size: 12px; font-weight: 800; text-transform: uppercase; }
        .lang-switcher { display: flex; gap: 6px; }
        .lang-btn {
            background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #aaa;
            padding: 6px 10px; border-radius: 6px; font-size: 11px; font-weight: 800; text-decoration: none; text-transform: uppercase; transition: 0.2s;
        }
        .lang-btn.active, .lang-btn:hover { background: #38bdf8; color: #000; border-color: #38bdf8; }

        .back-link { display: inline-block; color: #888; text-decoration: none; font-weight: 700; font-size: 13px; margin-bottom: 15px; text-transform: uppercase; transition: 0.2s; }
        .back-link:hover { color: #fff; }

        h1 { font-size: 26px; font-weight: 900; margin-bottom: 20px; text-transform: uppercase; letter-spacing: 0.5px; }
        h3 { font-size: 15px; font-weight: 800; margin: 25px 0 10px 0; color: #38bdf8; text-transform: uppercase; }

        .form-grid { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 15px; margin-bottom: 15px; }
        @media(max-width: 768px) { .form-grid { grid-template-columns: 1fr; } }

        .form-group { display: flex; flex-direction: column; gap: 6px; }
        label { font-size: 12px; font-weight: 700; color: #aaa; text-transform: uppercase; }
        input, select {
            background: rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.1); border-radius: 10px;
            padding: 12px; color: #fff; font-size: 14px; outline: none; transition: 0.2s;
        }
        input:focus, select:focus { border-color: #38bdf8; box-shadow: 0 0 10px rgba(56,189,248,0.2); }

        .enchant-row { display: flex; gap: 10px; margin-bottom: 10px; align-items: center; }
        .enchant-row select { flex: 2; }
        .enchant-row input { flex: 1; }

        .btn {
            background: linear-gradient(135deg, #38bdf8, #0284c7); color: #000; font-weight: 900; padding: 10px 20px;
            border: none; border-radius: 10px; cursor: pointer; text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; transition: 0.2s;
        }
        .btn:hover { background: #fff; }
        .btn-danger { background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.4); }
        .btn-danger:hover { background: #ef4444; color: #fff; }

        .output-box {
            background: rgba(0,0,0,0.6); border: 1px solid rgba(56, 189, 248, 0.3); border-radius: 12px;
            padding: 18px; font-family: monospace; font-size: 13px; color: #f59e0b; word-break: break-all; margin-top: 6px;
            position: relative;
        }

        .site-footer { width: 100%; background: transparent; color: #a1a1aa; padding: 25px 15px; font-size: 13px; border-top: 1px solid rgba(255, 255, 255, 0.08); margin-top: 50px; }
        .footer-container { max-width: 900px; margin: 0 auto; display: flex; flex-direction: column; align-items: center; justify-content: space-between; gap: 10px; text-align: center; }
        @media (min-width: 768px) { .footer-container { flex-direction: row; text-align: left; } }
        .footer-info p { margin: 0; }
        .footer-desc { font-size: 11px; color: #71717a; margin-top: 3px !important; }
        .footer-credit a { color: #38bdf8; text-decoration: none; font-weight: 600; transition: color 0.2s ease; }
        .footer-credit a:hover { color: #7dd3fc; text-decoration: underline; }
    </style>
</head>
<body>

    <div class="container">
        <div class="top-bar">
            <span class="badge"><?= $t['badge'] ?></span>
            <div class="lang-switcher">
                <?php foreach ($availableLangs as$lang): ?>
                    <a href="?lang=<?= $lang ?>" class="lang-btn <?= ($currentLang === $lang) ? 'active' : '' ?>"><?= strtoupper($lang) ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <a href="index.php?lang=<?= $currentLang ?>" class="back-link"><?= $t['back'] ?></a>
        <h1><?= $t['title'] ?></h1>

        <h3><?= $t['item_settings'] ?></h3>
        <div class="form-grid">
            <div class="form-group">
                <label><?= $t['item_material'] ?></label>
                <input type="text" id="itemMaterial" value="netherite_chestplate" placeholder="napr. iron_sword">
            </div>
            <div class="form-group">
                <label><?= $t['item_count'] ?></label>
                <input type="number" id="itemCount" value="1" min="1" max="64">
            </div>
            <div class="form-group">
                <label><?= $t['item_damage'] ?></label>
                <input type="number" id="itemDamage" value="0" min="0">
            </div>
        </div>

        <h3><?= $t['enchants_title'] ?></h3>
        <div id="enchantsList"></div>
        <button type="button" class="btn" id="addEnchantBtn"><i class="fa-solid fa-plus"></i> <?= $t['add_enchant'] ?></button>

        <!-- 1. VÝSTUP: ADMIN SHOP ITEM NBT -->
        <h3><?= $t['output_nbt_title'] ?></h3>
        <div class="output-box" id="outputNbt">[enchantments={levels:{"minecraft:protection":4,"minecraft:thorns":3,"minecraft:unbreaking":3}}]</div>
        <button type="button" class="btn copyBtn" data-target="outputNbt" style="margin-top: 10px;"><i class="fa-solid fa-copy"></i> <?= $t['copy_btn'] ?></button>

        <!-- 2. VÝSTUP: GIVE PLAYER COMMAND -->
        <h3><?= $t['output_cmd_title'] ?></h3>
        <div class="output-box" id="outputCmd">give {player} netherite_chestplate[enchantments={levels:{"minecraft:protection":4,"minecraft:thorns":3,"minecraft:unbreaking":3}}] 1</div>
        <button type="button" class="btn copyBtn" data-target="outputCmd" style="margin-top: 10px;"><i class="fa-solid fa-copy"></i> <?= $t['copy_btn'] ?></button>
    </div>

    <footer class="site-footer">
        <div class="footer-container">
            <div class="footer-info">
                <p>&copy; <?= date('Y'); ?> <strong>Core Network</strong>. <?= $t['rights'] ?></p>
                <p class="footer-desc"><?= $t['footer_text'] ?></p>
            </div>
            <div class="footer-credit">
                <p>Crafted with ❤️ by <a href="https://urobmiweb.sk" target="_blank" rel="noopener">UrobMiWeb.sk</a></p>
            </div>
        </div>
    </footer>

    <script>
        const enchantsConfig = {
            'minecraft:sharpness': 5,
            'minecraft:smite': 5,
            'minecraft:bane_of_arthropods': 5,
            'minecraft:efficiency': 5,
            'minecraft:fortune': 3,
            'minecraft:looting': 3,
            'minecraft:unbreaking': 3,
            'minecraft:silk_touch': 1,
            'minecraft:knockback': 2,
            'minecraft:fire_aspect': 2,
            'minecraft:mending': 1,
            'minecraft:sweeping_edge': 3,
            'minecraft:luck_of_the_sea': 3,
            'minecraft:lure': 3,
            'minecraft:power': 5,
            'minecraft:punch': 2,
            'minecraft:flame': 1,
            'minecraft:infinity': 1,
            'minecraft:multishot': 1,
            'minecraft:piercing': 4,
            'minecraft:quick_charge': 3,
            'minecraft:protection': 4,
            'minecraft:fire_protection': 4,
            'minecraft:feather_falling': 4,
            'minecraft:blast_protection': 4,
            'minecraft:projectile_protection': 4,
            'minecraft:respiration': 3,
            'minecraft:aqua_affinity': 1,
            'minecraft:thorns': 3,
            'minecraft:depth_strider': 3,
            'minecraft:frost_walker': 2,
            'minecraft:soul_speed': 3,
            'minecraft:swift_sneaking': 3,
            'minecraft:loyalty': 3,
            'minecraft:impaling': 5,
            'minecraft:riptide': 3,
            'minecraft:channeling': 1,
            'minecraft:binding_curse': 1,
            'minecraft:vanishing_curse': 1
        };

        const materialInput = document.getElementById('itemMaterial');
        const countInput = document.getElementById('itemCount');
        const damageInput = document.getElementById('itemDamage');
        const enchantsList = document.getElementById('enchantsList');
        const addEnchantBtn = document.getElementById('addEnchantBtn');
        const outputNbt = document.getElementById('outputNbt');
        const outputCmd = document.getElementById('outputCmd');

        function updateOutput() {
            let material = materialInput.value.trim() || 'stone';
            material = material.replace('minecraft:', '');

            const count = parseInt(countInput.value) || 1;
            const damage = parseInt(damageInput.value) || 0;

            let enchantsArr = [];
            document.querySelectorAll('.enchant-row').forEach(row => {
                const select = row.querySelector('.enchantSelect');
                const levelInput = row.querySelector('.enchantLevel');
                if (select && levelInput) {
                    const enchName = select.value;
                    const maxLimit = enchantsConfig[enchName] || 5;

                    let level = parseInt(levelInput.value) || 1;
                    if (level > maxLimit) {
                        level = maxLimit;
                        levelInput.value = maxLimit;
                    }
                    if (level < 1) {
                        level = 1;
                        levelInput.value = 1;
                    }

                    if (enchName) {
                        enchantsArr.push(`"${enchName}":${level}`);
                    }
                }
            });

            let componentsArr = [];

            if (damage > 0) {
                componentsArr.push(`"minecraft:damage":${damage}`);
            }

            if (enchantsArr.length > 0) {
                componentsArr.push(`enchantments={levels:{${enchantsArr.join(',')}}}`);
            }

            let itemPart = material;
            if (componentsArr.length > 0) {
                itemPart += `[${componentsArr.join(',')}]`;
            }

            // 1. Výstup: NBT časť
            let nbtOnly = '';
            if (componentsArr.length > 0) {
                nbtOnly = `[${componentsArr.join(',')}]`;
            }
            outputNbt.textContent = nbtOnly;

            // 2. Výstup: GIVE PLAYER COMMAND
            let giveCmd = `give {player} ${itemPart}`;
            if (count > 1) {
                giveCmd += ` ${count}`;
            }
            outputCmd.textContent = giveCmd;
        }

        function addEnchantRow(selectedEnch = 'minecraft:protection', selectedLevel = 4) {
            const row = document.createElement('div');
            row.className = 'enchant-row';

            let selectHtml = `<select class="enchantSelect">`;
            for (let ench in enchantsConfig) {
                const selected = (ench === selectedEnch) ? 'selected' : '';
                selectHtml += `<option value="${ench}" ${selected}>${ench} (max: ${enchantsConfig[ench]})</option>`;
            }
            selectHtml += `</select>`;

            row.innerHTML = `
                ${selectHtml}
                <input type="number" class="enchantLevel" value="${selectedLevel}" min="1" max="${enchantsConfig[selectedEnch]}" placeholder="Level">
                <button type="button" class="btn btn-danger removeRowBtn"><i class="fa-solid fa-trash"></i></button>
            `;

            const selectEl = row.querySelector('.enchantSelect');
            const levelEl = row.querySelector('.enchantLevel');

            selectEl.addEventListener('change', () => {
                const maxLimit = enchantsConfig[selectEl.value] || 5;
                levelEl.max = maxLimit;
                if (parseInt(levelEl.value) > maxLimit) {
                    levelEl.value = maxLimit;
                }
                updateOutput();
            });

            levelEl.addEventListener('input', updateOutput);

            row.querySelector('.removeRowBtn').addEventListener('click', () => {
                row.remove();
                updateOutput();
            });

            enchantsList.appendChild(row);
            updateOutput();
        }

        addEnchantBtn.addEventListener('click', () => {
            addEnchantRow('minecraft:protection', 1);
        });

        materialInput.addEventListener('input', updateOutput);
        countInput.addEventListener('input', updateOutput);
        damageInput.addEventListener('input', updateOutput);

        document.querySelectorAll('.copyBtn').forEach(btn => {
            btn.addEventListener('click', () => {
                const targetId = btn.getAttribute('data-target');
                const textToCopy = document.getElementById(targetId).textContent;

                navigator.clipboard.writeText(textToCopy).then(() => {
                    const originalText = btn.innerHTML;
                    btn.innerHTML = `<i class="fa-solid fa-check"></i> <?= $t['copied'] ?>`;
                    setTimeout(() => {
                        btn.innerHTML = originalText;
                    }, 2000);
                });
            });
        });

        addEnchantRow('minecraft:protection', 4);
        addEnchantRow('minecraft:thorns', 3);
        addEnchantRow('minecraft:unbreaking', 3);
    </script>
</body>
</html>
