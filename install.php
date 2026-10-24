<?php
// install.php - Viacjazyčný inštalačný sprievodca pre CoreNetwork Portal
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Spracovanie výberu jazyka
if (isset($_GET['set_lang'])) {
    $lang = $_GET['set_lang'];
    if (in_array($lang, ['sk', 'cz', 'en', 'it', 'fr'])) {
        $_SESSION['install_lang'] = $lang;
    }
    header('Location: install.php?step=2');
    exit;
}

$installLang = $_SESSION['install_lang'] ?? 'sk';
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error = '';

// Slovník pre inštalátor (SK, CZ, EN, IT, FR)
$i18n = [
    'sk' => [
        'title' => 'Inštalácia | CoreNetwork Setup',
        'step_lang' => 'Jazyk', 'step_db' => 'DB', 'step_admin' => 'Admin', 'step_portal' => 'Portál', 'step_servers' => 'Servery',
        'lang_title' => 'Výber jazyka / Language Selection',
        'lang_desc' => 'Zvoľte si jazyk, v ktorom chcete prebehnúť inštaláciu portálu.',
        'db_desc' => 'Zadajte prihlasovacie údaje k vašej MySQL databáze (CoreSync).',
        'db_host' => 'Databázový Hostiteľ (Host)', 'db_name' => 'Názov databázy (DB Name)', 'db_user' => 'Používateľ (DB User)', 'db_pass' => 'Heslo (DB Password)', 'db_pass_ph' => 'Zadajte heslo...',
        'admin_desc' => 'Zadajte herný nick hráča, ktorému sa priradia plné administrátorské oprávnenia na webe.',
        'admin_nick' => 'Nick na serveri (Hlavný Admin)', 'admin_desc_sub' => 'Tento účet získa prístup k správe nastavení, článkov a komentárov.',
        'portal_desc' => 'Prispôsobte si texty, ktoré sa budú zobrazovať na úvodnej stránke portálu.',
        'portal_name' => 'Názov portálu / siete (V hlavičke)',
        'hero_small' => 'Malý horný nápis (Hero nadpis)', 'hero_main' => 'Hlavný veľký nápis (Hero hlavička)', 'hero_desc_label' => 'Popis pod hlavným nápisom',
        'footer_title' => 'Spodný nadpis (Footer banner)', 'footer_desc' => 'Spodný text pod nadpisom (Footer desc)',
        'servers_desc' => 'Nastavenie serverov a výberového režimu (detekovaných', 'servers_desc_end' => 'aktívnych serverov).',
        'selector_box' => 'Aktivovať Server Selector', 'selector_desc' => 'Hráčom sa na úvod zobrazí výberová stránka so všetkými servermi.',
        'default_server' => 'Hlavný predvolený server (Zobrazí sa priamo na webe bez výberu)',
        'server_config_title' => 'Detailná konfigurácia serverov',
        'hide_server' => 'Skryť server', 'display_name' => 'Zobrazovaný názov', 'server_color' => 'Farba servera (Téma)',
        'dynmap_on' => 'Zapnúť Dynmapu', 'dynmap_url' => 'Dynmapa URL (iframe)', 'chat_on' => 'Povoliť písanie do chatu cez web',
        'badge_text' => 'Štítok / Badge', 'server_desc' => 'Popis servera', 'target_url' => 'Cieľový odkaz (Tlačidlo Vstúpiť)',
        'btn_next' => 'Pokračovať', 'btn_back' => 'Späť', 'btn_finish' => 'Dokončiť inštaláciu',
        'success_title' => 'Inštalácia bola úspešne dokončená!',
        'success_desc' => 'Všetky globálne nastavenia, konfigurácie serverov, administrátorské oprávnenia a pripojenie k databáze boli uložené.',
        'security_warn' => 'Nezabudnite súbor install.php zo servera vymazať.', 'btn_enter' => 'Vstúpiť na portál',
        'err_conn' => 'Nepodarilo sa pripojiť k databáze! Skontrolujte údaje.', 'err_admin' => 'Zadajte nick administrátora!', 'err_save' => 'Chyba pri ukladaní nastavení: '
    ],
    'cz' => [
        'title' => 'Instalace | CoreNetwork Setup',
        'step_lang' => 'Jazyk', 'step_db' => 'DB', 'step_admin' => 'Admin', 'step_portal' => 'Portál', 'step_servers' => 'Servery',
        'lang_title' => 'Výběr jazyka / Language Selection',
        'lang_desc' => 'Zvolte si jazyk, ve kterém chcete provést instalaci portálu.',
        'db_desc' => 'Zadejte přihlašovací údaje k vaší MySQL databázi (CoreSync).',
        'db_host' => 'Databázový Hostitel (Host)', 'db_name' => 'Název databáze (DB Name)', 'db_user' => 'Uživatel (DB User)', 'db_pass' => 'Heslo (DB Password)', 'db_pass_ph' => 'Zadejte heslo...',
        'admin_desc' => 'Zadejte herní nick hráče, kterému se přiřadí plná administrátorská oprávnění na webu.',
        'admin_nick' => 'Nick na serveru (Hlavní Admin)', 'admin_desc_sub' => 'Tento účet získá přístup ke správě nastavení, článků a komentářů.',
        'portal_desc' => 'Přizpůsobte si texty, které se budou zobrazovat na úvodní stránce portálu.',
        'portal_name' => 'Název portálu / sítě (V hlavičce)',
        'hero_small' => 'Malý horní nápis (Hero nadpis)', 'hero_main' => 'Hlavní velký nápis (Hero hlavička)', 'hero_desc_label' => 'Popis pod hlavním nápisem',
        'footer_title' => 'Spodní nadpis (Footer banner)', 'footer_desc' => 'Spodní text pod nadpisem (Footer desc)',
        'servers_desc' => 'Nastavení serverů a výběrového režimu (detekovaných', 'servers_desc_end' => 'aktivních serverů).',
        'selector_box' => 'Aktivovat Server Selector', 'selector_desc' => 'Hráčům se na úvod zobrazí výběrová stránka se všemi servery.',
        'default_server' => 'Hlavní výchozí server (Zobrazí se přímo na webu bez výběru)',
        'server_config_title' => 'Detailní konfigurace serverů',
        'hide_server' => 'Skrýt server', 'display_name' => 'Zobrazovaný názv', 'server_color' => 'Barva serveru (Téma)',
        'dynmap_on' => 'Zapnout Dynmapu', 'dynmap_url' => 'Dynmapa URL (iframe)', 'chat_on' => 'Povolit psaní do chatu přes web',
        'badge_text' => 'Štítek / Badge', 'server_desc' => 'Popis serveru', 'target_url' => 'Cílový odkaz (Tlačítko Vstoupit)',
        'btn_next' => 'Pokračovat', 'btn_back' => 'Zpět', 'btn_finish' => 'Dokončit instalaci',
        'success_title' => 'Instalace byla úspěšně dokončena!',
        'success_desc' => 'Všechna globální nastavení, konfigurace serverů, administrátorská oprávnění a připojení k databázi byla uložena.',
        'security_warn' => 'Nezapomeňte soubor install.php ze serveru smazat.', 'btn_enter' => 'Vstoupit na portál',
        'err_conn' => 'Nepodařilo se připojit k databázi! Zkontrolujte údaje.', 'err_admin' => 'Zadejte nick administrátora!', 'err_save' => 'Chyba při ukládání nastavení: '
    ],
    'en' => [
        'title' => 'Installation | CoreNetwork Setup',
        'step_lang' => 'Lang', 'step_db' => 'DB', 'step_admin' => 'Admin', 'step_portal' => 'Portal', 'step_servers' => 'Servers',
        'lang_title' => 'Language Selection',
        'lang_desc' => 'Choose the language you want to use for the installation.',
        'db_desc' => 'Enter the login credentials for your MySQL database (CoreSync).',
        'db_host' => 'Database Host', 'db_name' => 'Database Name', 'db_user' => 'Database User', 'db_pass' => 'Database Password', 'db_pass_ph' => 'Enter password...',
        'admin_desc' => 'Enter the in-game username of the player who will get full admin permissions.',
        'admin_nick' => 'Server Nickname (Main Admin)', 'admin_desc_sub' => 'This account will gain access to settings, articles, and comment management.',
        'portal_desc' => 'Customize the texts displayed on the portal homepage.',
        'portal_name' => 'Portal / Network Name (Header)',
        'hero_small' => 'Small Top Title (Hero Subtitle)', 'hero_main' => 'Main Heading (Hero Title)', 'hero_desc_label' => 'Description below main heading',
        'footer_title' => 'Bottom Heading (Footer Banner)', 'footer_desc' => 'Bottom Description (Footer Desc)',
        'servers_desc' => 'Server settings and selector mode (detected', 'servers_desc_end' => 'active servers).',
        'selector_box' => 'Enable Server Selector', 'selector_desc' => 'Players will see a selector page with all servers on startup.',
        'default_server' => 'Main Default Server (Displayed directly without selector)',
        'server_config_title' => 'Detailed Server Configuration',
        'hide_server' => 'Hide Server', 'display_name' => 'Display Name', 'server_color' => 'Server Theme Color',
        'dynmap_on' => 'Enable Dynmap', 'dynmap_url' => 'Dynmap URL (iframe)', 'chat_on' => 'Allow Web Chat Typing',
        'badge_text' => 'Status Badge', 'server_desc' => 'Server Description', 'target_url' => 'Target URL (Enter Button)',
        'btn_next' => 'Continue', 'btn_back' => 'Back', 'btn_finish' => 'Finish Installation',
        'success_title' => 'Installation successfully completed!',
        'success_desc' => 'All global settings, server configurations, admin permissions, and database connection have been saved.',
        'security_warn' => 'Do not forget to delete the install.php file from your server.', 'btn_enter' => 'Enter Portal',
        'err_conn' => 'Failed to connect to the database! Check your credentials.', 'err_admin' => 'Enter administrator nickname!', 'err_save' => 'Error saving settings: '
    ],
    'it' => [
        'title' => 'Installazione | CoreNetwork Setup',
        'step_lang' => 'Lingua', 'step_db' => 'DB', 'step_admin' => 'Admin', 'step_portal' => 'Portale', 'step_servers' => 'Server',
        'lang_title' => 'Selezione della lingua',
        'lang_desc' => 'Scegli la lingua per l\'installazione del portale.',
        'db_desc' => 'Inserisci le credenziali per il tuo database MySQL (CoreSync).',
        'db_host' => 'Host Database', 'db_name' => 'Nome Database', 'db_user' => 'Utente Database', 'db_pass' => 'Password Database', 'db_pass_ph' => 'Inserisci password...',
        'admin_desc' => 'Inserisci il nickname di gioco del giocatore che riceverà i permessi completi.',
        'admin_nick' => 'Nickname Server (Admin Principale)', 'admin_desc_sub' => 'Questo account avrà accesso alla gestione delle impostazioni, articoli e commenti.',
        'portal_desc' => 'Personalizza i testi mostrati sulla home page del portale.',
        'portal_name' => 'Nome Portale / Network (Header)',
        'hero_small' => 'Piccolo Titolo Superiore', 'hero_main' => 'Titolo Principale (Hero)', 'hero_desc_label' => 'Descrizione sotto il titolo principale',
        'footer_title' => 'Titolo Inferiore (Footer Banner)', 'footer_desc' => 'Descrizione Inferiore (Footer Desc)',
        'servers_desc' => 'Impostazioni server e selettore (rilevati', 'servers_desc_end' => 'server attivi).',
        'selector_box' => 'Attiva Server Selector', 'selector_desc' => 'I giocatori vedranno una pagina di selezione con tutti i server all\'avvio.',
        'default_server' => 'Server Predefinito Principale (Mostrato direttamente senza selettore)',
        'server_config_title' => 'Configurazione Dettagliata Server',
        'hide_server' => 'Nascondi Server', 'display_name' => 'Nome Visualizzato', 'server_color' => 'Colore Tema Server',
        'dynmap_on' => 'Abilita Dynmap', 'dynmap_url' => 'URL Dynmap (iframe)', 'chat_on' => 'Consenti Scrittura in Chat via Web',
        'badge_text' => 'Badge di Stato', 'server_desc' => 'Descrizione Server', 'target_url' => 'URL di Destinazione (Pulsante Entra)',
        'btn_next' => 'Continua', 'btn_back' => 'Indietro', 'btn_finish' => 'Completa Installazione',
        'success_title' => 'Installazione completata con successo!',
        'success_desc' => 'Tutte le impostazioni globali, configurazioni server, permessi admin e connessione database sono stati salvati.',
        'security_warn' => 'Non dimenticare di eliminare il file install.php dal server.', 'btn_enter' => 'Entra nel Portale',
        'err_conn' => 'Connessione al database fallita! Controlla le credenziali.', 'err_admin' => 'Inserisci il nickname dell\'amministratore!', 'err_save' => 'Errore durante il salvataggio: '
    ],
    'fr' => [
        'title' => 'Installation | CoreNetwork Setup',
        'step_lang' => 'Langue', 'step_db' => 'DB', 'step_admin' => 'Admin', 'step_portal' => 'Portail', 'step_servers' => 'Serveurs',
        'lang_title' => 'Sélection de la langue',
        'lang_desc' => 'Choisissez la langue que vous souhaitez utiliser pour l\'installation.',
        'db_desc' => 'Entrez les identifiants de connexion de votre base de données MySQL (CoreSync).',
        'db_host' => 'Hôte de la base de données', 'db_name' => 'Nom de la base de données', 'db_user' => 'Utilisateur de la base de données', 'db_pass' => 'Mot de passe de la base de données', 'db_pass_ph' => 'Entrez le mot de passe...',
        'admin_desc' => 'Entrez le pseudo en jeu du joueur qui obtiendra les permissions d\'administrateur.',
        'admin_nick' => 'Pseudo sur le serveur (Admin Principal)', 'admin_desc_sub' => 'Ce compte aura accès à la gestion des paramètres, des articles et des commentaires.',
        'portal_desc' => 'Personnalisez les textes affichés sur la page d\'accueil du portail.',
        'portal_name' => 'Nom du Portail / Réseau (En-tête)',
        'hero_small' => 'Petit Titre Supérieur', 'hero_main' => 'Titre Principal (Héro)', 'hero_desc_label' => 'Description sous le titre principal',
        'footer_title' => 'Titre Inférieur (Bannière de Pied de Page)', 'footer_desc' => 'Description Inférieure',
        'servers_desc' => 'Paramètres des serveurs et mode de sélection (détectés', 'servers_desc_end' => 'serveurs actifs).',
        'selector_box' => 'Activer le Sélecteur de Serveur', 'selector_desc' => 'Les joueurs verront une page de sélection avec tous les serveurs au démarrage.',
        'default_server' => 'Serveur Principal par Défaut (Affiché directement sans sélecteur)',
        'server_config_title' => 'Configuration Détaillée des Serveurs',
        'hide_server' => 'Masquer le Serveur', 'display_name' => 'Nom Affiché', 'server_color' => 'Couleur du Thème du Serveur',
        'dynmap_on' => 'Activer Dynmap', 'dynmap_url' => 'URL Dynmap (iframe)', 'chat_on' => 'Autoriser l\'écriture dans le chat via le Web',
        'badge_text' => 'Badge de Statut', 'server_desc' => 'Description du Serveur', 'target_url' => 'URL Cible (Bouton Rejoindre)',
        'btn_next' => 'Continuer', 'btn_back' => 'Retour', 'btn_finish' => 'Terminer l\'installation',
        'success_title' => 'Installation terminée avec succès !',
        'success_desc' => 'Tous les paramètres globaux, configurations de serveurs, permissions admin et connexion BDD ont été enregistrés.',
        'security_warn' => 'N\'oubliez pas de supprimer le fichier install.php de votre serveur.', 'btn_enter' => 'Entrer sur le Portail',
        'err_conn' => 'Échec de la connexion à la base de données ! Vérifiez vos identifiants.', 'err_admin' => 'Entrez le pseudo de l\'administrateur !', 'err_save' => 'Erreur lors de l\'enregistrement des paramètres : '
    ]
];

