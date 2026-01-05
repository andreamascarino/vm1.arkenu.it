let phpVersions = [];

function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast ' + type + ' show';
    setTimeout(() => t.classList.remove('show'), 3000);
}

function loadSystem() {
    fetch('?action=system').then(r => r.json()).then(d => {
        document.getElementById('uptime').textContent = d.uptime;
        document.getElementById('load-avg').textContent = Math.round(d.load / 4 * 100) + '%';
    });
}

function loadServices() {
    fetch('?action=services').then(r => r.json()).then(data => {
        const names = {nginx: 'Nginx', php83: 'PHP 8.3', php73: 'PHP 7.3', mariadb: 'MariaDB', redis: 'Redis'};
        let html = '';
        for (const [k, v] of Object.entries(data)) {
            html += `<div class="service-badge"><span class="service-dot ${v ? 'on' : 'off'}"></span><span>${names[k]}</span></div>`;
        }
        document.getElementById('services-badges').innerHTML = html;
    });
}

function loadDisk() {
    fetch('?action=disk')
        .then(r => r.json())
        .then(d => {
            // Server locale
            const sUsed = (d.server.used / 1000000000).toFixed(1);
            const sTotal = (d.server.total / 1000000000).toFixed(1);
            const sPct = (d.server.used / d.server.total * 100).toFixed(1);
            
            let html = `
                <div class="disk-info"><span>Server</span><span>${sUsed} / ${sTotal} GB (${sPct}%)</span></div>
                <div class="disk-bar"><div class="disk-fill" style="width:${sPct}%"></div></div>`;
            
            // QNAP backup - con gestione errori
            if (d.qnap && d.qnap.used !== undefined && d.qnap.limit) {
                const qUsed = (d.qnap.used / 1000000000).toFixed(1);
                const qLimit = (d.qnap.limit / 1000000000).toFixed(0);
                const qPct = (d.qnap.used / d.qnap.limit * 100).toFixed(1);
                html += `
                    <div class="disk-info" style="margin-top:0.5rem"><span>QNAP Backup</span><span>${qUsed} / ${qLimit} GB (${qPct}%)</span></div>
                    <div class="disk-bar"><div class="disk-fill" style="width:${qPct}%;background:var(--accent-secondary)"></div></div>`;
            } else {
                html += `
                    <div class="disk-info" style="margin-top:0.5rem;color:var(--warning)"><span>⚠️ QNAP Backup</span><span>Non raggiungibile</span></div>`;
            }
            
            document.getElementById('disk-info').innerHTML = html;
        })
        .catch(err => {
            console.error('Errore caricamento disk:', err);
            document.getElementById('disk-info').innerHTML = `
                <div class="disk-info" style="color:var(--danger)"><span>⚠️ Errore</span><span>Impossibile caricare dati disco</span></div>`;
        });
}

function loadPhpVersions() {
    fetch('?action=php-versions').then(r => r.json()).then(versions => {
        phpVersions = versions;
        const select = document.getElementById('php-version-select');
        if (select) {
            select.innerHTML = versions.map(v => `<option value="${v}">${v}</option>`).join('');
        }
    });
}

function toggleDumpUpload() {
    const check = document.getElementById('import-db-check');
    const group = document.getElementById('dump-upload-group');
    group.style.display = check.checked ? 'block' : 'none';
}

function toggleFilesUpload() {
    const check = document.getElementById('import-files-check');
    const group = document.getElementById('files-upload-group');
    group.style.display = check.checked ? 'block' : 'none';
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => showToast('Copiato!')).catch(() => {});
}

function updateProgress(percent, status) {
    const fill = document.getElementById('progress-fill');
    const statusEl = document.getElementById('progress-status');
    if (fill) fill.style.width = percent + '%';
    if (statusEl) statusEl.textContent = status;
}

let sitesData = {};

