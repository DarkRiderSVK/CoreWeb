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

$availableLangs = ['sk', 'cz', 'en', 'pl', 'fr', 'it'];$currentLang = $_GET['lang'] ?? $_SESSION['parser_lang'] ?? 'sk';
if (!in_array($currentLang, $availableLangs)) {$currentLang = 'sk'; }
$_SESSION['parser_lang'] =$currentLang;

// Cesty k priečinkom v /uploads (o jednu úroveň vyššie od /utils)
$sourceDir = __DIR__ . '/../uploads/raw_icons/';$outputDir = __DIR__ . '/../uploads/custom_icons/';

// Automatické vytvorenie priečinkov v /uploads, ak neexistujú
if (!is_dir($sourceDir)) { mkdir($sourceDir, 0777, true); }
if (!is_dir($outputDir)) { mkdir($outputDir, 0777, true); }

$translations = [
    'sk' => [
        'title' => 'ItemNameParser & Správa Ikoniek',
        'badge' => 'Administrátorský nástroj',
        'back' => '← Späť na prehľad utilít',
        'intro_title' => 'Tvorba a implementácia vlastných ikon pre modované servery',
        'intro_text' => 'Myslíme na všetky aspekty siete – kým Vanilla portál obsahuje všetky ikony automaticky, pri modovaných serveroch je potrebné portálu pomôcť. Pre export predmetov z hry využite mody <a href="https://www.curseforge.com/minecraft/mc-mods/iconexporter" target="_blank" style="color: #38bdf8;">IconExporter</a> a <a href="https://www.curseforge.com/minecraft/mc-mods/cyclops-core" target="_blank" style="color: #38bdf8;">Cyclops Core</a> v testovacom Singleplayer kliente.',
        'guide_title' => 'Návod na generovanie a nasadenie:',
        'step_1' => 'Zapnite si klienta s IconExporter a Cyclops Core a pripojte sa do lokálneho sveta.',
        'step_2' => 'Zadajte príkaz: <code style="color: #f59e0b;">/iconexporter export</code>',
        'step_3' => 'Vygenerované súbory zložte a nahrajte do priečinka na serveri: <code style="color: #f59e0b;">/uploads/raw_icons/</code>',
        'step_4' => 'Ak majú súbory starý formát <code>mod__item_name.png</code>, spustite konverziu nižšie. Nástroj ich preorganizuje do priečinka: <code style="color: #f59e0b;">/uploads/custom_icons/</code>',
        'step_5' => 'Pre finálnu funkčnosť na webe vytvorte v adresári <code>/assets</code> priečinok s názvom presne podľa <b>SLUG názvu serveru</b> z CoreSync konfigurácie (napr. <code>ZombieServer_1_21_1</code>) a presuňte doň obsah z <code>custom_icons</code>.',
        'format_title' => 'Podporovaná štruktúra:',
        'format_text' => 'Portál natívne číta štruktúru <code>/assets/&lt;slug_serveru&gt;/&lt;mod_name&gt;/&lt;item_name&gt;.png</code> (prípadne s príponou <code>_x36.png</code>). Plochý formát <code>mod__item_name.png</code> web nepodporuje.',
        'util_title' => 'Hromadný konvertor pre starý formát',
        'util_desc' => 'Ak vám IconExporter uložil súbory ako <code>mod__item_name.png</code>, kliknite na tlačidlo nižšie pre automatické rozbalenie do štruktúry priečinkov.',
        'start_btn' => 'Spustiť hromadnú konverziu',
        'processing' => 'Spracovávam súbory...',
        'completed' => 'Konverzia bola úspešne dokončená!',
        'success_count' => 'Úspešne spracovaných ikoniek:',
        'error_dir' => 'Chyba: Priečinok <code>/uploads/raw_icons/</code> neexistuje alebo je prázdny.',
        'footer_text' => 'Multi-verzná Minecraft sieť so synchronizovaným webshopom a databázou.',
        'rights' => 'Všetky práva vyhradené.'
    ],
    'cz' => [
        'title' => 'ItemNameParser & Správa Ikonek',
        'badge' => 'Administrátorský nástroj',
        'back' => '← Zpět na přehled utilit',
        'intro_title' => 'Tvorba a implementace vlastních ikon pro modované servery',
        'intro_text' => 'Myslíme na všechny aspekty sítě – zatímco Vanilla portál obsahuje všechny ikony automaticky, u modovaných serverů je potřeba portálu pomoci. Pro export předmětů využijte mody <a href="https://www.curseforge.com/minecraft/mc-mods/iconexporter" target="_blank" style="color: #38bdf8;">IconExporter</a> a <a href="https://www.curseforge.com/minecraft/mc-mods/cyclops-core" target="_blank" style="color: #38bdf8;">Cyclops Core</a> v testovacím Singleplayer kliente.',
        'guide_title' => 'Návod na generování a nasazení:',
        'step_1' => 'Zapněte klienta s IconExporter a Cyclops Core a připojte se do lokálního světa.',
        'step_2' => 'Zadejte příkaz: <code style="color: #f59e0b;">/iconexporter export</code>',
        'step_3' => 'Vygenerované soubory nahrajte do složky na serveru: <code style="color: #f59e0b;">/uploads/raw_icons/</code>',
        'step_4' => 'Pokud mají soubory starý formát <code>mod__item_name.png</code>, spusťte konverzi níže. Nástroj je přeorganizuje do složky: <code style="color: #f59e0b;">/uploads/custom_icons/</code>',
        'step_5' => 'Pro finální funkčnost vytvořte v adresáři <code>/assets</code> složku s názvem přesně podle <b>SLUG názvu serveru</b> z CoreSync (např. <code>ZombieServer_1_21_1</code>) a přesuňte do ní obsah z <code>custom_icons</code>.',
        'format_title' => 'Podporovaná struktura:',
        'format_text' => 'Portál nativně čte strukturu <code>/assets/&lt;slug_serveru&gt;/&lt;mod_name&gt;/&lt;item_name&gt;.png</code> (případně s příponou <code>_x36.png</code>). Plochý formát web nepodporuje.',
        'util_title' => 'Hromadný konvertor pro starý formát',
        'util_desc' => 'Pokud vám IconExporter uložil soubory jako <code>mod__item_name.png</code>, klikněte na tlačítko níže pro automatické rozbalení do struktury.',
        'start_btn' => 'Spustit hromadnou konverzi',
        'processing' => 'Zpracovávám soubory...',
        'completed' => 'Konverze byla úspěšně dokončena!',
        'success_count' => 'Úspěšně zpracovaných ikonek:',
        'error_dir' => 'Chyba: Složka <code>/uploads/raw_icons/</code> neexistuje nebo je prázdná.',
        'footer_text' => 'Multi-verzní Minecraft síť se synchronizovaným webshopem a databází.',
        'rights' => 'Všechna práva vyhrazena.'
    ],
    'en' => [
        'title' => 'ItemNameParser & Icons Manager',
        'badge' => 'Administrator Tool',
        'back' => '← Back to Utilities',
        'intro_title' => 'Creation and Implementation of Custom Icons for Modded Servers',
        'intro_text' => 'We think of all network aspects – while the Vanilla portal contains all icons automatically, modded servers require assistance. Use <a href="https://www.curseforge.com/minecraft/mc-mods/iconexporter" target="_blank" style="color: #38bdf8;">IconExporter</a> and <a href="https://www.curseforge.com/minecraft/mc-mods/cyclops-core" target="_blank" style="color: #38bdf8;">Cyclops Core</a> in a test Singleplayer client to export items.',
        'guide_title' => 'Generation and Deployment Guide:',
        'step_1' => 'Launch your client with IconExporter and Cyclops Core and join a local world.',
        'step_2' => 'Run command: <code style="color: #f59e0b;">/iconexporter export</code>',
        'step_3' => 'Upload generated files to the server folder: <code style="color: #f59e0b;">/uploads/raw_icons/</code>',
        'step_4' => 'If files use the old <code>mod__item_name.png</code> format, run conversion below to reorganize them into: <code style="color: #f59e0b;">/uploads/custom_icons/</code>',
        'step_5' => 'For proper functionality, create a folder inside <code>/assets</code> named exactly after the <b>Server SLUG name</b> from CoreSync (e.g., <code>ZombieServer_1_21_1</code>) and move the contents of <code>custom_icons</code> into it.',
        'format_title' => 'Supported Structure:',
        'format_text' => 'The portal natively reads <code>/assets/&lt;slug_server&gt;/&lt;mod_name&gt;/&lt;item_name&gt;.png</code> structures. Flat formats are not supported.',
        'util_title' => 'Batch Converter for Older Format',
        'util_desc' => 'If IconExporter saved files as <code>mod__item_name.png</code>, click the button below to automatically unpack them into the correct folder structure.',
        'start_btn' => 'Start Batch Conversion',
        'processing' => 'Processing files...',
        'completed' => 'Conversion completed successfully!',
        'success_count' => 'Successfully processed icons:',
        'error_dir' => 'Error: Folder <code>/uploads/raw_icons/</code> does not exist or is empty.',
        'footer_text' => 'Multi-version Minecraft network with a synced webshop and database.',
        'rights' => 'All rights reserved.'
    ],
    'pl' => [
        'title' => 'ItemNameParser i Zarządzanie Ikonami',
        'badge' => 'Narzędzie administratora',
        'back' => '← Powrót do narzędzi',
        'intro_title' => 'Tworzenie i wdrażanie niestandardowych ikon dla serwerów modowanych',
        'intro_text' => 'Dbamy o każdy aspekt sieci. Użyj modów <a href="https://www.curseforge.com/minecraft/mc-mods/iconexporter" target="_blank" style="color: #38bdf8;">IconExporter</a> i <a href="https://www.curseforge.com/minecraft/mc-mods/cyclops-core" target="_blank" style="color: #38bdf8;">Cyclops Core</a> w kliencie testowym Singleplayer.',
        'guide_title' => 'Instrukcja generowania i wdrożenia:',
        'step_1' => 'Uruchom klienta z modami i wejdź na świat.',
        'step_2' => 'Wpisz komendę: <code style="color: #f59e0b;">/iconexporter export</code>',
        'step_3' => 'Prześlij wygenerowane pliki do folderu: <code style="color: #f59e0b;">/uploads/raw_icons/</code>',
        'step_4' => 'Jeśli pliki mają stary format <code>mod__item_name.png</code>, uruchom konwersję poniżej do: <code style="color: #f59e0b;">/uploads/custom_icons/</code>',
        'step_5' => 'Utwórz w <code>/assets</code> folder o nazwie <b>SLUG serwera</b> (np. <code>ZombieServer_1_21_1</code>) i przenieś tam zawartość z <code>custom_icons</code>.',
        'format_title' => 'Obsługiwana struktura:',
        'format_text' => 'Struktura folderów: <code>/assets/&lt;slug_serwera&gt;/&lt;mod_name&gt;/&lt;item_name&gt;.png</code>.',
        'util_title' => 'Konwerter wsadowy dla starego formatu',
        'util_desc' => 'Jeśli IconExporter zapisał pliki jako <code>mod__item_name.png</code>, kliknij przycisk poniżej.',
        'start_btn' => 'Rozpocznij konwersję wsadową',
        'processing' => 'Przetwarzanie...',
        'completed' => 'Konwersja zakończona sukcesem!',
        'success_count' => 'Przetworzone ikony:',
        'error_dir' => 'Błąd: Folder <code>/uploads/raw_icons/</code> nie istnieje lub jest pusty.',
        'footer_text' => 'Wersjonowana sieć Minecraft ze zsynchronizowanym sklepem i bazą danych.',
        'rights' => 'Wszelkie prawa zastrzeżone.'
    ],
    'fr' => [
        'title' => 'ItemNameParser & Gestionnaire d\'Icônes',
        'badge' => 'Outil Administrateur',
        'back' => '← Retour aux utilitaires',
        'intro_title' => 'Création et implémentation d\'icônes personnalisées',
        'intro_text' => 'Utilisez <a href="https://www.curseforge.com/minecraft/mc-mods/iconexporter" target="_blank" style="color: #38bdf8;">IconExporter</a> et <a href="https://www.curseforge.com/minecraft/mc-mods/cyclops-core" target="_blank" style="color: #38bdf8;">Cyclops Core</a> en Solo pour exporter les éléments.',
        'guide_title' => 'Guide de déploiement :',
        'step_1' => 'Lancez le client avec les mods et rejoignez un monde.',
        'step_2' => 'Exécutez : <code style="color: #f59e0b;">/iconexporter export</code>',
        'step_3' => 'Téléchargez les fichiers dans : <code style="color: #f59e0b;">/uploads/raw_icons/</code>',
        'step_4' => 'Si l\'ancien format est utilisé, lancez la conversion pour enregistrer dans : <code style="color: #f59e0b;">/uploads/custom_icons/</code>',
        'step_5' => 'Créez un dossier dans <code>/assets</code> nommé d\'après le <b>SLUG du serveur</b> (ex: <code>ZombieServer_1_21_1</code>) et déplacez-y le contenu.',
        'format_title' => 'Structure prise en charge :',
        'format_text' => 'Chemin : <code>/assets/&lt;slug&gt;/&lt;mod&gt;/&lt;item&gt;.png</code>.',
        'util_title' => 'Convertisseur pour l\'ancien format',
        'util_desc' => 'Si les fichiers sont au format <code>mod__item.png</code>, cliquez ci-dessous.',
        'start_btn' => 'Lancer la conversion',
        'processing' => 'Traitement...',
        'completed' => 'Conversion réussie !',
        'success_count' => 'Icônes traitées :',
        'error_dir' => 'Erreur : Le dossier <code>/uploads/raw_icons/</code> est vide ou absent.',
        'footer_text' => 'Réseau Minecraft multi-version avec boutique et base de données synchronisées.',
        'rights' => 'Tous droits réservés.'
    ],
    'it' => [
        'title' => 'ItemNameParser & Gestore Icone',
        'badge' => 'Strumento Amministratore',
        'back' => '← Torna alle utilità',
        'intro_title' => 'Creazione e implementazione di icone personalizzate',
        'intro_text' => 'Usa <a href="https://www.curseforge.com/minecraft/mc-mods/iconexporter" target="_blank" style="color: #38bdf8;">IconExporter</a> e <a href="https://www.curseforge.com/minecraft/mc-mods/cyclops-core" target="_blank" style="color: #38bdf8;">Cyclops Core</a> in Singleplayer per esportare gli oggetti.',
        'guide_title' => 'Guida al deployment:',
        'step_1' => 'Avvia il client con le mod ed entra in un mondo.',
        'step_2' => 'Esegui: <code style="color: #f59e0b;">/iconexporter export</code>',
        'step_3' => 'Carica i file nella cartella: <code style="color: #f59e0b;">/uploads/raw_icons/</code>',
        'step_4' => 'Se usi il vecchio formato, avvia la conversione in: <code style="color: #f59e0b;">/uploads/custom_icons/</code>',
        'step_5' => 'Crea una cartella in <code>/assets</code> con il nome dello <b>SLUG del server</b> (es. <code>ZombieServer_1_21_1</code>) e sposta i file.',
        'format_title' => 'Struttura supportata:',
        'format_text' => 'Percorso: <code>/assets/&lt;slug&gt;/&lt;mod&gt;/&lt;item&gt;.png</code>.',
        'util_title' => 'Convertitore per vecchio formato',
        'util_desc' => 'Se i file sono in formato <code>mod__item.png</code>, clicca qui.',
        'start_btn' => 'Avvia conversione',
        'processing' => 'Elaborazione...',
        'completed' => 'Conversione completata!',
        'success_count' => 'Icone elaborate:',
        'error_dir' => 'Errore: La cartella <code>/uploads/raw_icons/</code> è vuota o assente.',
        'footer_text' => 'Rete Minecraft multi-versione con shop e database sincronizzati.',
        'rights' => 'Tutti i diritti riservati.'
    ]
];