$__t = function($key) use ($i18n, $installLang) {
    return $i18n[$installLang][$key] ?? $i18n['sk'][$key] ?? $key;
};

// Helper funkcia na overenie DB spojenia zo session
function checkActiveDbConnection() {
    if (empty($_SESSION['db_host']) || empty($_SESSION['db_name']) || empty($_SESSION['db_user'])) {
        return false;
    }
    try {
        $dsn = "mysql:host=" . $_SESSION['db_host'] . ";dbname=" . $_SESSION['db_name'] . ";charset=utf8mb4";
        $pdo = new PDO($dsn, $_SESSION['db_user'], $_SESSION['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        return $pdo;
    } catch (Exception $e) {
        return false;
    }
}

// Krok 1 -> Krok 2: Spracovanie pripojenia k DB
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $hostInput = trim($_POST['db_host'] ?? '127.0.0.1');
    $_SESSION['db_host'] = ($hostInput === 'localhost') ? '127.0.0.1' : $hostInput;
    $_SESSION['db_name'] = trim($_POST['db_name'] ?? '');
    $_SESSION['db_user'] = trim($_POST['db_user'] ?? '');
    $_SESSION['db_pass'] = $_POST['db_pass'] ?? '';

    $testPdo = checkActiveDbConnection();
    if (!$testPdo) {
        $error = $__t('err_conn');
        $step = 2;
    } else {
        header('Location: install.php?step=3');
        exit;
    }
}

// Krok 2 -> Krok 3: Uloženie admin nicku do session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 3) {
    $adminNick = trim($_POST['admin_nick'] ?? '');
    if (empty($adminNick)) {
        $error = $__t('err_admin');
        $step = 3;
    } else {
        $_SESSION['admin_nick'] = $adminNick;
        header('Location: install.php?step=4');
        exit;
    }
}

