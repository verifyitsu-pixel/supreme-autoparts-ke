#!/bin/sh
set -eu
mode=${1:-}
base=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
dest=/etc/systemd/network/10-netplan-eth0.network.d/90-store-resolvers.conf
state=/var/lib/store-deploy/host-dns
[ "$(id -u)" -eq 0 ] || exit 1
case "$mode" in
 apply)
  [ ! -e "$dest" ] || { echo 'Existing override: inspect before deploying'; exit 1; }
  [ ! -e "$state/applied.sha256" ] || exit 1
  networkctl status eth0 --no-pager | grep -q '/run/systemd/network/10-netplan-eth0.network'
  [ "$(resolvectl dns eth0 | sed 's/^[^:]*: //')" = '195.179.224.53 209.126.15.53' ] || { echo 'DNS baseline changed'; exit 1; }
  mkdir -p "$state"; chmod 700 "$state"
  cp -p /etc/netplan/50-cloud-init.yaml "$state/netplan.before"
  resolvectl status > "$state/resolved.before"
  ip -j address show dev eth0 > "$state/address.before.json"
  ip -j route show > "$state/routes.before.json"
  mkdir -p "$(dirname "$dest")"
  install -m 644 "$base/90-store-resolvers.conf" "$dest"
  sha256sum "$dest" | cut -d' ' -f1 > "$state/applied.sha256"
  # Apply DNS alone. No network restart/reconfigure, address/route or record edit.
  resolvectl dns eth0 1.1.1.1 8.8.8.8
  resolvectl flush-caches
  resolvectl dns eth0
  cmp /etc/netplan/50-cloud-init.yaml "$state/netplan.before"
  ip -j address show dev eth0 > "$state/address.after.json"
  ip -j route show > "$state/routes.after.json"
  cmp "$state/routes.before.json" "$state/routes.after.json"
  echo 'DNS override installed; runtime DNS applied. Probes require independent verification.'
 ;;
 rollback)
  [ -f "$state/applied.sha256" ] && [ -f "$dest" ]
  [ "$(sha256sum "$dest" | cut -d' ' -f1)" = "$(cat "$state/applied.sha256")" ] || exit 1
  resolvectl dns eth0 195.179.224.53 209.126.15.53
  rm "$dest" "$state/applied.sha256"
  resolvectl flush-caches
  echo 'Prior DNS restored; persistent override removed.'
 ;;
 *) echo 'Use apply or rollback'; exit 2 ;;
esac