function loadSites() {
    fetch('?action=sites').then(r => r.json()).then(sites => {
        document.getElementById('sites-count').textContent = sites.length;
        if (sites.length === 0) {
            document.getElementById('sites-list').innerHTML = '<div class="empty-state"><p>Nessun sito</p><button class="btn btn-primary" style="margin-top:1rem" onclick="openModal(\'create\')">Crea sito</button></div>';
            return;
        }
        
        sites.forEach(s => sitesData[s.domain] = s);
        
        let html = '';
        for (const s of sites) {
            const sftpSection = s.sftpUser ? `
                <div class="panel-section">
                    <h4>Accesso SFTP</h4>
                    <div class="panel-row"><span class="label">Host</span><span class="value">209.227.239.208</span></div>
                    <div class="panel-row"><span class="label">Utente</span><span class="value clickable" onclick="copyToClipboard('${s.sftpUser}')">${s.sftpUser}</span></div>
                    <div class="panel-row"><span class="label">Password</span><span class="value clickable" onclick="copyToClipboard('${s.sftpPass}')">${s.sftpPass}</span></div>
                </div>` : '';

            const aliasSection = `
                <div class="panel-section">
                    <h4>Domini Alias</h4>
                    <div id="aliases-${s.domain}" class="aliases-list">
                        ${(s.aliases || []).map(a => `<div class="alias-item"><span>${a}</span><button class="btn-tiny btn-danger" onclick="event.stopPropagation(); removeAlias('${s.domain}', '${a}')">✕</button></div>`).join('') || '<span style="color:var(--text-muted)">Nessun alias</span>'}
                    </div>
                    <div class="panel-row" style="margin-top:10px">
                        <input type="text" id="new-alias-${s.domain}" placeholder="alias.dominio.it" class="form-input-sm" onclick="event.stopPropagation()" style="flex:1">
                        <button class="btn btn-sm btn-secondary" onclick="event.stopPropagation(); addAlias('${s.domain}')">+ Aggiungi</button>
                    </div>
                </div>`;
            
            const phpOptions = (s.phpVersions || phpVersions).map(v => 
                `<option value="${v}" ${v === s.phpVersion ? 'selected' : ''}>${v}</option>`
            ).join('');
            
            html += `
            <div class="site-item" data-domain="${s.domain}">
                <div class="site-header" onclick="toggleSite('${s.domain}')">
                    <div class="site-info">
                        <div class="site-icon">🌐</div>
                        <div class="site-details">
                            <div class="site-domain">
                                <a href="http${s.ssl ? 's' : ''}://${s.domain}" target="_blank" onclick="event.stopPropagation()">${s.domain}</a>
                                <div class="badges-row">
                                    <span class="badge badge-${s.ssl ? 'ssl' : 'http'}">${s.ssl ? '🔒 SSL' : '⚠️ HTTP'}</span>
                                    <span class="badge badge-php">PHP ${s.phpVersion}</span>
                                    <span class="badge badge-backup-${s.backupEnabled ? 'on' : 'off'}">${s.backupEnabled ? '✓ Backup' : '✗ Backup'}</span>
                                </div>
                            </div>
                            <div class="site-meta">
                                <span>💾 ${s.size}</span>
                                <span>🕐 ${s.lastBackup === 'Server di backup non disponibile' ? '⚠️ ' + s.lastBackup : s.lastBackup}</span>
                            </div>
                        </div>
                    </div>
                    <div class="expand-icon">▼</div>
                </div>
                <div class="site-panel">
                    <div class="panel-content">
                        <div class="panel-section">
                            <h4>Dimensioni</h4>
                            <div class="panel-row"><span class="label">File</span><span class="value">${s.sizeFiles}</span></div>
                            <div class="panel-row"><span class="label">Database</span><span class="value">${s.sizeDb}</span></div>
                            <div class="panel-row"><span class="label">Backup QNAP</span><span class="value" id="backup-size-${s.domain}">Calcolo...</span></div>
                            <div class="panel-row"><span class="label">Document Root</span><span class="value">${s.docRoot}</span></div>
                        </div>
                        <div class="panel-section">
                            <h4>Database</h4>
                            <div class="panel-row"><span class="label">Nome</span><span class="value">${s.dbName}</span></div>
                            <div class="panel-row"><span class="label">Utente</span><span class="value clickable" onclick="copyToClipboard('${s.dbUser}')">${s.dbUser}</span></div>
                            <div class="panel-row"><span class="label">Password</span><span class="value clickable" onclick="copyToClipboard('${s.dbPass}')">${s.dbPass || '-'}</span></div>
                        </div>
                        ${sftpSection}
                        ${aliasSection}
                        <div class="panel-section">
                            <h4>Configurazione</h4>
                            <div class="panel-row">
                                <span class="label">Versione PHP</span>
                                <select class="form-select-sm" onchange="changePhp('${s.domain}', this.value)" onclick="event.stopPropagation()">
                                    ${phpOptions}
                                </select>
                            </div>
                        </div>
                        <div class="panel-section">
                            <h4>Backup Disponibili</h4>
                            <div class="snapshot-list" id="snapshots-${s.domain}"><div style="color:var(--text-muted)">Caricamento...</div></div>
                        </div>
                        <div class="panel-section">
                            <h4>Backup Automatico</h4>
                            <div class="toggle-wrapper">
                                <div class="toggle ${s.backupEnabled ? 'active' : ''}" onclick="toggleBackup('${s.domain}', ${!s.backupEnabled}); event.stopPropagation();"></div>
                                <span class="toggle-label">${s.backupEnabled ? 'Attivo' : 'Disattivo'}</span>
                            </div>
                            <div class="panel-row" style="margin-top:1rem;"><span class="label">Ultimo backup</span><span class="value" style="${s.lastBackup === 'Server di backup non disponibile' ? 'color:var(--warning)' : ''}">${s.lastBackup === 'Server di backup non disponibile' ? '⚠️ ' + s.lastBackup : s.lastBackup}</span></div>
                        </div>
                        <div class="panel-actions">
                            <button class="btn btn-secondary btn-sm" onclick="doBackup('${s.domain}'); event.stopPropagation();">📦 Backup Ora</button>
                            ${!s.ssl ? `<button class="btn btn-primary btn-sm" onclick="doSSL('${s.domain}'); event.stopPropagation();">🔒 SSL</button>` : ''}
                            <button class="btn btn-danger btn-sm" onclick="doDelete('${s.domain}'); event.stopPropagation();">🗑️ Elimina</button>
                        </div>
                    </div>
                </div>
            </div>`;
        }
        document.getElementById('sites-list').innerHTML = html;
    });
}

