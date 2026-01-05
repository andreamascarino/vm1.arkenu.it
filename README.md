# vm1.arkenu.it

Server Configuration UI per la gestione di siti web e backup.

## Funzionalità

- Gestione configurazione siti web
- Monitoraggio stato server
- Gestione backup QNAP
- Interfaccia web per amministrazione

## Requisiti

- PHP 7.4+
- Accesso SSH/Sudo per operazioni di sistema
- Configurazione rclone per backup QNAP

## Installazione

1. Clona il repository
2. Configura `config.php` con le credenziali necessarie
3. Imposta il file `.auth_hash` per l'autenticazione

## Note

- Il file `config.php` e `.auth_hash` sono esclusi dal repository per sicurezza
- I file di stato (`stats.json`, `system-status.json`) sono generati dinamicamente
