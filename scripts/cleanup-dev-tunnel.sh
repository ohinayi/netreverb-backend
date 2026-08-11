#!/usr/bin/env bash

set -u

ssh -T \
  -i "${NETREVERB_TUNNEL_KEY:-$HOME/.ssh/netreverb-kamailio-tunnel}" \
  -o BatchMode=yes \
  -o ConnectTimeout=10 \
  deploy@sip.classyra.com.ng \
  'set -u
   ss -Hlnpt 2>/dev/null | awk "/127\\.0\\.0\\.1:8001/ {print}" |
   sed -n "s/.*pid=\\([0-9][0-9]*\\).*/\\1/p" |
   while read -r pid; do
     [ -n "$pid" ] || continue
     user=$(ps -o user= -p "$pid" 2>/dev/null | tr -d " ")
     command=$(ps -o comm= -p "$pid" 2>/dev/null | tr -d " ")
     if [ "$user" = "deploy" ] && [ "$command" = "sshd-session" ]; then
       kill "$pid" 2>/dev/null || true
       echo "Removed stale development reverse tunnel (PID $pid)."
     fi
   done'

exit 0