$t = $translations[$currentLang];

// AJAX ENDPOINT PRE ZISKANIE ZOZNAMU SÚBOROV Z /uploads/raw_icons/
if (isset($_GET['action']) &&$_GET['action'] === 'get_files') {
    header('Content-Type: application/json');
    if (!is_dir($sourceDir)) {
        echo json_encode(['error' => true, 'message' => $t['error_dir']]);
        exit;
    }
    $allFiles = scandir($sourceDir);$pngFiles = [];
    foreach ($allFiles as$f) {
        if ($f === '.' ||$f === '..') continue;
        if (strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'png') {
            $pngFiles[] =$f;
        }
    }
    echo json_encode(['error' => false, 'files' => $pngFiles]);
    exit;
}

// AJAX ENDPOINT PRE DÁVKOVÉ SPRACOVANIE A PRESUN DO /uploads/custom_icons/
if (isset($_POST['action']) &&$_POST['action'] === 'process_batch') {
    header('Content-Type: application/json');
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0777, true);
    }

    $batchFiles =$_POST['files'] ?? [];
    $results = [];$batchSuccess = 0;

    foreach ($batchFiles as$file) {
        $filename = pathinfo($file, PATHINFO_FILENAME);
        if (str_contains($filename, '__')) {
            $parts = explode('__',$filename, 2);
            $modName = strtolower(trim($parts[0]));
            $itemName = strtolower(trim($parts[1]));

            $modDir = $outputDir .$modName . '/';
            if (!is_dir($modDir)) {
                mkdir($modDir, 0777, true);
            }

            $sourcePath = $sourceDir .$file;
            $targetPath = $modDir .$itemName . '.png';

            if (file_exists($sourcePath) && copy($sourcePath, $targetPath)) {$results[] = ['file' => $file, 'status' => 'ok', 'mod' =>$modName, 'item' => $itemName];$batchSuccess++;
            } else {
                $results[] = ['file' =>$file, 'status' => 'error'];
            }
        } else {
            $results[] = ['file' =>$file, 'status' => 'skipped'];
        }
    }

    echo json_encode(['success' => true, 'processed' => $results, 'count' =>$batchSuccess]);
    exit;
}
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

        h1 { font-size: 26px; font-weight: 900; margin-bottom: 15px; text-transform: uppercase; letter-spacing: 0.5px; }
        h2 { font-size: 20px; font-weight: 900; margin: 25px 0 10px 0; color: #fff; text-transform: uppercase; }
        h3 { font-size: 15px; font-weight: 800; margin: 20px 0 8px 0; color: #38bdf8; text-transform: uppercase; }
        p, li { font-size: 14px; color: #ccc; line-height: 1.6; margin-bottom: 10px; }
        ul { padding-left: 20px; margin-bottom: 20px; }
        code { background: rgba(0,0,0,0.5); padding: 3px 6px; border-radius: 4px; font-family: monospace; font-size: 13px; color: #f59e0b; }
        
        .box-section { background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.06); border-radius: 14px; padding: 25px; margin-top: 25px; }

        .btn {
            background: linear-gradient(135deg, #38bdf8, #0284c7); color: #000; font-weight: 900; padding: 14px 28px;
            border: none; border-radius: 12px; cursor: pointer; text-transform: uppercase; font-size: 14px; letter-spacing: 1px;
            transition: 0.3s; box-shadow: 0 10px 25px rgba(56, 189, 248, 0.3); display: inline-flex; align-items: center; gap: 10px; margin-top: 15px;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 15px 30px rgba(56, 189, 248, 0.5); background: #fff; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none !important; }
        
        #progress-container { display: none; margin-top: 20px; background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 20px; }
        .progress-bar-bg { width: 100%; height: 10px; background: rgba(255,255,255,0.1); border-radius: 5px; overflow: hidden; margin: 15px 0; }
        .progress-bar-fill { width: 0%; height: 100%; background: #38bdf8; transition: width 0.2s ease; }
        #log-box { max-height: 160px; overflow-y: auto; background: rgba(0,0,0,0.6); border: 1px solid rgba(255,255,255,0.05); border-radius: 8px; padding: 10px; font-family: monospace; font-size: 12px; color: #aaa; margin-top: 15px; }
        .log-ok { color: #4ade80; } .log-err { color: #f87171; } .log-skip { color: #fbbf24; }

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

        <a href="index.php" class="back-link"><?= $t['back'] ?></a>
        <h1><?= $t['title'] ?></h1>
        
        <h2><?= $t['intro_title'] ?></h2>
        <p><?= $t['intro_text'] ?></p>

        <h3><?= $t['guide_title'] ?></h3>
        <ul>
            <li><?= $t['step_1'] ?></li>
            <li><?= $t['step_2'] ?></li>
            <li><?= $t['step_3'] ?></li>
            <li><?= $t['step_4'] ?></li>
            <li><?= $t['step_5'] ?></li>
        </ul>

        <h3><?= $t['format_title'] ?></h3>
        <p><?= $t['format_text'] ?></p>

        <div class="box-section">
            <h3><?= $t['util_title'] ?></h3>
            <p><?= $t['util_desc'] ?></p>

            <button type="button" class="btn" id="startParserBtn"><i class="fa-solid fa-play"></i> <?= $t['start_btn'] ?></button>

            <div id="progress-container">
                <div id="status-text" style="font-weight: 700; font-size: 14px; color: #fff;"><?= $t['processing'] ?> (<span id="progress-percent">0%</span>)</div>
                <div class="progress-bar-bg">
                    <div class="progress-bar-fill" id="progressBarFill"></div>
                </div>
                <div id="log-box"></div>
            </div>
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

    <script>
        const chunkSize = 15;

        document.getElementById('startParserBtn').addEventListener('click', function() {
            const btn = this;
            btn.disabled = true;
            
            const progressContainer = document.getElementById('progress-container');
            const progressBarFill = document.getElementById('progressBarFill');
            const progressPercent = document.getElementById('progress-percent');
            const statusText = document.getElementById('status-text');
            const logBox = document.getElementById('log-box');

            progressContainer.style.display = 'block';
            logBox.innerHTML = '';

            fetch('util_icon_parser.php?action=get_files')
                .then(res => res.json())
                .then(data => {
                    if (data.error) {
                        logBox.innerHTML += `<div class="log-err">[CHYBA] ${data.message}</div>`;
                        btn.disabled = false;
                        return;
                    }

                    const files = data.files;
                    if (files.length === 0) {
                        logBox.innerHTML += `<div class="log-skip">[INFO] Žiadne PNG súbory v /uploads/raw_icons/.</div>`;
                        statusText.textContent = "Žiadne súbory na spracovanie.";
                        progressBarFill.style.width = '100%';
                        progressPercent.textContent = '100%';
                        btn.disabled = false;
                        return;
                    }

                    let totalFiles = files.length;
                    let processedCount = 0;
                    let successTotal = 0;

                    let batches = [];
                    for (let i = 0; i < totalFiles; i += chunkSize) {
                        batches.push(files.slice(i, i + chunkSize));
                    }

                    let batchIndex = 0;

                    function processNextBatch() {
                        if (batchIndex >= batches.length) {
                            statusText.innerHTML = `<span class="log-ok"><?= $t['completed'] ?></span> (Úspešne: ${successTotal}/${totalFiles})`;
                            progressBarFill.style.width = '100%';
                            progressPercent.textContent = '100%';
                            btn.disabled = false;
                            return;
                        }

                        let currentBatch = batches[batchIndex];
                        let formData = new FormData();
                        formData.append('action', 'process_batch');
                        currentBatch.forEach(f => formData.append('files[]', f));

                        fetch('util_icon_parser.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(res => res.json())
                        .then(resData => {
                            if (resData.success) {
                                successTotal += resData.count;
                                resData.processed.forEach(p => {
                                    if (p.status === 'ok') {
                                        logBox.innerHTML += `<div><span class="log-ok">[OK]</span> Mod: <b>${p.mod}</b> -> Item: <b>${p.item}.png</b></div>`;
                                    } else if (p.status === 'skipped') {
                                        logBox.innerHTML += `<div><span class="log-skip">[PRESKOČENÉ]</span> Chýba '__': ${p.file}</div>`;
                                    } else {
                                        logBox.innerHTML += `<div><span class="log-err">[CHYBA]</span> ${p.file}</div>`;
                                    }
                                });
                                logBox.scrollTop = logBox.scrollHeight;
                            }

                            processedCount += currentBatch.length;
                            let percent = Math.min(100, Math.round((processedCount / totalFiles) * 100));
                            progressBarFill.style.width = percent + '%';
                            progressPercent.textContent = percent + '%';
                            statusText.textContent = `Spracovávam (${processedCount}/${totalFiles})...`;

                            batchIndex++;
                            processNextBatch();
                        })
                        .catch(err => {
                            logBox.innerHTML += `<div class="log-err">[CHYBA] Zlyhala komunikácia so serverom.</div>`;
                            btn.disabled = false;
                        });
                    }

                    processNextBatch();
                })
                .catch(err => {
                    logBox.innerHTML += `<div class="log-err">[CHYBA] Nepodarilo sa načítať zoznam súborov.</div>`;
                    btn.disabled = false;
                });
        });
    </script>
</body>
</html>
