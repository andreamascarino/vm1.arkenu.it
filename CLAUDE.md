# PWHost Server - Documentazione Claude

---

## ⚠️ ATTENZIONE: SERVER DI PRODUZIONE ⚠️

**Questo è il server di PRODUZIONE con siti live.**

- **NON** fare modifiche senza test preventivi
- **NON** sperimentare codice non testato
- Per sviluppo e test usare il **server di staging**
- Ogni modifica può impattare i siti dei clienti

---

## Server Info

- **Hostname**: PROCESSWIRE
- **IP Pubblico**: 209.227.239.208
- **OS**: Ubuntu 24.04.3 LTS (Noble Numbat)
- **CPU**: AMD EPYC-Milan, 4 core
- **RAM**: 8 GB
- **Disco**: 118 GB (root), ~10% utilizzato

---

## Servizi Attivi

| Servizio | Versione | Note |
|----------|----------|------|
| Nginx | 1.24.0 | Reverse proxy + web server |
| PHP-FPM | 7.3, 7.4, 8.1, 8.3 | Multi-versione, pool per sito |
| MariaDB | 10.11.13 | Database MySQL-compatible |
| Redis | - | Session storage + cache |
| Fail2ban | - | Protezione SSH |
| atd | - | Job scheduler asincrono |
| SSH | OpenSSH | Porta 22 |

---

## Struttura Directory

```
/var/www/sites/                    # Root siti web
├── {dominio}/
│   ├── public/                    # Document root (Nginx)
│   ├── logs/                      # access.log, error.log, php-error.log
│   ├── backups/                   # Backup locali
│   ├── .db-credentials            # Host, Database, User, Pass
│   └── .sftp-credentials          # User, Pass, Host, Port (se SFTP attivo)

/usr/local/bin/                    # Script CLI
├── pw-create                      # Crea nuovo sito
├── pw-create-async                # Wrapper asincrono per creazione
├── pw-delete                      # Elimina sito
├── pw-backup                      # Backup manuale
├── pw-restore                     # Restore da backup
├── pw-ssl                         # Configura Let's Encrypt
├── pw-php                         # Cambia versione PHP
├── pw-alias                       # Gestisce alias dominio
├── pw-import-backup               # Import backup da QNAP
├── pw-import-db                   # Import database
├── pw-import-files                # Import file
├── pw-update-config               # Aggiorna config.php ProcessWire
└── pw-list                        # Lista siti

/usr/local/bin/pwhost/             # Script helper interni
├── backup-current.sh              # Backup QNAP (4x/giorno)
├── backup-snapshot.sh             # Backup QNAP snapshot (2x/giorno)
├── backup-disaster-recovery.sh    # Backup Aruba DR (1x/notte)
├── collect-stats.sh               # Statistiche (ogni 5 min)
├── check-updates.sh               # Check aggiornamenti
├── db-maintenance.sh              # Manutenzione DB (domenica)
└── ...

/usr/local/lib/pwhost/scripts/     # Script wrapper temporanei (creazione asincrona)

/var/run/pwhost/                   # File di stato job asincroni
├── job_*.json                     # Status: running/completed/error

/etc/nginx/sites-available/        # Configurazioni Nginx
/etc/nginx/sites-enabled/          # Symlink attivi
/etc/nginx/snippets/
├── security-headers.conf          # Header sicurezza
└── fastcgi-cache.conf             # Cache FastCGI

/etc/php/{version}/fpm/pool.d/     # Pool PHP-FPM per sito
```

---

## Dashboard PWHost

**URL**: https://vm1.arkenu.it/

**File principali**:
- `/var/www/sites/vm1.arkenu.it/public/index.php` - Backend PHP
- `/var/www/sites/vm1.arkenu.it/public/assets/js/app.js` - Frontend JS
- `/var/www/sites/vm1.arkenu.it/public/assets/css/style.css` - Stili

**Endpoint API** (GET/POST `?action=`):
- `sites` - Lista siti con stats (senza dati QNAP per velocità)
- `all-backups` - Lista ultimo backup per ogni sito (lazy loading)
- `create` - Crea sito (async)
- `create-status` - Polling stato creazione
- `delete` - Elimina sito
- `backup` - Backup manuale
- `backups` - Lista backup disponibili per un sito
- `restore` - Restore da snapshot
- `ssl` - Configura SSL
- `change-php` - Cambia versione PHP
- `aliases` - Lista alias dominio
- `alias-add` / `alias-remove` - Gestisce alias
- `list-qnap-backups` - Lista backup QNAP per import
- `disk` - Spazio disco server (veloce)
- `disk-qnap` - Spazio disco QNAP (lazy loading)
- `stats` - Statistiche server
- `services` - Stato servizi
- `system` - Info sistema (uptime, load)
- `dr-backups` - Lista backup Disaster Recovery