// Krok 3 -> Krok 4: Uloženie textov portálu do session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 4) {
    $_SESSION['portal_name'] = trim($_POST['portal_name'] ?? 'Core Network');
    $_SESSION['hero_small_title'] = trim($_POST['hero_small_title'] ?? 'HERNÝ PORTÁL');
    $_SESSION['hero_main_title'] = trim($_POST['hero_main_title'] ?? 'VYBER SI SVOJ SVET');
    $_SESSION['hero_desc'] = trim($_POST['hero_desc'] ?? 'Zvoľ si svoju cestu...');

    $_SESSION['footer_title'] = trim($_POST['footer_title'] ?? 'JEDEN PORTÁL...');
    $_SESSION['footer_desc'] = trim($_POST['footer_desc'] ?? 'Nezáleží na tom...');

    header('Location: install.php?step=5');
    exit;
}

// Krok 4 -> Dokončenie: Uloženie všetkého do DB a zápis db.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 5) {
    $enableSelector = isset($_POST['enable_selector']) ? 1 : 0;
    $defaultServer = trim($_POST['default_server'] ?? '');
    $serversConfig = $_POST['server_config'] ?? [];

    $pdo = checkActiveDbConnection();
    if (!$pdo) {
        header('Location: install.php?step=2&error=Connection_Lost');
        exit;
    }

    try {
        // 1. Zápis konfiguračného súboru includes/db.php
        $dbConfigContent = "<?php\n";
        $dbConfigContent .= "// cfg/db.php\n";
        $dbConfigContent .= "return [\n";
        $dbConfigContent .= "    'host'    => '" . addslashes($_SESSION['db_host']) . "',\n";
        $dbConfigContent .= "    'db'      => '" . addslashes($_SESSION['db_name']) . "',\n";
        $dbConfigContent .= "    'user'    => '" . addslashes($_SESSION['db_user']) . "',\n";
        $dbConfigContent .= "    'pass'    => '" . addslashes($_SESSION['db_pass']) . "',\n";
        $dbConfigContent .= "    'charset' => 'utf8mb4'\n";
        $dbConfigContent .= "];\n";

        if (!is_dir(__DIR__ . '/cfg')) {
            mkdir(__DIR__ . '/cfg', 0755, true);
        }
        file_put_contents(__DIR__ . '/cfg/db.php', $dbConfigContent);

        // 2. Vytvorenie tabuľky pre portálové nastavenia
        $pdo->exec("CREATE TABLE IF NOT EXISTS `coresync_portal_settings` (
            `setting_key` VARCHAR(64) PRIMARY KEY,
            `setting_value` TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $stmt = $pdo->prepare("REPLACE INTO `coresync_portal_settings` (`setting_key`, `setting_value`) VALUES (?, ?)");
        $stmt->execute(['portal_name', $_SESSION['portal_name'] ?? 'Core Network']);
        $stmt->execute(['portal_logo_size', '100']);
        $stmt->execute(['portal_logo', '']);
        $stmt->execute(['site_favicon', '']);
        $stmt->execute(['hero_small_title', $_SESSION['hero_small_title'] ?? '']);
        $stmt->execute(['hero_main_title', $_SESSION['hero_main_title'] ?? '']);
        $stmt->execute(['hero_desc', $_SESSION['hero_desc'] ?? '']);
        $stmt->execute(['footer_title', $_SESSION['footer_title'] ?? '']);
        $stmt->execute(['footer_desc', $_SESSION['footer_desc'] ?? '']);
        $stmt->execute(['meta_description', '']);
        $stmt->execute(['meta_keywords', '']);
        $stmt->execute(['selector_bg', '']);
        $stmt->execute(['enable_server_selector', $enableSelector]);
        $stmt->execute(['default_server', $defaultServer]);
        $stmt->execute(['portal_timezone', 'Europe/Bratislava']);

        // 3. Vytvorenie tabuľky pre administrátorov a oprávnenia
        $pdo->exec("CREATE TABLE IF NOT EXISTS `coresync_admins` (
            `username` VARCHAR(64) PRIMARY KEY,
            `permissions` TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $adminNick = $_SESSION['admin_nick'] ?? 'Admin';
        $fullPermissions = json_encode(["viewSettings","editSettings","writeArticle","editArticle","editArticleOthers","deleteArticle","deleteArticleOthers","manageComments","viewPages","editPages","deletePages","viewRewards","manageRewards","deleteRewards","viewVoting","editVoteSites","deleteVoteSites","editVoteRewards","deleteVoteRewards","manageWebshop","addAdminShop","deleteAdminShop","moderatePlayerMarket","manageWebshopSettings","viewUsers","manageUsers","managePlugins"]);
        $stmtAdmin = $pdo->prepare("REPLACE INTO `coresync_admins` (`username`, `permissions`) VALUES (?, ?)");
        $stmtAdmin->execute([$adminNick, $fullPermissions]);

        // Vytvorenie tabuľky pre články
        $pdo->exec("CREATE TABLE IF NOT EXISTS `coresync_articles` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(255) NOT NULL,
            `slug` VARCHAR(255) NOT NULL UNIQUE,
            `content` TEXT NOT NULL,
            `author` VARCHAR(64) NOT NULL,
            `image` VARCHAR(255) DEFAULT '',
            `server_name` VARCHAR(64) DEFAULT 'global',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Vytvorenie tabuľky pre galériu fotiek k článkom
        $pdo->exec("CREATE TABLE IF NOT EXISTS `coresync_article_images` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `article_id` INT NOT NULL,
            `image_path` VARCHAR(255) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`article_id`) REFERENCES `coresync_articles`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Vytvorenie tabuľky pre komentáre k článkom
        $pdo->exec("CREATE TABLE IF NOT EXISTS `coresync_article_comments` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `article_id` INT NOT NULL,
            `author` VARCHAR(64) NOT NULL,
            `comment` TEXT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`article_id`) REFERENCES `coresync_articles`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Vytvorenie tabuľky pre hlasovacie stránky
        $pdo->exec("CREATE TABLE IF NOT EXISTS `coresync_vote_sites` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `site_name` VARCHAR(128) NOT NULL,
            `site_url` VARCHAR(255) NOT NULL,
            `server_name` VARCHAR(64) DEFAULT 'global',
            `banner_image` VARCHAR(255) DEFAULT '',
            `sort_order` INT DEFAULT 0,
            `is_active` TINYINT(1) DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `coresync_vote_rewards` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `server_name` VARCHAR(64) DEFAULT 'global',
            `title` VARCHAR(128) NOT NULL,
            `description` TEXT DEFAULT NULL,
            `image` VARCHAR(255) NOT NULL,
            `sort_order` INT DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");


        // 4. Vytvorenie tabuľky pre konfiguráciu serverov
        $pdo->exec("CREATE TABLE IF NOT EXISTS `coresync_servers_config` (
            `server_name` VARCHAR(64) PRIMARY KEY,
            `display_name` VARCHAR(128) NOT NULL,
            `badge_text` VARCHAR(64) DEFAULT 'ONLINE',
            `server_color` VARCHAR(16) DEFAULT '#00BFFF',
            `description` TEXT,
            `target_url` VARCHAR(255) DEFAULT '#',
            `is_hidden` TINYINT(1) DEFAULT 0,
            `bg_image` VARCHAR(255) DEFAULT '',
            `card_image` VARCHAR(255) DEFAULT '',
            `enable_dynmap` TINYINT(1) DEFAULT 0,
            `dynmap_url` VARCHAR(255) DEFAULT '',
            `allow_webchat_typing` TINYINT(1) DEFAULT 1,
            `allow_global_webchat` TINYINT(1) DEFAULT 1,
            `webchat_min_playtime_hours` INT DEFAULT 1,
            `comment_min_playtime_hours` INT DEFAULT 1,
            `server_ip` VARCHAR(128) DEFAULT '',
            `connection_info` TEXT DEFAULT NULL,
            `download_url` VARCHAR(255) DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Vytvorenie tabuľky pre vlastné dynamické stránky
        $pdo->exec("CREATE TABLE IF NOT EXISTS `coresync_custom_pages` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `slug` VARCHAR(128) NOT NULL UNIQUE,
            `title` VARCHAR(255) NOT NULL,
            `menu_title` VARCHAR(128) DEFAULT '',
            `page_title` VARCHAR(255) DEFAULT '',
            `icon` VARCHAR(64) DEFAULT 'fa-solid fa-file-lines',
            `header_image` VARCHAR(255) DEFAULT '',
            `content` LONGTEXT NOT NULL,
            `show_in_menu` TINYINT(1) DEFAULT 0,
            `open_in_new_tab` TINYINT(1) DEFAULT 0,
            `is_published` TINYINT(1) DEFAULT 1,
            `menu_order` INT DEFAULT 0,
            `attachment_url` VARCHAR(255) DEFAULT '',
            `attachment_name` VARCHAR(128) DEFAULT '',
            `server_name` VARCHAR(64) DEFAULT 'global',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        foreach ($serversConfig as $srvName => $conf) {
            $displayName = trim($conf['display_name'] ?? $srvName);
            $badgeText = trim($conf['badge_text'] ?? 'ONLINE');
            $serverColor = trim($conf['server_color'] ?? '#00BFFF');
            $description = trim($conf['description'] ?? '');
            $targetUrl = trim($conf['target_url'] ?? 'index.php?server=' . urlencode($srvName));
            $isHidden = isset($conf['is_hidden']) ? 1 : 0;

            $enableDynmap = isset($conf['enable_dynmap']) ? 1 : 0;
            $dynmapUrl = trim($conf['dynmap_url'] ?? '');
            $allowChat = isset($conf['allow_webchat_typing']) ? 1 : 0;
            $webchatMinPlaytime = (int)($conf['webchat_min_playtime_hours'] ?? 1);
            $commentMinPlaytime = (int)($conf['comment_min_playtime_hours'] ?? 1);

            $stmtIns =$pdo->prepare("REPLACE INTO `coresync_servers_config` (`server_name`, `display_name`, `badge_text`, `server_color`, `description`, `target_url`, `is_hidden`, `enable_dynmap`, `dynmap_url`, `allow_webchat_typing`, `webchat_min_playtime_hours`, `comment_min_playtime_hours`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtIns->execute([$srvName,$displayName, $badgeText,$serverColor, $description,$targetUrl, $isHidden,$enableDynmap, $dynmapUrl,$allowChat, $webchatMinPlaytime,$commentMinPlaytime]);
        }

        header('Location: install.php?step=99');
        exit;

    } catch (Exception $e) {
        $error =$__t('err_save') . $e->getMessage();$step = 5;
    }
}

if (isset($_GET['error']) && $_GET['error'] === 'Connection_Lost') {$error = $__t('err_conn');$step = 2;
}
?>
<!DOCTYPE html>
<html lang="<?= $installLang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $__t('title') ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #00BFFF;
            --bg-color: #050505;
            --panel-bg: rgba(12, 12, 12, 0.95);
            --border-color: rgba(255, 255, 255, 0.08);
        }
        body {
            background-color: var(--bg-color);
            color: #fff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 40px 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            box-sizing: border-box;
        }
        .install-container {
            width: 100%;
            max-width: 750px;
            background: var(--panel-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.8);
            backdrop-filter: blur(15px);
        }
        .steps-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            position: relative;
        }
        .step-dot {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 13px;
            color: #777;
        }
        .step-dot.active {
            background: rgba(0,191,255,0.15);
            border-color: var(--primary-color);
            color: var(--primary-color);
            box-shadow: 0 0 15px rgba(0,191,255,0.3);
        }
        .step-dot.completed {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: #000;
        }
        h1 { font-size: 22px; font-weight: 900; text-transform: uppercase; margin-top: 0; margin-bottom: 10px; }
        h1 span { color: var(--primary-color); }
        p { color: #aaa; font-size: 14px; line-height: 1.6; margin-bottom: 25px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-size: 13px; font-weight: 700; text-transform: uppercase; margin-bottom: 8px; color: #ccc; }
        input[type="text"], input[type="password"], textarea, select {
            width: 100%;
            background: rgba(0, 0, 0, 0.5);
            border: 1px solid var(--border-color);
            padding: 12px 15px;
            border-radius: 10px;
            color: #fff;
            font-size: 14px;
            outline: none;
            box-sizing: border-box;
            transition: 0.2s;
            font-family: inherit;
        }
        input[type="color"] {
            -webkit-appearance: none;
            border: none;
            width: 45px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
            background: none;
        }
        input[type="color"]::-webkit-color-swatch-wrapper { padding: 0; }
        input[type="color"]::-webkit-color-swatch { border: 1px solid var(--border-color); border-radius: 8px; }
        input:focus, textarea:focus, select:focus { border-color: var(--primary-color); box-shadow: 0 0 10px rgba(0,191,255,0.2); }
        select option { background: #111; color: #fff; }

        .color-presets { display: flex; gap: 8px; margin-bottom: 8px; }
        .color-preset-btn { width: 28px; height: 28px; border-radius: 6px; border: 2px solid rgba(255,255,255,0.2); cursor: pointer; transition: 0.2s; }
        .color-preset-btn:hover { transform: scale(1.15); border-color: #fff; }

        .server-config-card {
            background: rgba(255,255,255,0.02);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .checkbox-group { display: flex; align-items: center; gap: 10px; margin-top: 15px; }
        .checkbox-group input { width: 18px; height: 18px; accent-color: var(--primary-color); cursor: pointer; }
        .checkbox-group label { margin: 0; cursor: pointer; color: #fff; text-transform: none; font-weight: 600; }

        .btn-submit {
            display: block;
            width: 100%;
            background: var(--primary-color);
            color: #000;
            border: none;
            padding: 14px;
            border-radius: 12px;
            font-weight: 900;
            text-transform: uppercase;
            font-size: 14px;
            cursor: pointer;
            transition: 0.2s;
            text-align: center;
            text-decoration: none;
        }
        .btn-submit:hover { background: #fff; box-shadow: 0 0 20px rgba(255,255,255,0.4); }

        .btn-back {
            display: inline-block;
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
            border: 1px solid var(--border-color);
            padding: 14px 24px;
            border-radius: 12px;
            font-weight: 900;
            text-transform: uppercase;
            font-size: 14px;
            cursor: pointer;
            transition: 0.2s;
            text-align: center;
            text-decoration: none;
        }
        .btn-back:hover { background: rgba(255, 255, 255, 0.1); border-color: rgba(255,255,255,0.3); }

        .button-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }

        .alert-error { background: rgba(255, 68, 68, 0.1); border: 1px solid rgba(255, 68, 68, 0.3); color: #ff4444; padding: 15px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; }
        .alert-success { background: rgba(0, 191, 255, 0.1); border: 1px solid rgba(0, 191, 255, 0.3); color: var(--primary-color); padding: 15px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; }

        .lang-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 15px; margin-top: 20px; }
        .lang-card { background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: 14px; padding: 20px; text-align: center; text-decoration: none; color: #fff; transition: 0.2s; display: flex; flex-direction: column; align-items: center; gap: 10px; }
        .lang-card:hover, .lang-card.active { background: rgba(0,191,255,0.1); border-color: var(--primary-color); box-shadow: 0 0 15px rgba(0,191,255,0.2); }
        .lang-flag { font-size: 36px; }
        .lang-name { font-weight: 800; text-transform: uppercase; font-size: 13px; }
    </style>
</head>
<body>

<div class="install-container">
    <div class="steps-indicator">
        <div class="step-dot <?= ($step === 1) ? 'active' : ($step > 1 ? 'completed' : '') ?>">1</div>
        <div class="step-dot <?= ($step === 2) ? 'active' : ($step > 2 ? 'completed' : '') ?>">2</div>
        <div class="step-dot <?= ($step === 3) ? 'active' : ($step > 3 ? 'completed' : '') ?>">3</div>
        <div class="step-dot <?= ($step === 4) ? 'active' : ($step > 4 ? 'completed' : '') ?>">4</div>
        <div class="step-dot <?= ($step === 5) ? 'active' : '' ?>">5</div>
    </div>

    <h1>CoreNetwork <span>Setup Wizard</span></h1>

    <?php if (!empty($error)): ?>
        <div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
        <!-- KROK 1: VÝBER JAZYKA S VLAJKAMI -->
        <p><b>Krok 1/5:</b> <?= $__t('lang_title') ?></p>
        <p style="margin-top: -15px;"><?= $__t('lang_desc') ?></p>

        <div class="lang-grid">
            <a href="install.php?set_lang=sk" class="lang-card <?= ($installLang === 'sk') ? 'active' : '' ?>">
                <div class="lang-flag">🇸🇰</div>
                <div class="lang-name">Slovenčina</div>
            </a>
            <a href="install.php?set_lang=cz" class="lang-card <?= ($installLang === 'cz') ? 'active' : '' ?>">
                <div class="lang-flag">🇨🇿</div>
                <div class="lang-name">Čeština</div>
            </a>
            <a href="install.php?set_lang=en" class="lang-card <?= ($installLang === 'en') ? 'active' : '' ?>">
                <div class="lang-flag">🇬🇧</div>
                <div class="lang-name">English</div>
            </a>
            <a href="install.php?set_lang=it" class="lang-card <?= ($installLang === 'it') ? 'active' : '' ?>">
                <div class="lang-flag">🇮🇹</div>
                <div class="lang-name">Italiano</div>
            </a>
            <a href="install.php?set_lang=fr" class="lang-card <?= ($installLang === 'fr') ? 'active' : '' ?>">
                <div class="lang-flag">🇫🇷</div>
                <div class="lang-name">Français</div>
            </a>
        </div>

        <a href="install.php?step=2" class="btn-submit" style="margin-top: 30px;"><?= $__t('btn_next') ?></a>

    <?php elseif ($step === 2): ?>
        <!-- KROK 2: DATABÁZA -->
        <p><b>Krok 2/5:</b> <?= $__t('db_desc') ?></p>
        <form method="POST" action="install.php?step=2">
            <div class="form-group">
                <label><?= $__t('db_host') ?></label>
                <input type="text" name="db_host" value="<?= htmlspecialchars($_SESSION['db_host'] ?? '127.0.0.1') ?>" required>
            </div>
            <div class="form-group">
                <label><?= $__t('db_name') ?></label>
                <input type="text" name="db_name" value="<?= htmlspecialchars($_SESSION['db_name'] ?? 'coresync') ?>" required>
            </div>
            <div class="form-group">
                <label><?= $__t('db_user') ?></label>
                <input type="text" name="db_user" value="<?= htmlspecialchars($_SESSION['db_user'] ?? 'root') ?>" required>
            </div>
            <div class="form-group">
                <label><?= $__t('db_pass') ?></label>
                <input type="password" name="db_pass" value="<?= htmlspecialchars($_SESSION['db_pass'] ?? '') ?>" placeholder="<?= $__t('db_pass_ph') ?>">
            </div>

            <div class="button-group">
                <a href="install.php?step=1" class="btn-back"><i class="fa-solid fa-arrow-left"></i> <?= $__t('btn_back') ?></a>
                <button type="submit" class="btn-submit" style="flex: 1; margin-top: 0;"><?= $__t('btn_next') ?></button>
            </div>
        </form>

    <?php elseif ($step === 3): ?>
        <!-- KROK 3: HLAVNÝ ADMINISTRÁTOR -->
        <p><b>Krok 3/5:</b> <?= $__t('admin_desc') ?></p>
        <form method="POST" action="install.php?step=3">
            <div class="form-group">
                <label><?= $__t('admin_nick') ?></label>
                <input type="text" name="admin_nick" value="<?= htmlspecialchars($_SESSION['admin_nick'] ?? 'DarkRider') ?>" placeholder="Napr. TvojMinecraftNick" required>
                <span style="font-size: 12px; color: #888; display: block; margin-top: 6px;"><?= $__t('admin_desc_sub') ?></span>
            </div>

            <div class="button-group">
                <a href="install.php?step=2" class="btn-back"><i class="fa-solid fa-arrow-left"></i> <?= $__t('btn_back') ?></a>
                <button type="submit" class="btn-submit" style="flex: 1; margin-top: 0;"><?= $__t('btn_next') ?></button>
            </div>
        </form>

    <?php elseif ($step === 4): ?>
        <!-- KROK 4: TEXTY A OBSAH PORTÁLU -->
        <p><b>Krok 4/5:</b> <?= $__t('portal_desc') ?></p>
        <form method="POST" action="install.php?step=4">
            <div class="form-group">
                <label><?= $__t('portal_name') ?></label>
                <input type="text" name="portal_name" value="<?= htmlspecialchars($_SESSION['portal_name'] ?? 'Core Network') ?>" required>
            </div>
            <hr style="border:0; border-top:1px solid var(--border-color); margin: 25px 0;">
            <div class="form-group">
                <label><?= $__t('hero_small') ?></label>
                <input type="text" name="hero_small_title" value="<?= htmlspecialchars($_SESSION['hero_small_title'] ?? 'HERNÝ PORTÁL') ?>" required>
            </div>
            <div class="form-group">
                <label><?= $__t('hero_main') ?></label>
                <input type="text" name="hero_main_title" value="<?= htmlspecialchars($_SESSION['hero_main_title'] ?? 'VYBER SI SVOJ SVET') ?>" required>
            </div>
            <div class="form-group">
                <label><?= $__t('hero_desc_label') ?></label>
                <textarea name="hero_desc" rows="2" required><?= htmlspecialchars($_SESSION['hero_desc'] ?? 'Zvoľ si svoju cestu...') ?></textarea>
            </div>
            <hr style="border:0; border-top:1px solid var(--border-color); margin: 25px 0;">
            <div class="form-group">
                <label><?= $__t('footer_title') ?></label>
                <input type="text" name="footer_title" value="<?= htmlspecialchars($_SESSION['footer_title'] ?? 'JEDEN PORTÁL...') ?>" required>
            </div>
            <div class="form-group">
                <label><?= $__t('footer_desc') ?></label>
                <textarea name="footer_desc" rows="2" required><?= htmlspecialchars($_SESSION['footer_desc'] ?? 'Nezáleží na tom...') ?></textarea>
            </div>

            <div class="button-group">
                <a href="install.php?step=3" class="btn-back"><i class="fa-solid fa-arrow-left"></i> <?= $__t('btn_back') ?></a>
                <button type="submit" class="btn-submit" style="flex: 1; margin-top: 0;"><?= $__t('btn_next') ?></button>
            </div>
        </form>

    <?php elseif ($step === 5): ?>
        <!-- KROK 5: SERVER SELECTOR A DETAILNÉ NASTAVENIA SERVEROV -->
        <?php
        $pdo = checkActiveDbConnection();$servers = [];
        try {
            $stmtSrv =$pdo->query("SELECT DISTINCT server_name FROM coresync_server_stats");
            $servers =$stmtSrv->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {$servers = [];
        }
        $srvCount = count($servers);
        ?>
        <p><b>Krok 5/5:</b> <?= $__t('servers_desc') ?> <b><?= $srvCount ?></b> <?= $__t('servers_desc_end') ?></p>

        <form method="POST" action="install.php?step=5">
            <div class="checkbox-group" style="background: rgba(0,191,255,0.05); border: 1px solid rgba(0,191,255,0.2); padding: 15px; border-radius: 12px; margin-bottom: 20px;">
                <input type="checkbox" id="enable_selector" name="enable_selector" value="1" checked onchange="toggleDefaultServerBox()">
                <label for="enable_selector"><b><?= $__t('selector_box') ?></b><br><span style="font-size: 12px; color: #aaa; font-weight: normal;"><?= $__t('selector_desc') ?></span></label>
            </div>

            <div class="form-group" id="defaultServerGroup" style="display: none;">
                <label><?= $__t('default_server') ?></label>
                <select name="default_server">
                    <?php if ($srvCount > 0): ?>
                        <?php foreach ($servers as$srv): ?>
                            <option value="<?= htmlspecialchars($srv) ?>"><?= htmlspecialchars($srv) ?></option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="global">global (Predvolený)</option>
                    <?php endif; ?>
                </select>
            </div>

            <?php if ($srvCount > 0): ?>
                <h3 style="font-size: 16px; font-weight: 800; text-transform: uppercase; margin: 30px 0 15px 0; color: var(--primary-color);"><?= $__t('server_config_title') ?></h3>

                <?php foreach ($servers as$srv): ?>
                    <div class="server-config-card">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                            <h4 style="margin: 0; font-size: 15px; color: #fff;"><i class="fa-solid fa-server"></i> Server ID: <code><?= htmlspecialchars($srv) ?></code></h4>

                            <div style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" id="hide_<?= htmlspecialchars($srv) ?>" name="server_config[<?= htmlspecialchars($srv) ?>][is_hidden]" value="1" style="width: 16px; height: 16px; accent-color: #ff4444; cursor: pointer;">
                                <label for="hide_<?= htmlspecialchars($srv) ?>" style="font-size: 12px; color: #ff6b6b; cursor: pointer; margin: 0; text-transform: none; font-weight: 700;"><?= $__t('hide_server') ?></label>
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: 12px;">
                            <label style="font-size: 11px;"><?= $__t('display_name') ?></label>
                            <input type="text" name="server_config[<?= htmlspecialchars($srv) ?>][display_name]" value="<?= htmlspecialchars(ucwords(str_replace('_', ' ', $srv))) ?>" required>
                        </div>

                        <div class="form-group" style="margin-bottom: 12px;">
                            <label style="font-size: 11px;"><?= $__t('server_color') ?></label>
                            <div class="color-presets">
                                <div class="color-preset-btn" style="background: #00BFFF;" onclick="setServerColor('<?= $srv ?>', '#00BFFF')"></div>
                                <div class="color-preset-btn" style="background: #a046ff;" onclick="setServerColor('<?= $srv ?>', '#a046ff')"></div>
                                <div class="color-preset-btn" style="background: #22c55e;" onclick="setServerColor('<?= $srv ?>', '#22c55e')"></div>
                                <div class="color-preset-btn" style="background: #ff9900;" onclick="setServerColor('<?= $srv ?>', '#ff9900')"></div>
                                <div class="color-preset-btn" style="background: #ec4899;" onclick="setServerColor('<?= $srv ?>', '#ec4899')"></div>
                            </div>
                            <input type="text" id="color_text_<?= htmlspecialchars($srv) ?>" name="server_config[<?= htmlspecialchars($srv) ?>][server_color]" value="#00BFFF" style="display:inline-block; width: calc(100% - 60px); vertical-align: middle;">
                            <input type="color" id="color_picker_<?= htmlspecialchars($srv) ?>" value="#00BFFF" style="display:inline-block; width: 50px; height: 42px; vertical-align: middle; margin-left: 5px; cursor: pointer; background:none; border:1px solid var(--border-color); border-radius:10px;" oninput="syncColor('<?= htmlspecialchars($srv) ?>', this.value)">
                        </div>

                        <div class="checkbox-group" style="margin-top: 10px;">
                            <input type="checkbox" id="dynmap_<?= htmlspecialchars($srv) ?>" name="server_config[<?= htmlspecialchars($srv) ?>][enable_dynmap]" value="1">
                            <label for="dynmap_<?= htmlspecialchars($srv) ?>"><?= $__t('dynmap_on') ?></label>
                        </div>
                        <div class="form-group" style="margin-top: 8px; margin-bottom: 8px;">
                            <label style="font-size: 11px;"><?= $__t('dynmap_url') ?></label>
                            <input type="text" name="server_config[<?= htmlspecialchars($srv) ?>][dynmap_url]" placeholder="https://map.mojserver.sk">
                        </div>
                        <div class="checkbox-group" style="margin-top: 5px;">
                            <input type="checkbox" id="chat_<?= htmlspecialchars($srv) ?>" name="server_config[<?= htmlspecialchars($srv) ?>][allow_webchat_typing]" value="1" checked>
                            <label for="chat_<?= htmlspecialchars($srv) ?>"><?= $__t('chat_on') ?></label>
                        </div>

                        <div class="form-group" style="margin-bottom: 12px;">
                            <label style="font-size: 11px;"><?= $__t('badge_text') ?></label>
                            <input type="text" name="server_config[<?= htmlspecialchars($srv) ?>][badge_text]" value="ONLINE">
                        </div>
                        <div class="form-group" style="margin-bottom: 12px;">
                            <label style="font-size: 11px;"><?= $__t('server_desc') ?></label>
                            <textarea name="server_config[<?= htmlspecialchars($srv) ?>][description]" rows="2" style="width:100%; background:rgba(0,0,0,0.5); border:1px solid var(--border-color); padding:10px; border-radius:8px; color:#fff; outline:none; resize:vertical;">Ultimátny herný zážitok na našom serveri.</textarea>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label style="font-size: 11px;"><?= $__t('target_url') ?></label>
                            <input type="text" name="server_config[<?= htmlspecialchars($srv) ?>][target_url]" value="index.php?server=<?= urlencode($srv) ?>">
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="button-group">
                <a href="install.php?step=4" class="btn-back"><i class="fa-solid fa-arrow-left"></i> <?= $__t('btn_back') ?></a>
                <button type="submit" class="btn-submit" style="flex: 1; margin-top: 0;"><?= $__t('btn_finish') ?></button>
            </div>
        </form>

        <script>
        function toggleDefaultServerBox() {
            const isChecked = document.getElementById('enable_selector').checked;
            const defaultGroup = document.getElementById('defaultServerGroup');
            if (isChecked) {
                defaultGroup.style.display = 'none';
            } else {
                defaultGroup.style.display = 'block';
            }
        }
        toggleDefaultServerBox();

        function setServerColor(srvName, hexColor) {
            document.getElementById('color_text_' + srvName).value = hexColor;
            document.getElementById('color_picker_' + srvName).value = hexColor;
        }

        function syncColor(srvName, hexColor) {
            document.getElementById('color_text_' + srvName).value = hexColor;
        }
        </script>

    <?php elseif ($step === 99): ?>
        <div class="alert-success"><i class="fa-solid fa-circle-check"></i> <?= $__t('success_title') ?></div>
        <p><?= $__t('success_desc') ?></p>
        <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 15px; border-radius: 12px; margin-bottom: 25px; font-size: 13px; color: #ccc;">
            <i class="fa-solid fa-shield-halved" style="color: var(--primary-color);"></i> <b>Bezpečnostné upozornenie:</b> Nezabudnite súbor <code>install.php</code> zo servera vymazať.
        </div>
        <a href="admin/settings.php" class="btn-submit"><?= $__t('btn_enter') ?></a>
    <?php endif; ?>
</div>

</body>
</html>
