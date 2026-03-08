#!/bin/sh
# AmneziaWG OPNsense Plugin Installer
# Obfuscated WireGuard (bypass DPI) for OPNsense 25.x / FreeBSD 14.x
#
# Usage:
#   sh install.sh            — install
#   sh install.sh uninstall  — remove

set -e
set -u

PLUGIN_VERSION="2.3.2"
PLUGIN_DIR="$(dirname "$0")/plugin"
VERSION_FILE="/usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/version.txt"

# ─────────────────────────────────────────────────────────────────────────────
# HELPERS
# ─────────────────────────────────────────────────────────────────────────────
warn() { echo "[WARN] $*" >&2; }
die()  { echo "[ERROR] $*" >&2; exit 1; }

# ─────────────────────────────────────────────────────────────────────────────
# UNINSTALL
# ─────────────────────────────────────────────────────────────────────────────
if [ "${1:-}" = "uninstall" ]; then
    echo "==> Stopping AmneziaWG..."
    /usr/local/opnsense/scripts/AmneziaWG/amneziawg-service-control.php stop 2>/dev/null || true

    echo "==> Removing plugin files..."
    rm -f  /usr/local/opnsense/scripts/AmneziaWG/amneziawg-service-control.php
    rmdir  /usr/local/opnsense/scripts/AmneziaWG 2>/dev/null || true
    rm -f  /usr/local/opnsense/service/conf/actions.d/actions_amneziawg.conf
    rm -rf /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG  # includes version.txt
    rm -f  /usr/local/etc/amnezia/private.key
    rm -f  /usr/local/etc/amnezia/*.conf
    rmdir  /usr/local/etc/amnezia 2>/dev/null || true
    rm -rf /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG
    rm -rf /usr/local/opnsense/mvc/app/views/OPNsense/AmneziaWG
    rm -f  /usr/local/etc/inc/plugins.inc.d/amneziawg.inc
    rm -f  /etc/newsyslog.conf.d/amneziawg.conf
    rm -f  /usr/local/etc/rc.syshook.d/start/50-amneziawg

    echo "==> Restarting configd..."
    service configd restart

    echo "==> Clearing cache..."
    rm -f /var/lib/php/tmp/opnsense_menu_cache.xml

    echo ""
    echo "=============================="
    echo "  AmneziaWG plugin removed."
    echo "=============================="
    echo "Refresh browser with Ctrl+F5."
    exit 0
fi

# ─────────────────────────────────────────────────────────────────────────────
# INSTALL
# ─────────────────────────────────────────────────────────────────────────────

# ─────────────────────────────────────────────────────────────────────────────
# VERSION CHECK & CONFIRMATION
# ─────────────────────────────────────────────────────────────────────────────
CURRENT_VERSION="not installed"
if [ -f "$VERSION_FILE" ]; then
    CURRENT_VERSION=$(cat "$VERSION_FILE" 2>/dev/null || echo "unknown")
fi

echo "============================================================"
echo "  os-amneziawg plugin installer"
echo "============================================================"
echo ""
echo "  Current version : ${CURRENT_VERSION}"
echo "  New version     : ${PLUGIN_VERSION}"
echo ""

if [ "$CURRENT_VERSION" = "$PLUGIN_VERSION" ]; then
    echo "  Version ${PLUGIN_VERSION} is already installed."
    printf "  Reinstall? [y/N] "
    read -r _CONFIRM < /dev/tty 2>/dev/null || _CONFIRM="n"
    case "$_CONFIRM" in
        [yY]*) ;;
        *) echo "  Installation cancelled."; exit 0 ;;
    esac
elif [ "$CURRENT_VERSION" != "not installed" ]; then
    printf "  Upgrade from ${CURRENT_VERSION} to ${PLUGIN_VERSION}? [Y/n] "
    read -r _CONFIRM < /dev/tty 2>/dev/null || _CONFIRM="y"
    case "$_CONFIRM" in
        [nN]*) echo "  Installation cancelled."; exit 0 ;;
        *) ;;
    esac
else
    printf "  Install version ${PLUGIN_VERSION}? [Y/n] "
    read -r _CONFIRM < /dev/tty 2>/dev/null || _CONFIRM="y"
    case "$_CONFIRM" in
        [nN]*) echo "  Installation cancelled."; exit 0 ;;
        *) ;;
    esac
fi

echo ""

# ─────────────────────────────────────────────────────────────────────────────
# FreeBSD QUARTERLY REPO HELPER
#
# Пакеты amnezia-kmod и amnezia-tools отсутствуют в репозитории OPNsense,
# но есть в FreeBSD quarterly. Создаём временный конфиг репо для pkg,
# устанавливаем пакеты и удаляем конфиг. URL не содержит хэшей — pkg сам
# резолвит нужную версию по имени пакета.
# ─────────────────────────────────────────────────────────────────────────────
FREEBSD_REPO_CONF="/usr/local/etc/pkg/repos/freebsd-quarterly.conf"
FREEBSD_REPO_CREATED=0

setup_freebsd_repo() {
    if [ -f "$FREEBSD_REPO_CONF" ]; then
        echo "[OK]  FreeBSD quarterly repo already configured"
        return 0
    fi
    install -d /usr/local/etc/pkg/repos
    cat > "$FREEBSD_REPO_CONF" << 'REPOEOF'
FreeBSD-quarterly: {
    url: "pkg+http://pkg.FreeBSD.org/${ABI}/quarterly",
    mirror_type: "srv",
    signature_type: "fingerprints",
    fingerprints: "/usr/share/keys/pkg",
    enabled: yes
}
REPOEOF
    FREEBSD_REPO_CREATED=1
    echo "[OK]  Temporary FreeBSD quarterly repo configured"
}

cleanup_freebsd_repo() {
    if [ "$FREEBSD_REPO_CREATED" = "1" ]; then
        rm -f "$FREEBSD_REPO_CONF"
        echo "[OK]  Temporary FreeBSD repo config removed"
    fi
}

echo "==> Step 1: Checking AmneziaWG packages..."

NEED_KMOD=0
NEED_TOOLS=0

if [ ! -x /usr/local/bin/awg ]; then
    NEED_TOOLS=1
fi
if ! kldstat -q -m if_amn 2>/dev/null; then
    NEED_KMOD=1
fi

if [ "$NEED_KMOD" = "1" ] || [ "$NEED_TOOLS" = "1" ]; then
    echo ""
    echo "  Missing packages detected:"
    [ "$NEED_TOOLS" = "1" ] && echo "    - amnezia-tools (awg, awg-quick)"
    [ "$NEED_KMOD" = "1" ]  && echo "    - amnezia-kmod  (if_amn kernel module)"
    echo ""
    printf "  Install from FreeBSD quarterly repo? [Y/n] "
    read -r _REPLY < /dev/tty 2>/dev/null || _REPLY="y"
    case "$_REPLY" in
        [nN]*)
            echo ""
            warn "Skipping package install. Plugin will be installed but"
            warn "AmneziaWG will NOT start until packages are in place."
            echo ""
            echo "  Manual install:"
            echo "    pkg install -r FreeBSD-quarterly amnezia-kmod amnezia-tools"
            echo "    kldload if_amn"
            echo "    echo 'if_amn_load=\"YES\"' >> /boot/loader.conf"
            ;;
        *)
            setup_freebsd_repo
            pkg update -r FreeBSD-quarterly 2>/dev/null || warn "pkg update failed — trying install anyway"

            if [ "$NEED_KMOD" = "1" ]; then
                echo "  Installing amnezia-kmod..."
                if pkg install -y -r FreeBSD-quarterly amnezia-kmod 2>/dev/null; then
                    echo "[OK]  amnezia-kmod installed"
                    kldload if_amn 2>/dev/null || true
                    grep -q 'if_amn_load' /boot/loader.conf 2>/dev/null || \
                        echo 'if_amn_load="YES"' >> /boot/loader.conf
                else
                    warn "Failed to install amnezia-kmod via pkg."
                    echo "       Try manually: pkg add <URL from pkg.freebsd.org>"
                fi
            fi

            if [ "$NEED_TOOLS" = "1" ]; then
                echo "  Installing amnezia-tools..."
                if pkg install -y -r FreeBSD-quarterly amnezia-tools 2>/dev/null; then
                    echo "[OK]  amnezia-tools installed"
                else
                    warn "Failed to install amnezia-tools via pkg."
                    echo "       Try manually: pkg add <URL from pkg.freebsd.org>"
                fi
            fi

            cleanup_freebsd_repo
            ;;
    esac
else
    echo "[OK]  awg: $(awg --version 2>/dev/null || echo 'installed')"
    echo "[OK]  if_amn kernel module loaded"
fi

# Final binary check
BINARIES_OK=1
[ ! -x /usr/local/bin/awg ] && BINARIES_OK=0
kldstat -q -m if_amn 2>/dev/null || BINARIES_OK=0

if [ "$BINARIES_OK" = "0" ]; then
    echo ""
    warn "One or more binaries/modules are still missing."
    warn "Plugin will be installed but AmneziaWG will NOT start."
fi

echo ""
echo "==> Step 2: Installing plugin files..."

install -d /usr/local/opnsense/scripts/AmneziaWG
install -m 0755 "$PLUGIN_DIR/scripts/AmneziaWG/amneziawg-service-control.php" \
                /usr/local/opnsense/scripts/AmneziaWG/

install -m 0644 "$PLUGIN_DIR/service/conf/actions.d/actions_amneziawg.conf" \
                /usr/local/opnsense/service/conf/actions.d/

install -d /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/Menu
install -m 0644 "$PLUGIN_DIR/mvc/app/models/OPNsense/AmneziaWG/General.xml" \
                /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/
install -m 0644 "$PLUGIN_DIR/mvc/app/models/OPNsense/AmneziaWG/General.php" \
                /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/
install -m 0644 "$PLUGIN_DIR/mvc/app/models/OPNsense/AmneziaWG/Instance.xml" \
                /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/
install -m 0644 "$PLUGIN_DIR/mvc/app/models/OPNsense/AmneziaWG/Instance.php" \
                /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/
install -m 0644 "$PLUGIN_DIR/mvc/app/models/OPNsense/AmneziaWG/Menu/Menu.xml" \
                /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/Menu/

# IMP-6: install ACL definitions for API endpoints
install -d /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/ACL
install -m 0644 "$PLUGIN_DIR/mvc/app/models/OPNsense/AmneziaWG/ACL/ACL.xml" \
                /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/ACL/

# Write version file
echo "$PLUGIN_VERSION" > "$VERSION_FILE"
chmod 0644 "$VERSION_FILE"

install -d /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG/Api
install -d /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG/forms
install -m 0644 "$PLUGIN_DIR/mvc/app/controllers/OPNsense/AmneziaWG/IndexController.php" \
                /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG/
install -m 0644 "$PLUGIN_DIR/mvc/app/controllers/OPNsense/AmneziaWG/Api/GeneralController.php" \
                /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG/Api/
install -m 0644 "$PLUGIN_DIR/mvc/app/controllers/OPNsense/AmneziaWG/Api/InstanceController.php" \
                /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG/Api/
install -m 0644 "$PLUGIN_DIR/mvc/app/controllers/OPNsense/AmneziaWG/Api/ServiceController.php" \
                /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG/Api/
install -m 0644 "$PLUGIN_DIR/mvc/app/controllers/OPNsense/AmneziaWG/Api/ImportController.php" \
                /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG/Api/
install -m 0644 "$PLUGIN_DIR/mvc/app/controllers/OPNsense/AmneziaWG/forms/general.xml" \
                /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG/forms/
install -m 0644 "$PLUGIN_DIR/mvc/app/controllers/OPNsense/AmneziaWG/forms/instance.xml" \
                /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG/forms/

install -d /usr/local/opnsense/mvc/app/views/OPNsense/AmneziaWG
install -m 0644 "$PLUGIN_DIR/mvc/app/views/OPNsense/AmneziaWG/general.volt" \
                /usr/local/opnsense/mvc/app/views/OPNsense/AmneziaWG/

install -m 0644 "$PLUGIN_DIR/etc/inc/plugins.inc.d/amneziawg.inc" \
                /usr/local/etc/inc/plugins.inc.d/

# SEC-6: install newsyslog config for log rotation (max 1MB, 5 archives, gzip)
install -d /etc/newsyslog.conf.d
install -m 0644 "$PLUGIN_DIR/etc/newsyslog.conf.d/amneziawg.conf" \
                /etc/newsyslog.conf.d/

install -d -m 0700 /usr/local/etc/amnezia

# MED-2: install rc.syshook for autostart on boot
install -d /usr/local/etc/rc.syshook.d/start
install -m 0755 "$PLUGIN_DIR/etc/rc.syshook.d/start/50-amneziawg" \
                /usr/local/etc/rc.syshook.d/start/

echo "[OK]  Plugin files installed."

echo ""
echo "==> Step 3: Restarting configd..."
service configd restart

echo ""
echo "==> Step 4: Clearing cache..."
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
rm -f /var/lib/php/tmp/PHP_errors.log

echo ""
echo "============================================================"
echo "  os-amneziawg v${PLUGIN_VERSION} installed!"
echo "============================================================"
echo ""
echo "  Check version:  configctl amneziawg version"
echo ""
echo "  Quick start:"
echo "  1. Refresh browser (Ctrl+F5) → VPN → AmneziaWG"
echo "  2. Instance tab → fill in your tunnel settings"
echo "     or use 'Import .conf' to paste your .conf file"
echo "  3. General tab → check 'Enable AmneziaWG'"
echo "  4. Click Apply"
echo ""
echo "  Selective routing (route only specific IPs via VPN):"
echo "  5. Interfaces → Assignments → add awg0, enable it"
echo "  6. System → Gateways → Add"
echo "     Interface: AWG, Gateway IP: <tunnel peer IP>"
echo "     Far Gateway: on, Disable monitoring: on"
echo "  7. Firewall → Aliases → Add (list IPs/networks for VPN)"
echo "  8. Firewall → Rules → LAN → Add"
echo "     Destination: <your alias>, Gateway: AWG_GW"
echo ""
echo "  To uninstall: sh install.sh uninstall"
echo ""
