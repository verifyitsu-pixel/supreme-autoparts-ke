# Checkout incident host DNS repair

Host upstream DNS timeout evidence Nov9 2026: 52,336 resolver timeouts; direct A lookups to both original uplinks timed out. Successful checkout-to-cart chain with DNS bypass took0.95s, normal host lookup chain10.9s with10.0sname lookup. Blackbox followed cart redirect then exceeded unchanged15s deadline. Independent Cloudflare/Google A/AAAA queries returned current public records quickly.

Git-tracked deployment replaces only eth0 DNS via networkd drop-in plus resolvectl runtime API. It does not run netplan apply, restart networking/WordPress/database, change public records, routes, addresses, alert rules, timeouts, payment or catalog code. Original netplan remains unchanged. The networkd override survives host reboot and regeneration of the same10-netplan-eth0.network file; changed interface/generated filename requires review. No reboot proof is claimed.

Run deploy.sh apply only after comparing exact live baseline. Private rollback and before/after evidence are retained under /var/lib/store-deploy/host-dns. deploy.sh rollback checks installed hash and restores original runtime uplinks. Follow with direct A/AAAA, unchanged blackbox probe, scheduled Prometheus/Grafana state, browser checkout no-submit and public store health. Alert clearing alone is not checkout/payment proof.

Official semantics: https://www.freedesktop.org/software/systemd/man/latest/systemd.network.html and https://www.freedesktop.org/software/systemd/man/latest/resolvectl.html . Drop-ins are parsed after the generated file; empty DNS resets preceding servers.
