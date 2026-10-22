#!/bin/bash
# =====================================================================
# Obaits — Linux Endpoint Audit Enablement (Phase 4.1 Stage 2)
# Fetched and run automatically at the end of enrollment, transparently —
# not a separate manual step. Installs/configures Auditd, Journald
# persistence, and PAM/sudo logging so Wazuh has real audit data to work
# with, instead of default OS-level noise only.
#
# Runs as its own subprocess from install.sh with its own exit code
# checked there — deliberately NOT using `set -e` here, so one failed
# step (e.g. a package manager hiccup) doesn't take down the rest of
# this script, matching the warn-and-continue pattern used everywhere
# else in Obaits' enrollment flow.
# =====================================================================

echo "[*] Starting Linux audit enablement..."

# --- 1. Install Auditd (if missing) ---
if command -v apt-get &> /dev/null; then
  apt-get install -y auditd audispd-plugins >/dev/null 2>&1
elif command -v yum &> /dev/null; then
  yum install -y audit audit-libs >/dev/null 2>&1
elif command -v dnf &> /dev/null; then
  dnf install -y audit audit-libs >/dev/null 2>&1
else
  echo "[-] WARNING: no known package manager found — auditd was not installed."
  exit 1
fi

if ! command -v auditctl &> /dev/null; then
  echo "[-] WARNING: auditd install did not succeed — skipping audit rule deployment."
  exit 1
fi

# --- 2. Deploy Wazuh-specific Audit Rules ---
AUDIT_RULES_FILE="/etc/audit/rules.d/obaits.rules"
cat << 'EOF' > "$AUDIT_RULES_FILE"
# ---------------------------
# Obaits Audit Rules (Phase 4.1)
# ---------------------------

# Track file system changes (high-value targets)
-w /etc/passwd -p wa -k identity
-w /etc/shadow -p wa -k identity
-w /etc/group -p wa -k identity
-w /etc/sudoers -p wa -k identity
-w /etc/sudoers.d -p wa -k identity
-w /etc/ssh/sshd_config -p wa -k ssh_config
-w /etc/ssh/ssh_config -p wa -k ssh_config
-w /root/.ssh -p wa -k identity
-w /home -p wa -k identity

# Track privilege escalations (SUID/SGID execution)
-a always,exit -F perm=x -F uid=0 -F auid!=1000 -k priv_esc

# Track admin actions (Sudo edits)
-w /var/log/sudo -p wa -k sudo_actions

# Process creation logging (critical for EDR)
-a always,exit -S execve -k process_creation

# Network connections (socket syscalls)
-a always,exit -S socket,connect,accept -k network_activity

# Kernel module loading (persistence)
-w /sbin/insmod -p x -k kernel_modules
-w /sbin/rmmod -p x -k kernel_modules

# Track web server file changes (if Apache/Nginx installed)
-w /var/www -p wa -k web_content
-w /etc/httpd -p wa -k web_config
-w /etc/nginx -p wa -k web_config

# Track database files (if databases installed)
-w /var/lib/mysql -p wa -k database_files
-w /var/lib/postgresql -p wa -k database_files
EOF

# --- 3. Tune Auditd performance ---
grep -q "^max_log_file" /etc/audit/auditd.conf || echo "max_log_file = 50" >> /etc/audit/auditd.conf
grep -q "^num_logs" /etc/audit/auditd.conf || echo "num_logs = 10" >> /etc/audit/auditd.conf
grep -q "^max_log_file_action" /etc/audit/auditd.conf || echo "max_log_file_action = ROTATE" >> /etc/audit/auditd.conf
grep -q "^space_left" /etc/audit/auditd.conf || echo "space_left = 75" >> /etc/audit/auditd.conf

# --- 4. Restart Auditd to apply rules ---
systemctl restart auditd 2>/dev/null
augenrules --load 2>/dev/null || true

# --- 5. Configure Journald for Persistent Logging ---
if [ -f /etc/systemd/journald.conf ]; then
  sed -i 's/^#Storage=.*/Storage=persistent/' /etc/systemd/journald.conf
  sed -i 's/^#SystemMaxUse=.*/SystemMaxUse=1G/' /etc/systemd/journald.conf
  systemctl restart systemd-journald 2>/dev/null
fi

# --- 6. Ensure Sudo logs all attempts ---
grep -q "^Defaults.*syslog" /etc/sudoers || echo "Defaults syslog" >> /etc/sudoers

echo "[+] Linux audit enablement complete."
