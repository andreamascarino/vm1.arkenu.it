<?php
require_once 'config.php';

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (checkAuth($_POST['password'])) {
        $_SESSION['authenticated'] = true;
        header('Location: /');
        exit;
    }
    $error = 'Password errata';
}

function getBackupEnabled() {
    $file = '/etc/pwhost-backup.conf';
    if (!file_exists($file)) return [];
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return array_filter($lines, fn($l) => !str_starts_with(trim($l), '#'));
}

function saveBackupEnabled($sites) {
    $content = "# Siti con backup attivo (uno per riga)\n" . implode("\n", $sites) . "\n";
    file_put_contents('/tmp/pwhost-backup.conf', $content);
    shell_exec('sudo /usr/bin/tee /etc/pwhost-backup.conf < /tmp/pwhost-backup.conf > /dev/null');
    unlink('/tmp/pwhost-backup.conf');
}

function parseBackupDate($filename) {
    if (preg_match('/_(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})\.tar\.gz/', $filename, $m)) {
        return "{$m[3]}/{$m[2]}/{$m[1]} {$m[4]}:{$m[5]}";
    }
    return null;
}

function getLastBackup($domain) {
    $output = shell_exec("rclone lsf 'qnap:/share/FTP/processwire/$domain/current/' 2>/dev/null | sort | tail -1");
    if (!empty(trim($output))) {
        $date = parseBackupDate(trim($output));
        if ($date) return $date;
    }
    $output = shell_exec("rclone lsf 'qnap:/share/FTP/processwire/$domain/snapshots/' 2>/dev/null | sort | tail -1");
    if (!empty(trim($output))) {
        $date = parseBackupDate(trim($output));
        if ($date) return $date;
    }
    return null;
}

function getSnapshots($domain) {
    $all = [];
    $output = shell_exec("rclone lsf 'qnap:/share/FTP/processwire/$domain/current/' 2>/dev/null");
    if (!empty($output)) {
        foreach (array_filter(explode("\n", trim($output))) as $f) {
            if (strpos($f, '.tar.gz') === false) continue;
            $date = parseBackupDate($f);
            $all[] = ['name' => $f, 'date' => $date ?: $f, 'type' => 'current'];
        }
    }
    $output = shell_exec("rclone lsf 'qnap:/share/FTP/processwire/$domain/snapshots/' 2>/dev/null");
    if (!empty($output)) {
        foreach (array_filter(explode("\n", trim($output))) as $f) {
            if (strpos($f, '.tar.gz') === false) continue;
            $date = parseBackupDate($f);
            $all[] = ['name' => $f, 'date' => $date ?: $f, 'type' => 'snapshot'];
        }
    }
    usort($all, fn($a, $b) => strcmp($b['name'], $a['name']));
    return array_slice($all, 0, 30);
}

function getDbCredentials($siteDir) {
    // Prima prova file .db-credentials
    $credsFile = "$siteDir/.db-credentials";
    if (file_exists($credsFile)) {
        $creds = file_get_contents($credsFile);
        $dbName = $dbUser = $dbPass = "";
        if (preg_match("/Database:\s*(\S+)/", $creds, $m)) $dbName = $m[1];
        if (preg_match("/User:\s*(\S+)/", $creds, $m)) $dbUser = $m[1];
        if (preg_match("/Pass:\s*(\S+)/", $creds, $m)) $dbPass = $m[1];
        if ($dbName) return ["name" => $dbName, "user" => $dbUser, "pass" => $dbPass];
    }
    // Fallback: leggi da ProcessWire config
    $dbName = $dbUser = $dbPass = '';
    $configFile = "$siteDir/public/site/config.php";
    if (file_exists($configFile)) {
        $config = file_get_contents($configFile);
        if (preg_match('/\$config->dbName\s*=\s*[\'"]([^\'"]+)[\'"]/', $config, $m)) $dbName = $m[1];
        if (preg_match('/\$config->dbUser\s*=\s*[\'"]([^\'"]+)[\'"]/', $config, $m)) $dbUser = $m[1];
        if (preg_match('/\$config->dbPass\s*=\s*[\'"]([^\'"]+)[\'"]/', $config, $m)) $dbPass = $m[1];
    }
    return ['name' => $dbName, 'user' => $dbUser, 'pass' => $dbPass];
}

function getDbSize($dbName, $dbUser, $dbPass) {
    if (empty($dbName) || empty($dbUser)) return '0M';
    $cmd = "mysql -u" . escapeshellarg($dbUser) . " -p" . escapeshellarg($dbPass) . " -N -e \"SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) FROM information_schema.tables WHERE table_schema = '$dbName'\" 2>/dev/null";
    $size = trim(shell_exec($cmd));
    return ($size && $size !== 'NULL') ? $size . 'M' : '0M';
}