function toggleSite(domain) {
    const item = document.querySelector(`.site-item[data-domain="${domain}"]`);
    const wasExpanded = item.classList.contains('expanded');
    document.querySelectorAll('.site-item').forEach(el => el.classList.remove('expanded'));
    if (!wasExpanded) {
        item.classList.add('expanded');
        loadSnapshots(domain);
        loadBackupSize(domain);
    }
}

function loadBackupSize(domain) {
    const el = document.getElementById('backup-size-' + domain);
    if (el) {
        fetch('?action=backup-size&domain=' + domain)
            .then(r => r.json())
            .then(d => {
                if (d.error) {
                    el.textContent = '⚠️ ' + d.size;
                    el.style.color = 'var(--warning)';
                } else {
                    el.textContent = d.size;
                    el.style.color = '';
                }
            })
            .catch(err => {
                el.textContent = '⚠️ Errore';
                el.style.color = 'var(--warning)';
            });
    }
}

function loadSnapshots(domain) {
    fetch('?action=backups&domain=' + domain)
        .then(r => r.json())
        .then(snaps => {
            const c = document.getElementById('snapshots-' + domain);
            if (snaps.error) {
                c.innerHTML = '<div style="color:var(--warning)">⚠️ ' + snaps.error + '</div>';
                return;
            }
            if (snaps.length === 0) {
                c.innerHTML = '<div style="color:var(--text-muted)">Nessun backup</div>';
                return;
            }
            c.innerHTML = snaps.map(s => {
                const typeLabel = s.type === 'current' ? '🔄' : '📦';
                const typeClass = s.type === 'current' ? 'color:#60a5fa' : 'color:#4ade80';
                return `<div class="snapshot-item" style="cursor:pointer" onclick="restoreBackup('${domain}', '${s.name}', '${s.date}')"><span style="${typeClass}">${typeLabel} ${s.date}</span><span style="color:var(--text-muted);font-size:0.7rem">${s.type}</span></div>`;
            }).join('');
        })
        .catch(err => {
            const c = document.getElementById('snapshots-' + domain);
            if (c) {
                c.innerHTML = '<div style="color:var(--warning)">⚠️ Errore caricamento backup</div>';
            }
        });
}

