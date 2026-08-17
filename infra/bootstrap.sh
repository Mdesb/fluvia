#!/usr/bin/env bash
#
# Durcissement + socle Docker d'un VPS OVH Debian 13 (Trixie).
# À lancer UNE FOIS, en root, sur un serveur fraîchement livré :
#
#   sudo bash bootstrap.sh
#
# Idempotent : relançable sans casse. Rejoue-le tel quel sur le VPS de PROD
# pour obtenir une machine identique.
#
set -euo pipefail

ADMIN_USER="${ADMIN_USER:-debian}"   # utilisateur par défaut de l'image OVH Debian
SWAP_SIZE="${SWAP_SIZE:-4G}"
TIMEZONE="${TIMEZONE:-Europe/Paris}"

log() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
warn() { printf '\n\033[1;33m /!\\ %s\033[0m\n' "$*"; }

[[ $EUID -eq 0 ]] || { echo "Lance ce script en root (sudo bash bootstrap.sh)"; exit 1; }
id "$ADMIN_USER" >/dev/null 2>&1 || { echo "Utilisateur '$ADMIN_USER' introuvable. Ajuste ADMIN_USER."; exit 1; }

# ---------------------------------------------------------------------------
# 1. Système de base
# ---------------------------------------------------------------------------
log "Mise à jour du système"
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get -y upgrade
apt-get install -y --no-install-recommends \
    ca-certificates curl gnupg git rsync htop vim \
    ufw fail2ban unattended-upgrades apt-listchanges

timedatectl set-timezone "$TIMEZONE"

# ---------------------------------------------------------------------------
# 2. Swap (filet de sécurité : 12 Go de RAM partagés entre 3 charges)
# ---------------------------------------------------------------------------
if [[ -z "$(swapon --show --noheadings)" ]]; then
    log "Création d'un swap de $SWAP_SIZE"
    fallocate -l "$SWAP_SIZE" /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
else
    log "Swap déjà présent, on ne touche à rien"
fi
# On privilégie la RAM : le swap n'est qu'un filet, pas un mode de fonctionnement.
echo 'vm.swappiness=10' > /etc/sysctl.d/99-swappiness.conf
sysctl -q --system

# ---------------------------------------------------------------------------
# 3. SSH : clé obligatoire, mot de passe refusé
#    Garde-fou : on ne coupe l'authentification par mot de passe QUE si une clé
#    est déjà installée, sinon on se verrouillerait dehors.
#
#    /!\ Le fichier doit s'appeler 00-* et non 99-* : sshd retient la PREMIÈRE
#    valeur rencontrée pour un mot-clé, et l'image OVH livre un
#    /etc/ssh/sshd_config.d/50-cloud-init.conf contenant "PasswordAuthentication yes".
#    Un fichier 99-* serait lu après et donc totalement ignoré.
# ---------------------------------------------------------------------------
log "Durcissement SSH"
ADMIN_HOME="$(getent passwd "$ADMIN_USER" | cut -d: -f6)"
HAS_KEY=0
for f in "$ADMIN_HOME/.ssh/authorized_keys" /root/.ssh/authorized_keys; do
    [[ -s "$f" ]] && HAS_KEY=1
done

if [[ $HAS_KEY -eq 1 ]]; then
    rm -f /etc/ssh/sshd_config.d/99-hardening.conf   # ancien emplacement, sans effet
    cat > /etc/ssh/sshd_config.d/00-hardening.conf <<'EOF'
PermitRootLogin prohibit-password
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
X11Forwarding no
MaxAuthTries 3
ClientAliveInterval 300
ClientAliveCountMax 2
EOF
    # cloud-init réécrit 50-cloud-init.conf à chaque boot : on lui dit de ne plus
    # réactiver l'authentification par mot de passe.
    mkdir -p /etc/cloud/cloud.cfg.d
    echo 'ssh_pwauth: false' > /etc/cloud/cloud.cfg.d/99-disable-password-auth.cfg

    sshd -t && { systemctl restart ssh 2>/dev/null || systemctl restart sshd; }
    echo "    Authentification par mot de passe désactivée."
else
    warn "Aucune clé SSH trouvée pour '$ADMIN_USER' ni root.
     L'authentification par mot de passe est LAISSÉE ACTIVE pour ne pas te bloquer.
     Installe ta clé (ssh-copy-id) puis relance ce script."
fi

# ---------------------------------------------------------------------------
# 4. Pare-feu
#    NB : Docker écrit directement dans nftables et court-circuite UFW pour tout
#    port publié sur 0.0.0.0. C'est pour ça que les stacks applicatives publient
#    sur 127.0.0.1 uniquement (cf. compose.preprod.yaml) : seul Nginx, natif sur
#    l'hôte, est exposé publiquement.
# ---------------------------------------------------------------------------
log "Configuration du pare-feu (UFW)"
ufw default deny incoming
ufw default allow outgoing
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

# ---------------------------------------------------------------------------
# 5. fail2ban (bannit le bruteforce SSH)
# ---------------------------------------------------------------------------
log "Configuration de fail2ban"
cat > /etc/fail2ban/jail.local <<'EOF'
[DEFAULT]
backend = systemd
bantime = 1h
findtime = 10m
maxretry = 5

[sshd]
enabled = true
EOF
systemctl enable --now fail2ban
systemctl restart fail2ban

# ---------------------------------------------------------------------------
# 6. Mises à jour de sécurité automatiques
# ---------------------------------------------------------------------------
log "Activation des mises à jour de sécurité automatiques"
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
EOF

# ---------------------------------------------------------------------------
# 7. Docker (dépôt officiel)
# ---------------------------------------------------------------------------
if ! command -v docker >/dev/null 2>&1; then
    log "Installation de Docker"
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc

    CODENAME="$(. /etc/os-release && echo "$VERSION_CODENAME")"
    # Docker publie parfois le dépôt d'une nouvelle stable avec du retard :
    # si trixie n'existe pas encore côté Docker, on retombe sur bookworm (compatible).
    if ! curl -fsIL "https://download.docker.com/linux/debian/dists/${CODENAME}/Release" >/dev/null 2>&1; then
        warn "Dépôt Docker '${CODENAME}' indisponible, bascule sur 'bookworm'."
        CODENAME="bookworm"
    fi

    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian ${CODENAME} stable" \
        > /etc/apt/sources.list.d/docker.list
    apt-get update
    apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
else
    log "Docker déjà installé"
fi

usermod -aG docker "$ADMIN_USER"

# Rotation des logs conteneurs : sans ça, /var/log finit par saturer les 100 Go.
cat > /etc/docker/daemon.json <<'EOF'
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "3" }
}
EOF
systemctl restart docker
systemctl enable docker

# ---------------------------------------------------------------------------
# 8. Nginx natif (reverse proxy unique) + Certbot
# ---------------------------------------------------------------------------
log "Installation de Nginx + Certbot"
apt-get install -y nginx certbot python3-certbot-nginx apache2-utils
rm -f /etc/nginx/sites-enabled/default
systemctl enable --now nginx
nginx -t && systemctl reload nginx

log "Terminé."
cat <<EOF

Prochaines étapes :
  1. Déconnecte-toi / reconnecte-toi pour que le groupe 'docker' prenne effet.
  2. Pointe un sous-domaine (ex. preprod.tondomaine.fr) vers l'IP du VPS.
  3. Déploie la stack : infra/deploy-preprod.sh

Vérifications utiles :
  ufw status verbose
  fail2ban-client status sshd
  docker run --rm hello-world
EOF