function getAvailablePhpVersions() {
    $versions = [];
    foreach (glob('/etc/php/*/fpm/pool.d/') as $dir) {
        preg_match('/\/php\/([\d.]+)\//', $dir, $m);
        if ($m) $versions[] = $m[1];
    }
    rsort($versions);
    return $versions;
}

function getSitePhpVersion($domain) {
    $poolFiles = glob("/etc/php/*/fpm/pool.d/{$domain}.conf");
    if (!empty($poolFiles)) {
        preg_match('/\/php\/([\d.]+)\//', $poolFiles[0], $m);
        return $m[1] ?? '8.3';
    }
    return '8.3';
}

function getSiteDetails($domain, $siteDir) {
    $sizeFiles = trim(shell_exec("du -sh " . escapeshellarg($siteDir) . " 2>/dev/null | cut -f1"));
    $dbCreds = getDbCredentials($siteDir);
    $sizeDb = getDbSize($dbCreds['name'], $dbCreds['user'], $dbCreds['pass']);
    
    $sftpUser = $sftpPass = '';
    $sftpCredsFile = "$siteDir/.sftp-credentials";
    if (file_exists($sftpCredsFile)) {
        $creds = file_get_contents($sftpCredsFile);
        if (preg_match('/User:\s*(\S+)/', $creds, $m)) $sftpUser = $m[1];
        if (preg_match('/Pass:\s*(\S+)/', $creds, $m)) $sftpPass = $m[1];
    }
    
    $aliasesFile = "$siteDir/.aliases";
    $aliases = file_exists($aliasesFile) ? array_filter(explode("\n", file_get_contents($aliasesFile))) : [];
    
    return [
        'sizeFiles' => $sizeFiles,
        'sizeDb' => $sizeDb,
        'dbName' => $dbCreds['name'],
        'dbUser' => $dbCreds['user'],
        'dbPass' => $dbCreds['pass'],
        'sftpUser' => $sftpUser,
        'sftpPass' => $sftpPass,
        'aliases' => $aliases
    ];
}

function toMb($s) {
    $s = trim($s);
    if (preg_match('/^([\d.]+)G$/i', $s, $m)) return floatval($m[1]) * 1024;
    if (preg_match('/^([\d.]+)M$/i', $s, $m)) return floatval($m[1]);
    if (preg_match('/^([\d.]+)K$/i', $s, $m)) return floatval($m[1]) / 1024;
    return 0;
}

function formatSize($mb) {
    return $mb >= 1024 ? round($mb/1024, 1) . 'G' : round($mb) . 'M';
}