function restoreBackup(domain, filename, dateLabel) {
    const choice = prompt("Restore backup " + dateLabel + " per " + domain + "?\n\nDigita:\n  db = solo database\n  files = solo files\n  all = tutto\n\n(o lascia vuoto per annullare)");
    
    if (!choice) return;
    const type = choice.toLowerCase().trim();
    if (!["db", "files", "all"].includes(type)) {
        showToast("Opzione non valida. Usa: db, files, all");
        return;
    }
    
    const progress = document.getElementById("restore-progress");
    const fill = document.getElementById("restore-fill");
    const status = document.getElementById("restore-status");
    const output = document.getElementById("restore-output");
    
    progress.style.display = "block";
    fill.classList.add("indeterminate");
    status.textContent = "Restore " + type + " in corso...";
    output.style.display = "none";
    output.textContent = "";
    
    fetch("?action=restore&domain=" + domain + "&file=" + encodeURIComponent(filename) + "&type=" + type)
        .then(r => r.json())
        .then(d => {
            fill.classList.remove("indeterminate");
            if (d.success) {
                status.textContent = "Restore " + type + " completato!";
                status.style.color = "#4ade80";
                if (d.output) {
                    output.textContent = d.output;
                    output.style.display = "block";
                }
                setTimeout(() => { progress.style.display = "none"; status.style.color = ""; }, 5000);
            } else {
                status.textContent = "Errore: " + (d.error || "sconosciuto");
                status.style.color = "#f87171";
                setTimeout(() => { progress.style.display = "none"; status.style.color = ""; }, 3000);
            }
        })
        .catch(e => {
            fill.classList.remove("indeterminate");
            status.textContent = "Errore: " + e.message;
            status.style.color = "#f87171";
            setTimeout(() => { progress.style.display = "none"; status.style.color = ""; }, 3000);
        });
}
function changePhp(domain, version) {
    const currentVersion = sitesData[domain]?.phpVersion;
    if (currentVersion === version) return;
    
    if (!confirm(`Cambiare PHP da ${currentVersion} a ${version} per ${domain}?`)) {
        loadSites();
        return;
    }
    showToast('Cambio PHP in corso...');
    fetch(`?action=change-php&domain=${domain}&version=${version}`).then(r => r.json()).then(d => {
        if (d.success) {
            showToast(`PHP cambiato a ${version}!`);
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('Errore: ' + d.error, 'error');
            loadSites();
        }
    });
}

function toggleBackup(domain, enable) {
    fetch(`?action=backup-toggle&domain=${domain}&enable=${enable ? 1 : 0}`).then(r => r.json()).then(d => {
        if (d.success) {
            showToast(`Backup ${enable ? 'attivato' : 'disattivato'} per ${domain}`);
            loadSites();
        } else {
            showToast('Errore: ' + d.error, 'error');
        }
    });
}

function openModal(name) { 
    document.getElementById('modal-' + name).classList.add('active');
    if (name === 'create') {
        loadPhpVersions();
    }
}

function closeModal(name) { 
    document.getElementById('modal-' + name).classList.remove('active');
    if (name === 'create') {
        document.getElementById('form-create').reset();
        document.getElementById('create-output').style.display = 'none';
        document.getElementById('create-progress').style.display = 'none';
        document.getElementById('dump-upload-group').style.display = 'none';
        document.getElementById('files-upload-group').style.display = 'none';
        document.getElementById('btn-create').disabled = false;
    }
}

function doBackup(domain) {
    if (!confirm('Backup di ' + domain + '?')) return;
    showToast('Backup in corso...');
    fetch('?action=backup&domain=' + domain).then(r => r.json()).then(d => {
        showToast('Backup completato!');
        loadSites();
    });
}

function doDelete(domain) {
    if (!confirm('⚠️ Eliminare ' + domain + '?')) return;
    if (!confirm('Confermi? File e database saranno eliminati.')) return;
    fetch('?action=delete&domain=' + domain).then(r => r.json()).then(() => {
        showToast('Eliminato');
        loadSites();
    });
}