---

## Sistema Backup

### QNAP (Storage primario)
- **Host**: 194.48.249.245
- **Protocollo**: SFTP (rclone remote "qnap")
- **Path**: `/share/FTP/processwire/{sito}/`
  - `current/` - Ultimi 4 backup (4x/giorno)
  - `snapshot/` - Ultimi 20 backup (10 giorni)
- **Formato**: `{sito}_YYYYMMDD_HHMMSS.tar.gz`
- **Contenuto**: database.sql + tutti i file del sito

### Aruba DR (Disaster Recovery)
- **Host**: ftp2.dc1.computing.cloud.it
- **Protocollo**: FTPS (rclone remote "aruba-dr", explicit_tls=true)
- **Path**: `/pwhost-backup/`
- **Schedule**: Ogni notte alle 02:00
- **Retention**: 2 giorni
- **Formato**: `pwhost-full-backup_YYYYMMDD.tar.gz.iso` (estensione .iso richiesta da Aruba)
- **Contenuto**: Backup COMPLETO del server (tutti i siti, tutti i DB, config sistema, certificati SSL)

### Siti con backup QNAP attivo
Configurati in `/etc/pwhost-backup.conf` (solo per backup frequenti QNAP):
- claviere.it
- cato2.it

**Nota**: Il backup DR include TUTTI i siti, indipendentemente da questa configurazione.

---

## Cron Jobs

```
# Backup QNAP - CURRENT (4x/giorno)
0 10,14,18,21 * * * backup-current.sh

# Backup QNAP - SNAPSHOT (2x/giorno)
0 3,15 * * * backup-snapshot.sh

# Backup DISASTER RECOVERY Aruba (1x/notte)
0 2 * * * backup-disaster-recovery.sh

# Stats collection (ogni 5 min)
*/5 * * * * collect-stats.sh

# Check updates (ogni notte)
30 3 * * * check-updates.sh

# DB maintenance (domenica)
0 4 * * 0 db-maintenance.sh

# Pulizia /tmp (ogni notte)
0 4 * * * find /tmp -maxdepth 1 -type f \( -name "*.zip" -o -name "*.sql" ... \) -mtime +1 -delete
```

---

## Creazione Sito (Workflow)

### Semplice (senza file)
1. PHP riceve richiesta `?action=create`
2. Genera jobId, crea status file in `/var/run/pwhost/`
3. Schedula `pw-create-async` via `at now`
4. `pw-create-async` chiama `pw-create`
5. `pw-create`: crea dir, DB, config Nginx, pool PHP-FPM, restart servizi
6. Aggiorna status file a "completed"

### Con ZIP/SQL upload
1. File uploadati in `/tmp/`
2. Crea script wrapper in `/usr/local/lib/pwhost/scripts/`
3. Schedula wrapper via `at now`
4. Wrapper: chiama pw-create, estrae ZIP, importa SQL, aggiorna config.php

### Con import QNAP
1. Schedula `pw-create-async` con path backup
2. `pw-create-async` chiama `pw-import-backup`
3. `pw-import-backup`: scarica da QNAP, estrae, importa DB, aggiorna config.php

---

## Permessi Sudoers

`/etc/sudoers.d/pwhost`:
```
www-data ALL=(root) NOPASSWD: /usr/local/bin/pw-create
www-data ALL=(root) NOPASSWD: /usr/local/bin/pw-backup
www-data ALL=(root) NOPASSWD: /usr/local/bin/pw-ssl
www-data ALL=(root) NOPASSWD: /usr/local/bin/pw-restore
www-data ALL=(root) NOPASSWD: /usr/local/bin/pw-delete
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart nginx
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart php8.3-fpm
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart mariadb
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart redis-server
www-data ALL=(root) NOPASSWD: /usr/bin/restic
www-data ALL=(root) NOPASSWD: /usr/bin/du
www-data ALL=(ALL) NOPASSWD: /usr/local/bin/pw-php
www-data ALL=(ALL) NOPASSWD: /usr/local/bin/pw-update-config
www-data ALL=(ALL) NOPASSWD: /usr/local/bin/pw-alias
www-data ALL=(ALL) NOPASSWD: /usr/local/bin/pw-create-async
www-data ALL=(ALL) NOPASSWD: /usr/local/bin/pw-import-backup
www-data ALL=(ALL) NOPASSWD: /usr/local/lib/pwhost/scripts/*
```