if (isAuthenticated() && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    
    switch ($action) {
        case 'sites':
            $sites = [];
            $backupEnabled = getBackupEnabled();
            $phpVersions = getAvailablePhpVersions();
            
            foreach (glob(SITES_DIR . '/*/') as $dir) {
                $domain = basename($dir);
                if ($domain === 'vm1.arkenu.it') continue;
                
                $hasSSL = file_exists("/etc/letsencrypt/live/$domain/fullchain.pem");
                $phpVersion = getSitePhpVersion($domain);
                $backupOn = in_array($domain, $backupEnabled);
                $details = getSiteDetails($domain, $dir);
                
                $totalMb = toMb($details['sizeFiles']) + toMb($details['sizeDb']);
                $totalSize = formatSize($totalMb);
                
                $sites[] = [
                    'domain' => $domain,
                    'size' => $totalSize,
                    'sizeFiles' => $details['sizeFiles'],
                    'sizeDb' => $details['sizeDb'],
                    'ssl' => $hasSSL,
                    'lastBackup' => $backupOn ? (getLastBackup($domain) ?: 'Mai') : 'Disattivo',
                    'phpVersion' => $phpVersion,
                    'phpVersions' => $phpVersions,
                    'dbName' => $details['dbName'],
                    'dbUser' => $details['dbUser'],
                    'dbPass' => $details['dbPass'],
                    'sftpUser' => $details['sftpUser'],
                    'sftpPass' => $details['sftpPass'],
                    'docRoot' => "/var/www/sites/$domain/public",
                    'backupEnabled' => $backupOn,
                    'aliases' => $details['aliases']
                ];
            }
            // Ordinamento: prima per dominio base (alfabetico), poi principale prima dei sottodomini
            usort($sites, function($a, $b) {
                $getBase = function($domain) {
                    $parts = explode('.', $domain);
                    return count($parts) >= 2 ? $parts[count($parts) - 2] : $domain;
                };
                $baseA = $getBase($a['domain']);
                $baseB = $getBase($b['domain']);
                if ($baseA !== $baseB) return strcmp($baseA, $baseB);
                $partsA = count(explode('.', $a['domain']));
                $partsB = count(explode('.', $b['domain']));
                if ($partsA !== $partsB) return $partsA - $partsB;
                return strcmp($a['domain'], $b['domain']);
            });
            echo json_encode($sites);
            exit;
            
        case 'php-versions':
            echo json_encode(getAvailablePhpVersions());
            exit;
            
        case 'change-php':
            $domain = $_GET['domain'] ?? '';
            $version = $_GET['version'] ?? '';
            $validVersions = getAvailablePhpVersions();
            
            if ($domain && preg_match('/^[a-z0-9.-]+$/i', $domain) && in_array($version, $validVersions)) {
                $output = shell_exec("sudo /usr/local/bin/pw-php " . escapeshellarg($domain) . " " . escapeshellarg($version) . " 2>&1");
                echo json_encode(['success' => true, 'output' => $output]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Parametri non validi']);
            }
            exit;
            
        case 'backup-size':
            $domain = $_GET['domain'] ?? '';
            if ($domain && preg_match('/^[a-z0-9.-]+$/i', $domain)) {
                $backupSize = shell_exec("rclone size 'qnap:/share/FTP/processwire/$domain/' --json 2>/dev/null");
                $size = '0M';
                if ($backupSize) {
                    $json = json_decode($backupSize, true);
                    if (isset($json['bytes'])) {
                        $mb = $json['bytes'] / 1024 / 1024;
                        $size = $mb >= 1024 ? round($mb/1024, 1) . 'G' : round($mb) . 'M';
                    }
                }
                echo json_encode(['size' => $size]);
            } else {
                echo json_encode(['size' => '0M']);
            }
            exit;
            
        case 'services':
            echo json_encode([
                'nginx' => trim(shell_exec("systemctl is-active nginx 2>/dev/null")) === 'active',
                'php83' => trim(shell_exec("systemctl is-active php8.3-fpm 2>/dev/null")) === 'active',
                'php73' => trim(shell_exec("systemctl is-active php7.3-fpm 2>/dev/null")) === 'active',
                'mariadb' => trim(shell_exec("systemctl is-active mariadb 2>/dev/null")) === 'active',
                'redis' => trim(shell_exec("systemctl is-active redis-server 2>/dev/null")) === 'active',
            ]);
            exit;
            
        case 'disk':
            // Spazio server locale
            $server = [
                'total' => disk_total_space('/'),
                'free' => disk_free_space('/'),
                'used' => disk_total_space('/') - disk_free_space('/')
            ];
            // Spazio QNAP backup
            $qnapOutput = shell_exec("rclone size 'qnap:/share/FTP/processwire/' --json 2>/dev/null");
            $qnapData = json_decode($qnapOutput, true);
            $qnap = [
                'used' => $qnapData['bytes'] ?? 0,
                'limit' => 500 * 1000000000 // 500GB
            ];
            echo json_encode(['server' => $server, 'qnap' => $qnap]);
            exit;

        case "system-status":
            $statusFile = __DIR__ . "/system-status.json";
            if (file_exists($statusFile)) {
                echo file_get_contents($statusFile);
            } else {
                echo json_encode(["error" => "Non disponibile"]);
            }
            exit;
            exit;

        case "stats":
            $statsFile = __DIR__ . "/stats.json";
            if (file_exists($statsFile)) {
                echo file_get_contents($statsFile);
            } else {
                echo json_encode(["disk" => [], "load" => []]);
            }
            exit;

        case 'system':
            $uptime = trim(shell_exec("uptime -p 2>/dev/null")) ?: 'N/D';
            $load = sys_getloadavg();
            echo json_encode(['hostname' => gethostname(), 'uptime' => $uptime, 'load' => round($load[0], 2)]);
            exit;
            
        case 'backup':
            $domain = $_GET['domain'] ?? '';
            if ($domain && preg_match('/^[a-z0-9.-]+$/i', $domain)) {
                $output = shell_exec("sudo /usr/local/bin/pw-backup " . escapeshellarg($domain) . " 2>&1");
                echo json_encode(['success' => true, 'output' => $output]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Dominio non valido']);
            }
            exit;
            
        case 'backup-toggle':
            $domain = $_GET['domain'] ?? '';
            $enable = ($_GET['enable'] ?? '') === '1';
            if ($domain && preg_match('/^[a-z0-9.-]+$/i', $domain)) {
                $current = getBackupEnabled();
                if ($enable && !in_array($domain, $current)) {
                    $current[] = $domain;
                } elseif (!$enable) {
                    $current = array_filter($current, fn($d) => $d !== $domain);
                }
                saveBackupEnabled(array_values($current));
                echo json_encode(['success' => true, 'enabled' => $enable]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Dominio non valido']);
            }
            exit;


        case 'restore':
            $domain = $_GET['domain'] ?? '';
            $backupFile = $_GET['file'] ?? '';
            $restoreType = $_GET['type'] ?? 'all';
            if ($domain && preg_match('/^[a-z0-9.-]+$/i', $domain) && $backupFile && preg_match('/^[a-z0-9._-]+.tar.gz$/i', $backupFile) && in_array($restoreType, ['db', 'files', 'all'])) {
                $output = shell_exec("sudo /usr/local/bin/pw-restore " . escapeshellarg($domain) . " " . escapeshellarg($backupFile) . " " . escapeshellarg($restoreType) . " 2>&1");
                echo json_encode(['success' => true, 'output' => $output]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Parametri non validi']);
            }
            exit;

        case "restore-dr":
            $backupFile = $_GET["file"] ?? "";
            $restoreType = $_GET["type"] ?? "all";
            if ($backupFile && preg_match("/^[a-z0-9._-]+\.tar\.gz\.iso$/i", $backupFile) && in_array($restoreType, ["all", "sites", "system"])) {
                file_put_contents("/var/log/pwhost-restore-dr.log", "");
                shell_exec("sudo /usr/local/bin/pwhost/restore-disaster-recovery.sh " . escapeshellarg($backupFile) . " " . escapeshellarg($restoreType) . " --no-confirm > /var/log/pwhost-restore-dr.log 2>\&1 \&");
                echo json_encode(["success" => true, "message" => "Restore avviato in background"]);
            } else {
                echo json_encode(["success" => false, "error" => "Parametri non validi"]);
            }
            exit;

        case "restore-dr-status":
            $log = @file_get_contents("/var/log/pwhost-restore-dr.log") ?: "";
            $completed = strpos($log, "RESTORE DISASTER RECOVERY COMPLETATO") !== false;
            $error = strpos($log, "Errore:") !== false || strpos($log, "Errore download") !== false;
            $lines = explode("\n", $log);
            $summary = [];
            foreach ($lines as $line) {
                if (preg_match("/^\d{4}-\d{2}-\d{2}|Download completato|Archivio verificato|Estrazione|Restore|Skip|Sistema|Siti|COMPLETATO/", $line)) {
                    $summary[] = trim($line);
                }
            }
            echo json_encode(["completed" => $completed, "error" => $error, "summary" => implode("\n", $summary)]);
            exit;

        case "dr-backups":
            $output = shell_exec("rclone lsf aruba-dr:/pwhost-backup/ 2>/dev/null");
            $files = array_filter(explode("\n", trim($output)));
            $backups = [];
            foreach ($files as $f) {
                if (preg_match("/pwhost-full-backup_(\d{8})\.tar\.gz\.iso/", $f, $m)) {
                    $backups[] = ["name" => $f, "date" => substr($m[1],0,4)."-".substr($m[1],4,2)."-".substr($m[1],6,2)];
                }
            }
            usort($backups, fn($a,$b) => strcmp($b["name"], $a["name"]));
            echo json_encode($backups);
            exit;
        case 'backups':
            $domain = $_GET['domain'] ?? '';
            if ($domain && preg_match('/^[a-z0-9.-]+$/i', $domain)) {
                echo json_encode(getSnapshots($domain));
            } else {
                echo '[]';
            }
            exit;

        case 'create':
            $domain = $_POST['domain'] ?? '';
            $sftp = isset($_POST['sftp']) && $_POST['sftp'] ? 'sftp' : '';
            $phpVersion = $_POST['php_version'] ?? '8.3';
            $importDb = isset($_POST['import_db']) && $_POST['import_db'];
            $importFiles = isset($_POST['import_files']) && $_POST['import_files'];
            
            if (!$domain || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
                echo json_encode(['success' => false, 'error' => 'Dominio non valido']);
                exit;
            }
            
            // Salva file temporanei
            $dumpFile = '';
            $siteZip = '';
            
            if ($importDb && isset($_FILES['sql_dump']) && $_FILES['sql_dump']['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['sql_dump']['name'], PATHINFO_EXTENSION);
                $dumpFile = '/tmp/' . uniqid('dump_') . '.' . $ext;
                move_uploaded_file($_FILES['sql_dump']['tmp_name'], $dumpFile);
                chmod($dumpFile, 0644);
            }
            
            if ($importFiles && isset($_FILES['site_zip']) && $_FILES['site_zip']['error'] === UPLOAD_ERR_OK) {
                $siteZip = '/tmp/' . uniqid('site_') . '.zip';
                move_uploaded_file($_FILES['site_zip']['tmp_name'], $siteZip);
                chmod($siteZip, 0644);
            }
            
            // Crea sito
            $cmd = "sudo /usr/local/bin/pw-create " . escapeshellarg($domain) . " " . escapeshellarg($phpVersion);
            if ($sftp) $cmd .= " sftp";
            if ($dumpFile) $cmd .= " " . escapeshellarg($dumpFile);
            
            $output = shell_exec($cmd . " 2>&1");
            
            // Estrai ZIP contenuti se fornito
            $extractOutput = '';
            if ($siteZip && file_exists($siteZip)) {
                $siteDir = "/var/www/sites/$domain/public";
                $extractOutput = shell_exec("cd " . escapeshellarg($siteDir) . " && unzip -o " . escapeshellarg($siteZip) . " 2>&1");
                
                // Fix permessi
                shell_exec("chown -R www-data:www-data " . escapeshellarg($siteDir));
                
                // Aggiorna config.php con nuove credenziali DB
                $configFile = "$siteDir/site/config.php";
                if (file_exists($configFile)) {
                    $dbCredsFile = "/var/www/sites/$domain/.db-credentials";
                    if (file_exists($dbCredsFile)) {
                        $creds = file_get_contents($dbCredsFile);
                        preg_match('/Database:\s*(\S+)/', $creds, $mDb);
                        preg_match('/User:\s*(\S+)/', $creds, $mUser);
                        preg_match('/Pass:\s*(\S+)/', $creds, $mPass);
                        
                        if ($mDb && $mUser && $mPass) {
                            $cmd = "sudo /usr/local/bin/pw-update-config " . 
                                escapeshellarg($configFile) . " " .
                                escapeshellarg($mDb[1]) . " " .
                                escapeshellarg($mUser[1]) . " " .
                                escapeshellarg($mPass[1]) . " " .
                                escapeshellarg($domain);
                            shell_exec($cmd);
                            $extractOutput .= "\nConfig.php aggiornato con nuove credenziali DB";
                        }
                    }
                }
                
                unlink($siteZip);
            }
            
            // Pulizia
            if ($dumpFile && file_exists($dumpFile)) {
                unlink($dumpFile);
            }
            
            $fullOutput = $output;
            if ($extractOutput) {
                $fullOutput .= "\n--- Estrazione ZIP ---\n" . $extractOutput;
            }
            
            echo json_encode(['success' => true, 'output' => $fullOutput]);
            
            // Chiudi connessione HTTP prima del reload
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            
            // Reload asincrono dei servizi
            shell_exec('nohup bash -c "sleep 2; systemctl reload nginx; systemctl reload php8.3-fpm 2>/dev/null; systemctl reload php7.3-fpm 2>/dev/null" >/dev/null 2>&1 &');
            exit;
            
        case 'ssl':
            $domain = $_GET['domain'] ?? '';
            if ($domain && preg_match('/^[a-z0-9.-]+$/i', $domain)) {
                $output = shell_exec("sudo /usr/local/bin/pw-ssl " . escapeshellarg($domain) . " 2>&1");
                echo json_encode(['success' => true, 'output' => $output]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Dominio non valido']);
            }
            exit;

        case "aliases":
            $domain = $_GET["domain"] ?? "";
            if ($domain && preg_match("/^[a-z0-9.-]+$/i", $domain)) {
                $output = shell_exec("sudo /usr/local/bin/pw-alias list " . escapeshellarg($domain) . " 2>/dev/null");
                $aliases = array_filter(explode("\n", trim($output)));
                echo json_encode($aliases);
            } else {
                echo json_encode([]);
            }
            exit;

        case "alias-add":
            $domain = $_GET["domain"] ?? "";
            $alias = $_GET["alias"] ?? "";
            if ($domain && $alias && preg_match("/^[a-z0-9.-]+$/i", $domain) && preg_match("/^[a-z0-9.-]+$/i", $alias)) {
                $output = shell_exec("sudo /usr/local/bin/pw-alias add " . escapeshellarg($domain) . " " . escapeshellarg($alias) . " 2>\&1");
                echo json_encode(["success" => true, "output" => $output]);
            } else {
                echo json_encode(["success" => false, "error" => "Parametri non validi"]);
            }
            exit;

        case "alias-remove":
            $domain = $_GET["domain"] ?? "";
            $alias = $_GET["alias"] ?? "";
            if ($domain && $alias && preg_match("/^[a-z0-9.-]+$/i", $domain) && preg_match("/^[a-z0-9.-]+$/i", $alias)) {
                $output = shell_exec("sudo /usr/local/bin/pw-alias remove " . escapeshellarg($domain) . " " . escapeshellarg($alias) . " 2>\&1");
                echo json_encode(["success" => true, "output" => $output]);
            } else {
                echo json_encode(["success" => false, "error" => "Parametri non validi"]);
            }
            exit;

        case 'delete':
            $domain = $_GET['domain'] ?? '';
            if ($domain && preg_match('/^[a-z0-9.-]+$/i', $domain) && $domain !== 'vm1.arkenu.it') {
                $output = shell_exec("echo 'ELIMINA' | sudo /usr/local/bin/pw-delete " . escapeshellarg($domain) . " 2>&1");
                echo json_encode(['success' => true, 'output' => $output]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Dominio non valido']);
            }
            exit;

        case 'logs':
            $logType = $_GET['type'] ?? 'backup';
            $lines = (int)($_GET['lines'] ?? 100);
            $lines = min(max($lines, 50), 500);
            
            $logFiles = [
                'backup' => '/var/log/pwhost-backup.log',
                'backup-dr' => '/var/log/pwhost-backup-dr.log',
                'updates' => '/var/log/pwhost-updates.log',
                'db-maintenance' => '/var/log/pwhost-db-maintenance.log'
            ];
            
            if (!isset($logFiles[$logType])) {
                echo json_encode(['success' => false, 'error' => 'Tipo log non valido']);
                exit;
            }
            
            $logFile = $logFiles[$logType];
            $content = '';
            
            if (file_exists($logFile) && is_readable($logFile)) {
                $content = shell_exec("tail -n $lines " . escapeshellarg($logFile) . " 2>/dev/null");
            } else {
                $content = "File di log non trovato o non leggibile: $logFile";
            }
            
            echo json_encode([
                'success' => true,
                'type' => $logType,
                'file' => $logFile,
                'content' => $content ?: '(log vuoto)'
            ]);
            exit;
    }
}

if (!isAuthenticated()):
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - PWHost</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-body">
    <div class="login-box">
        <h1>🖥️ PWHost</h1>
        <p class="subtitle">ProcessWire Hosting Manager</p>
        <?php if (isset($error)): ?><div class="error"><?= $error ?></div><?php endif; ?>
        <form method="POST">
            <input type="password" name="password" placeholder="Password" required autofocus>
            <button type="submit">Accedi</button>
        </form>
        <p class="note">Prima volta? La password inserita diventerà la password di accesso.</p>
    </div>
</body>
</html>
<?php exit; endif; ?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PWHost Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="header">
        <div class="header-left"><div class="logo">P</div><h1>vm1.arkenu.it</h1></div>
        <div class="header-right"><a href="?logout">Esci</a></div>
    </div>
    <div class="container">
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-header"><span class="stat-title">Server</span><div class="stat-icon purple">🖥️</div></div><div class="stat-value" id="sites-count">-</div><div class="stat-desc">siti attivi</div><div class="server-details"><div class="server-row"><span class="label">IP</span><span class="value">209.227.239.208</span></div><div class="server-row"><span class="label">Uptime</span><span class="value" id="uptime">...</span></div></div></div>
            <div class="stat-card"><div class="stat-header"><span class="stat-title">Servizi</span><div class="stat-icon green">⚡</div></div><div id="services-badges" class="services-row">...</div></div>
            <div class="stat-card"><div class="stat-header"><span class="stat-title">Spazio Disco</span><div class="stat-icon blue">💾</div></div><div id="disk-info">...</div></div>
            <div class="stat-card" style="position:relative;overflow:hidden"><div id="load-chart" style="position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:0"></div><div class="stat-header"><span class="stat-title">Load Average</span><span style="font-size:0.6rem;color:var(--text-muted);margin-left:5px">24h</span><div class="stat-icon orange">📊</div></div><div class="stat-value" id="load-avg">-</div><div class="stat-desc">Sistema</div></div>
        </div>
        <div class="main-card">
            <div class="main-header"><h2>🗂️ Siti Web</h2><button class="btn btn-primary" onclick="openModal('create')"><span>+</span> Nuovo Sito</button></div>
            <div class="sites-list" id="sites-list"><div class="empty-state">Caricamento...</div></div>
        </div>
        <div class="main-card" style="margin-top:20px;">
            <div class="main-header"><h2>🔧 Stato Sistema</h2><button class="btn btn-secondary" onclick="loadSystemStatus()">🔄 Aggiorna</button></div>
            <div id="system-status-content" style="padding:15px;">
                <div style="color:var(--text-muted)">Clicca Aggiorna per verificare...</div>
            </div>
        </div>
        <div class="main-card" style="margin-top:20px;">
            <div class="main-header"><h2>🛡️ Disaster Recovery</h2><button class="btn btn-secondary" onclick="loadDrBackups()">🔄 Aggiorna</button></div>
            <div class="dr-info" style="padding:15px;color:var(--text-muted);font-size:0.85rem;">
                <p>Backup completi su Aruba FTP (datacenter IT1). Usa per ripristinare l'intero server in caso di disastro.</p>
            </div>
            <div id="dr-backups-list" style="padding:0 15px 15px;"><div style="color:var(--text-muted)">Clicca Aggiorna per vedere i backup...</div></div>
        </div>
        <div class="main-card" style="margin-top:20px;">
            <div class="main-header"><h2>📋 Log Sistema</h2>
                <div style="display:flex;gap:10px;align-items:center;">
                    <select id="log-type-select" class="form-select" style="width:auto;padding:6px 12px;">
                        <option value="backup">Backup QNAP</option>
                        <option value="backup-dr">Backup DR</option>
                        <option value="updates">Aggiornamenti</option>
                        <option value="db-maintenance">DB Maintenance</option>
                    </select>
                    <select id="log-lines-select" class="form-select" style="width:auto;padding:6px 12px;">
                        <option value="50">50 righe</option>
                        <option value="100" selected>100 righe</option>
                        <option value="200">200 righe</option>
                        <option value="500">500 righe</option>
                    </select>
                    <button class="btn btn-secondary" onclick="loadLogs()">🔄 Carica</button>
                </div>
            </div>
            <div id="logs-content" style="padding:15px;">
                <div style="color:var(--text-muted)">Seleziona un tipo di log e clicca Carica...</div>
            </div>
        </div>
        <div class="main-card" style="margin-top:20px;">
            <div class="main-header"><h2>🔧 Comandi Utili SSH</h2>
                <button class="btn btn-secondary" onclick="toggleCommands()">📖 Mostra/Nascondi</button>
            </div>
            <div id="commands-content" style="padding:15px;display:none;">
                <div class="commands-grid">
                    <div class="cmd-section">
                        <h4>🔄 Gestione Servizi</h4>
                        <div class="cmd-item"><code>systemctl restart nginx</code><span>Riavvia Nginx</span></div>
                        <div class="cmd-item"><code>systemctl restart php8.3-fpm</code><span>Riavvia PHP 8.3</span></div>
                        <div class="cmd-item"><code>systemctl restart php7.4-fpm</code><span>Riavvia PHP 7.4</span></div>
                        <div class="cmd-item"><code>systemctl restart php7.3-fpm</code><span>Riavvia PHP 7.3</span></div>
                        <div class="cmd-item"><code>systemctl restart mariadb</code><span>Riavvia MariaDB</span></div>
                        <div class="cmd-item"><code>systemctl restart redis-server</code><span>Riavvia Redis</span></div>
                        <div class="cmd-item"><code>nginx -t && systemctl reload nginx</code><span>Test e reload Nginx</span></div>
                    </div>
                    <div class="cmd-section">
                        <h4>💾 Backup</h4>
                        <div class="cmd-item"><code>pw-backup DOMINIO</code><span>Backup manuale sito</span></div>
                        <div class="cmd-item"><code>/usr/local/bin/pwhost/backup-current.sh</code><span>Backup QNAP current</span></div>
                        <div class="cmd-item"><code>/usr/local/bin/pwhost/backup-snapshot.sh</code><span>Backup QNAP snapshot</span></div>
                        <div class="cmd-item"><code>/usr/local/bin/pwhost/backup-disaster-recovery.sh</code><span>Backup DR Aruba</span></div>
                        <div class="cmd-item"><code>rclone lsf qnap:/share/FTP/processwire/</code><span>Lista backup QNAP</span></div>
                        <div class="cmd-item"><code>rclone lsf aruba-dr:/pwhost-backup/</code><span>Lista backup Aruba</span></div>
                    </div>
                    <div class="cmd-section">
                        <h4>🔧 Gestione Siti</h4>
                        <div class="cmd-item"><code>pw-create DOMINIO</code><span>Crea nuovo sito</span></div>
                        <div class="cmd-item"><code>pw-delete DOMINIO</code><span>Elimina sito</span></div>
                        <div class="cmd-item"><code>pw-ssl DOMINIO</code><span>Attiva SSL</span></div>
                        <div class="cmd-item"><code>pw-php DOMINIO VERSIONE</code><span>Cambia PHP (es: 8.3)</span></div>
                        <div class="cmd-item"><code>pw-alias list DOMINIO</code><span>Lista alias</span></div>
                        <div class="cmd-item"><code>pw-alias add DOMINIO ALIAS</code><span>Aggiungi alias</span></div>
                        <div class="cmd-item"><code>pw-restore DOMINIO SNAPSHOT</code><span>Restore da backup</span></div>
                    </div>
                    <div class="cmd-section">
                        <h4>📊 Monitoraggio</h4>
                        <div class="cmd-item"><code>htop</code><span>Monitor risorse live</span></div>
                        <div class="cmd-item"><code>df -h</code><span>Spazio disco</span></div>
                        <div class="cmd-item"><code>free -h</code><span>Memoria RAM</span></div>
                        <div class="cmd-item"><code>tail -f /var/log/pwhost-backup.log</code><span>Log backup live</span></div>
                        <div class="cmd-item"><code>tail -100 /var/www/sites/DOMINIO/logs/error.log</code><span>Errori sito</span></div>
                        <div class="cmd-item"><code>/usr/local/bin/pwhost/check-updates.sh</code><span>Check aggiornamenti</span></div>
                    </div>
                    <div class="cmd-section">
                        <h4>🗄️ Database</h4>
                        <div class="cmd-item"><code>mysql -u root</code><span>Console MySQL</span></div>
                        <div class="cmd-item"><code>mysqldump -u root DBNAME > dump.sql</code><span>Export database</span></div>
                        <div class="cmd-item"><code>mysql -u root DBNAME < dump.sql</code><span>Import database</span></div>
                        <div class="cmd-item"><code>mysql -u root -e "SHOW DATABASES"</code><span>Lista database</span></div>
                    </div>
                    <div class="cmd-section">
                        <h4>🛠️ Sistema</h4>
                        <div class="cmd-item"><code>apt update && apt upgrade -y</code><span>Aggiorna sistema</span></div>
                        <div class="cmd-item"><code>reboot</code><span>Riavvia server</span></div>
                        <div class="cmd-item"><code>uptime</code><span>Uptime server</span></div>
                        <div class="cmd-item"><code>cat /etc/pwhost-backup.conf</code><span>Siti con backup attivo</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
        
    <div class="modal" id="modal-create">
        <div class="modal-content modal-large">
            <div class="modal-header"><h2>Nuovo Sito</h2><button class="modal-close" onclick="closeModal('create')">✕</button></div>
            <form id="form-create" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Dominio</label>
                    <input type="text" name="domain" placeholder="esempio.it" required>
                </div>
                <div class="form-group">
                    <label>Versione PHP</label>
                    <select name="php_version" id="php-version-select" class="form-select"></select>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="sftp" value="1">
                        <span>Crea utente SFTP dedicato</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="import_files" id="import-files-check" value="1" onchange="toggleFilesUpload()">
                        <span>Importa file sito da ZIP</span>
                    </label>
                </div>
                <div class="form-group" id="files-upload-group" style="display:none;">
                    <label>ZIP contenuti sito (verrà estratto in /public)</label>
                    <input type="file" name="site_zip" accept=".zip">
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="import_db" id="import-db-check" value="1" onchange="toggleDumpUpload()">
                        <span>Importa database da dump SQL</span>
                    </label>
                </div>
                <div class="form-group" id="dump-upload-group" style="display:none;">
                    <label>File SQL (.sql, .zip, .gz)</label>
                    <input type="file" name="sql_dump" accept=".sql,.zip,.gz">
                </div>
                <div id="create-progress" class="progress-container" style="display:none;">
                    <div class="progress-bar"><div class="progress-fill" id="progress-fill"></div></div>
                    <div class="progress-status" id="progress-status">Preparazione...</div>
                </div>
                <div id="create-output" class="output" style="display:none;"></div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('create')">Annulla</button>
                    <button type="submit" class="btn btn-primary" id="btn-create">Crea Sito</button>
                </div>
            </form>
        </div>
    </div>
    <div class="modal" id="modal-output"><div class="modal-content"><div class="modal-header"><h2 id="output-title">Output</h2><button class="modal-close" onclick="closeModal('output')">✕</button></div><div id="output-content" class="output"></div><div class="modal-actions"><button class="btn btn-secondary" onclick="closeModal('output')">Chiudi</button></div></div></div>
    <div id="restore-progress" style="display:none;position:fixed;bottom:80px;left:50%;transform:translateX(-50%);width:400px;z-index:1001;background:linear-gradient(135deg,#f59e0b,#d97706);padding:20px;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,0.4);">
        <div style="color:#fff;font-weight:600;font-size:0.95rem;margin-bottom:12px;" id="restore-status">Restore in corso...</div>
        <div style="background:rgba(0,0,0,0.2);border-radius:6px;height:8px;overflow:hidden;"><div class="progress-fill indeterminate" id="restore-fill" style="height:100%;background:#fff;border-radius:6px;"></div></div>
        <pre id="restore-output" style="display:none;max-height:150px;overflow-y:auto;font-size:0.7rem;margin-top:12px;background:rgba(0,0,0,0.3);color:#fff;padding:10px;border-radius:6px;white-space:pre-wrap;font-family:monospace;"></pre>
    </div>
    <div class="toast" id="toast"></div>
    <script src="assets/js/app.js"></script>
</body>
</html>