function doSSL(domain) {
    if (!confirm('SSL per ' + domain + '? I DNS devono puntare a questo server.')) return;
    showToast('Generazione SSL...');
    fetch('?action=ssl&domain=' + domain).then(r => r.json()).then(d => {
        if (d.output?.includes('success')) showToast('SSL attivato!');
        else {
            document.getElementById('output-title').textContent = 'SSL';
            document.getElementById('output-content').textContent = d.output || 'Errore';
            openModal('output');
        }
        loadSites();
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('form-create');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const out = document.getElementById('create-output');
            const progress = document.getElementById('create-progress');
            const btn = document.getElementById('btn-create');
            
            // Mostra progress bar
            progress.style.display = 'block';
            out.style.display = 'none';
            btn.disabled = true;
            
            // Simula avanzamento durante upload
            let percent = 0;
            const hasFiles = formData.get('site_zip')?.size > 0;
            const hasDump = formData.get('sql_dump')?.size > 0;
            
            updateProgress(5, 'Upload file in corso...');
            
            const xhr = new XMLHttpRequest();
            
            // Traccia upload progress
            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    const pct = Math.round((e.loaded / e.total) * 100);
                    updateProgress(pct, 'Upload: ' + pct + '%');
                }
            });
            
            // Quando upload finisce, mostra stato indeterminato
            xhr.upload.addEventListener('load', function() {
                document.getElementById('progress-fill').classList.add('indeterminate');
                document.getElementById('progress-status').textContent = 'Installazione in corso...';
            });
            
            xhr.addEventListener('load', function() {
                document.getElementById('progress-fill').classList.remove('indeterminate');
                updateProgress(100, 'Completato!');
                if (xhr.status === 200) {
                    try {
                        const d = JSON.parse(xhr.responseText);
                        updateProgress(100, 'Completato!');
                        out.style.display = 'block';
                        out.textContent = d.output || d.error;
                        if (d.success) {
                            showToast('Sito creato!');
                            setTimeout(() => { closeModal('create'); loadSites(); }, 2000);
                        }
                    } catch(e) {
                        updateProgress(0, 'Errore parsing risposta');
                        out.style.display = 'block';
                        out.textContent = 'Errore: ' + xhr.responseText;
                    }
                } else {
                    updateProgress(0, 'Errore server: ' + xhr.status);
                    out.style.display = 'block';
                    out.textContent = 'Errore HTTP: ' + xhr.status;
                }
                btn.disabled = false;
            });
            
            xhr.addEventListener('error', function() {
                updateProgress(0, 'Errore di rete');
                out.style.display = 'block';
                out.textContent = 'Errore di connessione';
                btn.disabled = false;
            });
            
            // Simula progress server-side
            let serverProgress = 50;
            const progressInterval = setInterval(() => {
                if (serverProgress < 90) {
                    serverProgress += 5;
                    updateProgress(serverProgress, 'Creazione sito in corso...');
                }
            }, 500);
            
            xhr.addEventListener('loadend', function() {
                clearInterval(progressInterval);
            });
            
            xhr.open('POST', '?action=create');
            xhr.send(formData);
        });
    }
    
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') document.querySelectorAll('.modal.active').forEach(m => closeModal(m.id.replace('modal-', '')));
    });
    
    loadPhpVersions();
    loadSystem(); loadServices(); loadDisk(); loadSites(); loadStats();
    setInterval(loadServices, 30000);
    setInterval(loadDisk, 60000);
    setInterval(loadSystem, 60000);
});

function loadDrBackups() {
    const container = document.getElementById("dr-backups-list");
    if (!container) return;
    container.innerHTML = "<div style=\"color:var(--text-muted)\">Caricamento...</div>";
    fetch("?action=dr-backups").then(r => r.json()).then(backups => {
        if (backups.length === 0) {
            container.innerHTML = "<div style=\"color:var(--text-muted)\">Nessun backup disponibile</div>";
            return;
        }
        container.innerHTML = backups.map(b => 
            "<div class=\"snapshot-item\" style=\"cursor:pointer;padding:10px;border-bottom:1px solid var(--border-color)\" onclick=\"restoreDr('" + b.name + "', '" + b.date + "')\">" +
            "<span style=\"color:#f59e0b\">🛡️ " + b.date + "</span>" +
            "<span style=\"color:var(--text-muted);font-size:0.7rem;margin-left:10px\">" + b.name + "</span>" +
            "</div>"
        ).join("");
    }).catch(e => {
        container.innerHTML = "<div style=\"color:#f87171\">Errore caricamento</div>";
    });
}

