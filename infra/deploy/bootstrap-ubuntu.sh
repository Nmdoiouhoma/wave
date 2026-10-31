#!/usr/bin/env bash
# Wave — one-time (idempotent) preparation of an Ubuntu 24.04 arm64 host
# (AWS t4g.small). Run as root:  sudo bash bootstrap-ubuntu.sh <deploy-user> [ssh-pubkey-file]
set -euo pipefail

DEPLOY_USER="${1:?usage: bootstrap-ubuntu.sh <deploy-user> [ssh-pubkey-file]}"
PUBKEY_FILE="${2:-}"
[ "$(id -u)" -eq 0 ] || { echo "run as root" >&2; exit 1; }
export DEBIAN_FRONTEND=noninteractive

# 1. System updates + automatic security updates.
apt-get update -q
apt-get -yq -o Dpkg::Options::=--force-confold upgrade
apt-get install -yq ca-certificates curl gnupg unattended-upgrades ufw fail2ban certbot rsync
echo 'unattended-upgrades unattended-upgrades/enable_auto_updates boolean true' | debconf-set-selections
dpkg-reconfigure -f noninteractive unattended-upgrades

# 2. Docker Engine + compose plugin from Docker's apt repository.
install -m 0755 -d /etc/apt/keyrings
if [ ! -s /etc/apt/keyrings/docker.asc ]; then
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
fi
. /etc/os-release
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable" \
    > /etc/apt/sources.list.d/docker.list
apt-get update -q
apt-get install -yq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
# Container logs rotated (compose also sets it per service).
if [ ! -s /etc/docker/daemon.json ]; then
    printf '{\n  "log-driver": "json-file",\n  "log-opts": { "max-size": "10m", "max-file": "3" }\n}\n' > /etc/docker/daemon.json
    systemctl restart docker
fi
systemctl enable --now docker

# 3. 2 GB swapfile, low swappiness (RAM first, swap as a safety net).
if ! swapon --show=NAME --noheadings | grep -qx /swapfile; then
    [ -f /swapfile ] || { fallocate -l 2G /swapfile; chmod 600 /swapfile; mkswap /swapfile; }
    swapon /swapfile
fi
grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
echo 'vm.swappiness=10' > /etc/sysctl.d/99-wave.conf
sysctl -q --system

# 4. Firewall: SSH, HTTP, HTTPS only (Docker-published ports are 80/443 only).
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

# 5. fail2ban on sshd.
cat > /etc/fail2ban/jail.d/wave-sshd.local <<'JAIL'
[sshd]
enabled  = true
backend  = systemd
maxretry = 5
findtime = 10m
bantime  = 1h
JAIL
systemctl enable --now fail2ban
systemctl restart fail2ban

# 6. Non-root deploy user in the docker group, key-only SSH.
id "$DEPLOY_USER" >/dev/null 2>&1 || adduser --disabled-password --gecos '' "$DEPLOY_USER"
usermod -aG docker "$DEPLOY_USER"
if [ -n "$PUBKEY_FILE" ]; then
    install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh"
    touch "/home/$DEPLOY_USER/.ssh/authorized_keys"
    grep -qxF "$(cat "$PUBKEY_FILE")" "/home/$DEPLOY_USER/.ssh/authorized_keys" || cat "$PUBKEY_FILE" >> "/home/$DEPLOY_USER/.ssh/authorized_keys"
    chown "$DEPLOY_USER:$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh/authorized_keys"
    chmod 600 "/home/$DEPLOY_USER/.ssh/authorized_keys"
fi
printf 'PasswordAuthentication no\nPermitRootLogin no\n' > /etc/ssh/sshd_config.d/99-wave.conf
systemctl reload ssh

# 7. Directories: app (compose files), JWT keys (php = uid 33), ACME webroot, TLS copies (nginx = uid 101).
install -d -m 755 -o "$DEPLOY_USER" -g "$DEPLOY_USER" /srv/wave
install -d -m 750 -o 33 -g 33 /srv/wave/jwt
install -d -m 755 /var/www/certbot
install -d -m 750 -o root -g 101 /etc/wave/tls
install -d /etc/letsencrypt/renewal-hooks/deploy
install -m 755 "$(dirname "$0")/certbot-deploy-hook.sh" /etc/letsencrypt/renewal-hooks/deploy/wave.sh

echo "bootstrap OK: docker $(docker --version | cut -d' ' -f3) / swap $(swapon --show=SIZE --noheadings | head -1) / ufw active"
