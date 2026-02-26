#!/bin/sh
# AmneziaWG OPNsense Plugin Installer
# Obfuscated WireGuard (bypass DPI) for OPNsense 25.x / FreeBSD 14.x
#
# Usage:
#   sh install.sh            — install
#   sh install.sh uninstall  — remove

set -e

PLUGIN_DIR="$(dirname "$0")/plugin"
AWG_KMOD="https://pkg.freebsd.org/FreeBSD:14:amd64/quarterly/All/Hashed/amnezia-kmod-2.0.10.1403000~eed49ec054.pkg"
AWG_TOOLS="https://pkg.freebsd.org/FreeBSD:14:amd64/quarterly/All/Hashed/amnezia-tools-1.0.20250903~ca774170ae.pkg"

# ─────────────────────────────────────────────────────────────────────────────
# UNINSTALL
# ─────────────────────────────────────────────────────────────────────────────
if [ "$1" = "uninstall" ]; then
    echo "==> Stopping AmneziaWG..."
    /usr/local/opnsense/scripts/AmneziaWG/amneziawg-service-control.php stop 2>/dev/null || true

    echo "==> Removing plugin files..."
    rm -f  /usr/local/opnsense/scripts/AmneziaWG/amneziawg-service-control.php
    rmdir  /usr/local/opnsense/scripts/AmneziaWG 2>/dev/null || true
    rm -f  /usr/local/opnsense/service/conf/actions.d/actions_amneziawg.conf
    rm -rf /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG
    rm -f  /usr/local/etc/amnezia/private.key
    rm -rf /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG
    rm -rf /usr/local/opnsense/mvc/app/views/OPNsense/AmneziaWG
    rm -f  /usr/local/etc/inc/plugins.inc.d/amneziawg.inc
    rm -f  /etc/newsyslog.conf.d/amneziawg.conf

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

echo "==> Step 1: Installing AmneziaWG packages..."
pkg add "$AWG_KMOD" || true
pkg add "$AWG_TOOLS" || true

echo "==> Step 2: Loading kernel module..."
kldload if_amn 2>/dev/null || true
grep -q 'if_amn_load' /boot/loader.conf || echo 'if_amn_load="YES"' >> /boot/loader.conf

echo "==> Step 3: Verifying binaries..."
awg --version || { echo "ERROR: awg not found after install!"; exit 1; }
echo "[OK] awg: $(awg --version)"

echo "==> Step 4: Installing plugin files..."

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

echo "==> Step 5: Restarting configd..."
service configd restart

echo "==> Step 6: Clearing cache..."
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
rm -f /var/lib/php/tmp/PHP_errors.log

echo ""
echo "============================================================"
echo "  AmneziaWG plugin installed!"
echo "============================================================"
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