function restoreDr(filename, dateLabel) {
    const choice = prompt("RESTORE DISASTER RECOVERY backup " + dateLabel + "\n\nATTENZIONE: Questa operazione sovrascriverà i dati!\n\nDigita:\n  all = tutto (sistema + siti)\n  sites = solo siti\n  system = solo configurazioni\n\n(o lascia vuoto per annullare)");
    if (!choice) return;
    const type = choice.toLowerCase().trim();
    if (!["all", "sites", "system"].includes(type)) {
        showToast("Opzione non valida");
        return;
    }
    const progress = document.getElementById("restore-progress");
    const status = document.getElementById("restore-status");
    const output = document.getElementById("restore-output");
    progress.style.display = "block";
    document.getElementById("restore-fill").classList.add("indeterminate");
    status.textContent = "Avvio restore DR...";
    output.style.display = "none";
    
    fetch("?action=restore-dr&file=" + encodeURIComponent(filename) + "&type=" + type)
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                status.textContent = "Download e restore in corso...";
                const pollInterval = setInterval(() => {
                    fetch("?action=restore-dr-status")
                        .then(r => r.json())
                        .then(s => {
                            output.textContent = s.summary;
                            output.style.display = "block";
                            if (s.completed) {
                                clearInterval(pollInterval);
                                document.getElementById("restore-fill").classList.remove("indeterminate");
                                status.textContent = "Restore DR completato!";
                                status.style.color = "#4ade80";
                                setTimeout(() => { progress.style.display = "none"; status.style.color = ""; loadSites(); }, 5000);
                            } else if (s.error) {
                                clearInterval(pollInterval);
                                document.getElementById("restore-fill").classList.remove("indeterminate");
                                status.textContent = "Errore durante il restore";
                                status.style.color = "#f87171";
                                setTimeout(() => { progress.style.display = "none"; status.style.color = ""; }, 8000);
                            }
                        });
                }, 2000);
            } else {
                document.getElementById("restore-fill").classList.remove("indeterminate");
                status.textContent = "Errore: " + (d.error || "sconosciuto");
                status.style.color = "#f87171";
                setTimeout(() => { progress.style.display = "none"; status.style.color = ""; }, 5000);
            }
        });
}

function loadStats() {
    fetch('?action=stats').then(r => r.json()).then(stats => {
        // Mini grafico disco
        if (stats.disk && stats.disk.length > 1) {
            drawMiniChart('disk-chart', stats.disk, '#818cf8');
        }
        // Mini grafico load
        if (stats.load && stats.load.length > 1) {
            drawMiniChart('load-chart', stats.load, '#f59e0b', 4);
        }
    });
}

function drawMiniChart(containerId, data, color, fixedMax = null) {
    const container = document.getElementById(containerId);
    if (!container || data.length < 2) return;
    
    const width = container.offsetWidth || 150;
    const height = container.offsetHeight || 50;
    const values = data.map(d => d.v);
    const max = fixedMax !== null ? fixedMax : (Math.max(...values) * 1.1 || 1);
    const min = 0;
    
    const points = values.map((v, i) => {
        const x = (i / (values.length - 1)) * width;
        const y = height - ((v - min) / (max - min)) * height;
        return `${x},${y}`;
    }).join(' ');
    
    container.innerHTML = `
        <svg width="${width}" height="${height}" style="position:absolute;top:0;left:0;opacity:0.3">
            <polyline fill="none" stroke="${color}" stroke-width="2" points="${points}"/>
        </svg>`;
}


function addAlias(domain) {
    const input = document.getElementById('new-alias-' + domain);
    const alias = input.value.trim().toLowerCase();
    if (!alias) {
        showToast('Inserisci un alias', 'error');
        return;
    }
    if (!/^[a-z0-9.-]+\.[a-z]{2,}$/.test(alias)) {
        showToast('Formato alias non valido', 'error');
        return;
    }
    showToast('Aggiunta alias...');
    fetch('?action=alias-add&domain=' + encodeURIComponent(domain) + '&alias=' + encodeURIComponent(alias))
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                showToast('Alias aggiunto!');
                input.value = '';
                loadSites();
            } else {
                showToast('Errore: ' + (d.error || 'sconosciuto'), 'error');
            }
        });
}

function removeAlias(domain, alias) {
    if (!confirm('Rimuovere alias ' + alias + '?')) return;
    showToast('Rimozione alias...');
    fetch('?action=alias-remove&domain=' + encodeURIComponent(domain) + '&alias=' + encodeURIComponent(alias))
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                showToast('Alias rimosso!');
                loadSites();
            } else {
                showToast('Errore: ' + (d.error || 'sconosciuto'), 'error');
            }
        });
}

