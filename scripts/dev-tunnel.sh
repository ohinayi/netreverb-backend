#!/usr/bin/env bash

# Runs as its own script file (not inlined in composer.json) so the
# ${VAR:-default} lookups below are evaluated once, in one real shell
# context, per retry loop iteration. Inlined in composer.json's script
# string they sit inside a double-quoted npx-concurrently argument, so
# the outer shell expands them immediately at parse time instead - a
# stale snapshot, not a live per-iteration read.

set -u

port="${NETREVERB_TUNNEL_REMOTE_PORT:-}"
if [ -z "$port" ] && [ -f .env ]; then
  port=$(grep -m1 '^NETREVERB_TUNNEL_REMOTE_PORT=' .env | cut -d= -f2)
fi
port="${port:-8001}"

key="${NETREVERB_TUNNEL_KEY:-$HOME/.ssh/netreverb-kamailio-tunnel}"

while true; do
  ssh -NT -i "$key" \
    -o ExitOnForwardFailure=yes \
    -o ServerAliveInterval=30 \
    -o ServerAliveCountMax=3 \
    -o TCPKeepAlive=yes \
    -o ConnectTimeout=15 \
    -L 127.0.0.1:3307:127.0.0.1:3306 \
    -L 127.0.0.1:8021:127.0.0.1:8021 \
    -L 127.0.0.1:18081:127.0.0.1:8081 \
    -L 127.0.0.1:7880:127.0.0.1:7880 \
    -L 127.0.0.1:6379:127.0.0.1:6379 \
    -R "127.0.0.1:${port}:127.0.0.1:8000" \
    deploy@sip.classyra.com.ng
  printf '%s\n' 'Tunnel disconnected, retrying in 5 seconds...'
  sleep 5
done