---

## Configurazione Nginx (Template)

Ogni sito ha:
- Socket PHP-FPM dedicato: `/run/php/{dominio}.sock`
- Cache FastCGI con bypass per POST/admin
- Security headers (X-Frame-Options, HSTS, etc.)
- Deny per `.`, `/wire/`, `/site/assets/cache|logs|backups`

---

## Configurazione PHP-FPM (Template)

```ini
[dominio.it]
user = www-data
group = www-data
listen = /run/php/dominio.it.sock
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
php_value[session.save_handler] = redis
php_value[session.save_path] = "tcp://127.0.0.1:6379"
php_value[upload_max_filesize] = 100M
php_value[memory_limit] = 256M
```

---

## Sicurezza

- **Fail2ban**: SSH protetto (3 tentativi, ban 1 ora)
- **IP whitelist**: 194.48.249.245 (QNAP)
- **SFTP chroot**: Utenti SFTP confinati in /var/www/sites/{sito}
- **HTTPS**: Let's Encrypt via Certbot

---

## Siti Ospitati

| Dominio | PHP | Note |
|---------|-----|------|
| arkenu.it | 8.3 | |
| cato2.it | 8.3 | Backup attivo |
| claviere.it | 7.4 | Backup attivo, webcam cron |
| keeplive.it | 8.3 | |
| vitaminag.arkenu.org | 7.3 | Legacy PHP |
| vm1.arkenu.it | 8.3 | Dashboard PWHost |
| fasiresults.keeplive.it | 8.3 | Solo file, no DB |

---

## Troubleshooting

### Job asincrono bloccato
```bash
# Verifica stato
cat /var/run/pwhost/job_*.json

# Verifica coda at
atq

# Log atd
journalctl -u atd --since "5 minutes ago"

# Esegui manualmente
sudo /usr/local/lib/pwhost/scripts/job_XXX.sh
```

### PHP-FPM non risponde
```bash
systemctl restart php8.3-fpm
systemctl status php8.3-fpm
tail -f /var/www/sites/{sito}/logs/php-error.log
```

### Nginx errore
```bash
nginx -t
systemctl reload nginx
tail -f /var/www/sites/{sito}/logs/error.log
```

### Backup fallito
```bash
# Test connessione QNAP
rclone lsd qnap:/share/FTP/processwire/

# Log backup
tail -f /var/log/pwhost-backup.log
```

---

## Note Importanti

1. **SERVER DI PRODUZIONE** - Non fare modifiche senza test
2. La creazione siti usa `at` scheduler per evitare 502 durante restart PHP-FPM
3. Gli script wrapper devono stare in `/usr/local/lib/pwhost/scripts/` (non `/tmp/` o `/run/` per noexec)
4. ProcessWire richiede aggiornamento `config.php` dopo import (DB credentials + httpHost)
5. I file temporanei in `/tmp/` vengono puliti ogni notte se > 1 giorno

---

## Architettura Dashboard (Performance)

La dashboard usa **lazy loading** per i dati QNAP per evitare blocchi durante il caricamento:

1. **Caricamento iniziale veloce**: `?action=sites` restituisce i siti senza dati QNAP
2. **Dati backup via AJAX**: `?action=all-backups` carica tutti i backup con una singola chiamata `rclone --recursive --files-only`
3. **Spazio QNAP separato**: `?action=disk-qnap` carica lo spazio disco QNAP in background

Questo evita che la latenza SFTP verso QNAP (~3-5 sec) blocchi il rendering della pagina.

### Funzioni chiave in index.php:
- `getAllBackupsFromQnap()` - Una sola chiamata rclone per tutti i backup
- `getSnapshots()` - Lista backup per singolo sito (usa shell_exec diretto per evitare falsi positivi)
- `isQnapAvailable()` - Check rapido connettività QNAP

---

## Disaster Recovery

### Contenuto backup DR
Il backup giornaliero su Aruba contiene:
- **Tutti i siti** (`/var/www/sites/*/public/`)
- **Tutti i database** (dump singoli + `all-databases.sql`)
- **Credenziali** (`.db-credentials`, `.sftp-credentials`)
- **Config Nginx** (`/etc/nginx/sites-available/`, snippets)
- **Pool PHP-FPM** (tutte le versioni: 7.3, 7.4, 8.1, 8.3)
- **Certificati SSL** (`/etc/letsencrypt/`)
- **Script PWHost** (`/usr/local/bin/pw*`, `/usr/local/bin/pwhost/`)
- **Config rclone** per backup
- **Cron jobs** e **sudoers**
- **Info server** (pacchetti installati, info sistema)