function loadSystemStatus() {
    const container = document.getElementById('system-status-content');
    container.innerHTML = '<div style="color:var(--text-muted)">Caricamento...</div>';
    fetch('?action=system-status').then(r => r.json()).then(d => {
        if (d.error) {
            container.innerHTML = '<div style="color:#f87171">' + d.error + '</div>';
            return;
        }
        const updatesClass = d.updatesAvailable > 0 ? (d.securityUpdates > 0 ? 'color:#f87171' : 'color:#f59e0b') : 'color:#4ade80';
        container.innerHTML = `
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;">
                <div class="status-box">
                    <div class="status-label">Sistema Operativo</div>
                    <div class="status-value">${d.versions.os}</div>
                </div>
                <div class="status-box">
                    <div class="status-label">Aggiornamenti</div>
                    <div class="status-value" style="${updatesClass}">${d.updatesAvailable} disponibili (security: auto)${d.securityUpdates > 0 ? ' (' + d.securityUpdates + ' security)' : ''}</div>
                </div>
                <div class="status-box">
                    <div class="status-label">Nginx</div>
                    <div class="status-value">${d.versions.nginx}</div>
                </div>
                <div class="status-box">
                    <div class="status-label">PHP 8.3</div>
                    <div class="status-value">${d.versions.php83}</div>
                </div>
                <div class="status-box">
                    <div class="status-label">PHP 7.3</div>
                    <div class="status-value">${d.versions.php73}</div>
                </div>
                <div class="status-box">
                    <div class="status-label">MariaDB</div>
                    <div class="status-value">${d.versions.mariadb}</div>
                </div>
                <div class="status-box">
                    <div class="status-label">Redis</div>
                    <div class="status-value">${d.versions.redis}</div>
                </div>
                <div class="status-box">
                    <div class="status-label">Uptime</div>
                    <div class="status-value">${d.uptimeDays} giorni</div>
                </div>
            </div>
            <div style="margin-top:10px;font-size:0.75rem;color:var(--text-muted)">Ultimo check: ${d.lastCheck}</div>
        `;
    });
}

function loadLogs() {
    const type = document.getElementById('log-type-select').value;
    const lines = document.getElementById('log-lines-select').value;
    const container = document.getElementById('logs-content');
    
    container.innerHTML = '<div style="color:var(--text-muted)">Caricamento...</div>';
    
    fetch(`?action=logs&type=${type}&lines=${lines}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const logNames = {
                    'backup': 'Backup QNAP',
                    'backup-dr': 'Backup Disaster Recovery',
                    'updates': 'Aggiornamenti Sistema',
                    'db-maintenance': 'Manutenzione Database'
                };
                
                // Colora le righe in base al contenuto
                const coloredContent = data.content.split('\n').map(line => {
                    const escaped = escapeHtml(line);
                    if (!escaped.trim()) return escaped;
                    
                    // Errori (rosso)
                    if (/error|errore|failed|failure|fatal|critical|denied|refused|timeout|impossible/i.test(line)) {
                        return `<span class="log-error">${escaped}</span>`;
                    }
                    // Warning (giallo)
                    if (/warning|warn|attenzione|skipping|skipped|retry|retrying|slow/i.test(line)) {
                        return `<span class="log-warn">${escaped}</span>`;
                    }
                    // Successo (verde)
                    if (/completato|completed|success|ok|done|finished|===.*===$|uploaded|transferred.*100%/i.test(line)) {
                        return `<span class="log-success">${escaped}</span>`;
                    }
                    return escaped;
                }).join('\n');
                
                container.innerHTML = `
                    <div style="margin-bottom:10px;font-size:0.8rem;color:var(--text-muted);">
                        <strong>${logNames[data.type]}</strong> - ${data.file}
                    </div>
                    <pre class="log-output">${coloredContent}</pre>
                `;
                // Scroll to bottom
                const pre = container.querySelector('pre');
                if (pre) pre.scrollTop = pre.scrollHeight;
            } else {
                container.innerHTML = `<div style="color:var(--error)">${data.error}</div>`;
            }
        })
        .catch(err => {
            container.innerHTML = `<div style="color:var(--error)">Errore: ${err.message}</div>`;
        });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function toggleCommands() {
    const content = document.getElementById('commands-content');
    content.style.display = content.style.display === 'none' ? 'block' : 'none';
}
