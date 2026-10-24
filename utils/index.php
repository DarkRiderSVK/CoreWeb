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

$availableLangs = ['sk', 'cz', 'en', 'pl', 'fr', 'it'];
$currentLang = $_GET['lang'] ?? $_SESSION['utils_lang'] ?? 'sk';
if (!in_array($currentLang, $availableLangs)) { $currentLang = 'sk'; }
$_SESSION['utils_lang'] = $currentLang;

$translations = [
    'sk' => [
        'title' => 'Administrátorské nástroje a utilitky',
        'badge' => 'Core Network Development',
        'back' => '← Späť na portál',
        'card1_title' => 'ItemNameParser & Správa Ikoniek',
        'card1_desc' => 'Nástroj pre hromadnú konverziu a správu modovaných ikon predmetov z IconExporter do štruktúry pre portál.',
        'card2_title' => 'Enchant Builder & Item Generator',
        'card2_desc' => 'Interaktívny nástroj na vyklikanie predmetov, enchantov a poškodenia pre jednoduché vkladanie do webshopu a odmien.',
        'btn_open' => 'Otvoriť utilitu ➔',
        'footer_text' => 'Multi-verzná Minecraft sieť so synchronizovaným webshopom a databázou.',
        'rights' => 'Všetky práva vyhradené.'
    ],
    'cz' => [
        'title' => 'Administrátorské nástroje a utilitky',
        'badge' => 'Core Network Development',
        'back' => '← Zpět na portál',
        'card1_title' => 'ItemNameParser & Správa Ikonek',
        'card1_desc' => 'Nástroj pro hromadnou konverzi a správu modovaných ikon předmětů z IconExporter do struktury pro portál.',
        'card2_title' => 'Enchant Builder & Item Generator',
        'card2_desc' => 'Interaktivní nástroj na vyklikání předmětů, enchantmentů a poškození pro snadné vkládání do webshopu a odměn.',
        'btn_open' => 'Otevřít utilitu ➔',
        'footer_text' => 'Multi-verzní Minecraft síť se synchronizovaným webshopem a databází.',
        'rights' => 'Všechna práva vyhrazena.'
    ],
    'en' => [
        'title' => 'Administrator Tools and Utilities',
        'badge' => 'Core Network Development',
        'back' => '← Back to Portal',
        'card1_title' => 'ItemNameParser & Icons Manager',
        'card1_desc' => 'Batch conversion and management tool for modded item icons from IconExporter into the portal structure.',
        'card2_title' => 'Enchant Builder & Item Generator',
        'card2_desc' => 'Interactive tool to build items, enchantments, and durability for easy use in webshop and rewards.',
        'btn_open' => 'Open Utility ➔',
        'footer_text' => 'Multi-version Minecraft network with a synced webshop and database.',
        'rights' => 'All rights reserved.'
    ],
    'pl' => [
        'title' => 'Narzędzia administratora i utylity',
        'badge' => 'Core Network Development',
        'back' => '← Powrót do portalu',
        'card1_title' => 'ItemNameParser i Zarządzanie Ikonami',
        'card1_desc' => 'Narzędzie do konwersji i zarządzania ikonami modów z IconExporter.',
        'card2_title' => 'Enchant Builder & Item Generator',
        'card2_desc' => 'Interaktywne narzędzie do tworzenia przedmiotów i enchantów do sklepu i nagród.',
        'btn_open' => 'Otwórz narzędzie ➔',
        'footer_text' => 'Wersjonowana sieć Minecraft ze zsynchronizowanym sklepem i bazą danych.',
        'rights' => 'Wszelkie prawa zastrzeżone.'
    ],
    'fr' => [
        'title' => 'Outils Administrateur et Utilitaires',
        'badge' => 'Core Network Development',
        'back' => '← Retour au portail',
        'card1_title' => 'ItemNameParser & Gestionnaire d\'Icônes',
        'card1_desc' => 'Outil de conversion et de gestion des icônes d\'objets moddés.',
        'card2_title' => 'Enchant Builder & Item Generator',
        'card2_desc' => 'Outil interactif pour créer des objets et enchantements pour la boutique et les récompenses.',
        'btn_open' => 'Ouvrir l\'utilitaire ➔',
        'footer_text' => 'Réseau Minecraft multi-version avec boutique et base de données synchronisées.',
        'rights' => 'Tous droits réservés.'
    ],
    'it' => [
        'title' => 'Strumenti Amministratore e Utilità',
        'badge' => 'Core Network Development',
        'back' => '← Torna al Portale',
        'card1_title' => 'ItemNameParser & Gestore Icone',
        'card1_desc' => 'Strumento di conversione e gestione per le icone di oggetti moddati.',
        'card2_title' => 'Enchant Builder & Item Generator',
        'card2_desc' => 'Strumento interattivo per creare oggetti e incantesimi per shop e ricompense.',
        'btn_open' => 'Apri Utilità ➔',
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
        .container { max-width: 1000px; margin: 0 auto; width: 100%; flex: 1; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 35px; }
        .badge { background: rgba(56, 189, 248, 0.1); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); padding: 6px 14px; border-radius: 8px; font-size: 12px; font-weight: 800; text-transform: uppercase; }
        .lang-switcher { display: flex; gap: 6px; }
        .lang-btn {
            background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #aaa;
            padding: 6px 10px; border-radius: 6px; font-size: 11px; font-weight: 800; text-decoration: none; text-transform: uppercase; transition: 0.2s;
        }
        .lang-btn.active, .lang-btn:hover { background: #38bdf8; color: #000; border-color: #38bdf8; }

        h1 { font-size: 32px; font-weight: 900; margin-bottom: 30px; text-transform: uppercase; letter-spacing: 0.5px; }

        .utils-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 25px;
        }

        .util-card {
            background: rgba(15, 15, 20, 0.85); backdrop-filter: blur(15px); border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px; padding: 30px; box-shadow: 0 15px 35px rgba(0,0,0,0.6); display: flex; flex-direction: column;
            text-decoration: none; color: #fff; transition: all 0.3s ease;
        }
        .util-card:hover {
            transform: translateY(-5px); border-color: rgba(56, 189, 248, 0.5); box-shadow: 0 20px 45px rgba(56, 189, 248, 0.2);
        }
        .util-card h3 { font-size: 20px; font-weight: 900; margin-bottom: 12px; color: #38bdf8; }
        .util-card p { font-size: 14px; color: #bbb; line-height: 1.6; margin-bottom: 25px; flex-grow: 1; }
        .util-card-footer { display: flex; align-items: center; justify-content: space-between; font-weight: 800; font-size: 13px; color: #38bdf8; text-transform: uppercase; }

        .back-link { display: inline-block; color: #888; text-decoration: none; font-weight: 700; font-size: 13px; margin-bottom: 15px; text-transform: uppercase; transition: 0.2s; }
        .back-link:hover { color: #fff; }

        .site-footer { width: 100%; background: transparent; color: #a1a1aa; padding: 25px 15px; font-size: 13px; border-top: 1px solid rgba(255, 255, 255, 0.08); margin-top: 50px; }
        .footer-container { max-width: 1000px; margin: 0 auto; display: flex; flex-direction: column; align-items: center; justify-content: space-between; gap: 10px; text-align: center; }
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
                <?php foreach ($availableLangs as $lang): ?>
                    <a href="?lang=<?= $lang ?>" class="lang-btn <?= ($currentLang === $lang) ? 'active' : '' ?>"><?= strtoupper($lang) ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <a href="../index.php" class="back-link"><?= $t['back'] ?></a>
        <h1><?= $t['title'] ?></h1>

        <div class="utils-grid">
            <a href="util_icon_parser.php?lang=<?= $currentLang ?>" class="util-card">
                <h3><i class="fa-solid fa-wand-magic-sparkles"></i> <?= $t['card1_title'] ?></h3>
                <p><?= $t['card1_desc'] ?></p>
                <div class="util-card-footer">
                    <span><?= $t['btn_open'] ?></span>
                    <i class="fa-solid fa-arrow-right"></i>
                </div>
            </a>

            <a href="util_enchant_builder.php?lang=<?= $currentLang ?>" class="util-card">
                <h3><i class="fa-solid fa-hammer"></i> <?= $t['card2_title'] ?></h3>
                <p><?= $t['card2_desc'] ?></p>
                <div class="util-card-footer">
                    <span><?= $t['btn_open'] ?></span>
                    <i class="fa-solid fa-arrow-right"></i>
                </div>
            </a>
        </div>
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
</body>
</html>