### Script di restore
```bash
pw-restore-dr --list                    # Lista backup disponibili
pw-restore-dr --download [file]         # Solo download
pw-restore-dr --restore-site DOMAIN     # Ripristina singolo sito
pw-restore-dr --full [file]             # Ripristino completo server (DANGER)
```

### Procedura Disaster Recovery completa

**Scenario**: Server perso, nuovo server Ubuntu 24.04 da configurare.

1. **Installare pacchetti base**:
   ```bash
   apt update && apt install -y nginx mariadb-server redis-server rclone
   apt install -y php7.3-fpm php7.4-fpm php8.1-fpm php8.3-fpm
   apt install -y php8.3-mysql php8.3-curl php8.3-gd php8.3-mbstring php8.3-xml php8.3-zip
   ```

2. **Configurare rclone per Aruba**:
   ```bash
   mkdir -p ~/.config/rclone
   cat > ~/.config/rclone/rclone.conf << 'EOF'
   [aruba-dr]
   type = ftp
   host = ftp2.dc1.computing.cloud.it
   user = ARU-23558
   pass = [PASSWORD_CRIPTATA]
   port = 21
   explicit_tls = true
   EOF
   ```

3. **Scaricare e estrarre backup**:
   ```bash
   mkdir -p /var/backups/pwhost-dr-restore
   rclone copy aruba-dr:/pwhost-backup/pwhost-full-backup_YYYYMMDD.tar.gz.iso /var/backups/pwhost-dr-restore/
   cd /var/backups/pwhost-dr-restore
   tar -xzf pwhost-full-backup_YYYYMMDD.tar.gz.iso
   ```

4. **Ripristinare configurazioni**:
   ```bash
   # Nginx
   cp -a system/nginx/sites-available/* /etc/nginx/sites-available/
   cp -a system/nginx/snippets/* /etc/nginx/snippets/

   # PHP-FPM pools
   cp system/php-fpm/7.3/*.conf /etc/php/7.3/fpm/pool.d/
   cp system/php-fpm/7.4/*.conf /etc/php/7.4/fpm/pool.d/
   cp system/php-fpm/8.1/*.conf /etc/php/8.1/fpm/pool.d/
   cp system/php-fpm/8.3/*.conf /etc/php/8.3/fpm/pool.d/

   # SSL certificates
   cp -a system/letsencrypt/letsencrypt/* /etc/letsencrypt/

   # Scripts
   cp system/pwhost/pw* /usr/local/bin/
   cp -a system/pwhost/pwhost /usr/local/bin/
   chmod +x /usr/local/bin/pw*

   # Cron e sudoers
   cp system/cron/pwhost-backup /etc/cron.d/
   cp system/sudoers/pwhost /etc/sudoers.d/
   ```

5. **Ripristinare siti**:
   ```bash
   mkdir -p /var/www/sites
   cp -a sites/* /var/www/sites/
   chown -R www-data:www-data /var/www/sites
   ```

6. **Ripristinare database**:
   ```bash
   mysql < all-databases.sql
   ```

7. **Riavviare servizi**:
   ```bash
   nginx -t && systemctl restart nginx
   systemctl restart php7.3-fpm php7.4-fpm php8.1-fpm php8.3-fpm
   systemctl restart mariadb redis-server
   ```

8. **Verificare**:
   - Tutti i siti web
   - Certificati SSL (potrebbero richiedere `certbot renew`)
   - Backup funzionanti

### Verifica stato DR
```bash
# Log backup DR
tail -f /var/log/pwhost-backup-dr.log

# Lista backup su Aruba
rclone lsl aruba-dr:/pwhost-backup/

# Test connessione Aruba
rclone lsd aruba-dr:/
```

### Troubleshooting DR

**Errore "Policy requires SSL"**:
```bash
# Verificare che rclone abbia explicit_tls = true
cat ~/.config/rclone/rclone.conf
```

**Errore "550 Access is denied"**:
- Il file deve avere estensione `.iso` per Aruba FTP

**Backup troppo grande**:
- Verificare spazio su Aruba (max ~50GB tipicamente)
- Considerare esclusione di file temporanei/cache
