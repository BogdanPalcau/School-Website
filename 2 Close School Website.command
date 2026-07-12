#!/bin/bash
# CLOSE the school website (double-click this).
# Stops the background server. No typing needed.

set +e
cd "$(dirname "$0")"
ROOT="$(pwd)"
PID_FILE="$ROOT/.portal-server.pid"
PORT_FILE="$ROOT/.portal-server.port"

notify() {
  /usr/bin/osascript -e "display notification \"$1\" with title \"School Website\"" >/dev/null 2>&1 || true
}

close_terminal() {
  /usr/bin/osascript >/dev/null 2>&1 <<'APPLESCRIPT' || true
tell application "Terminal"
  try
    close front window
  end try
end tell
APPLESCRIPT
}

if [[ -f "$PID_FILE" ]]; then
  PID="$(cat "$PID_FILE" 2>/dev/null || true)"
  if [[ -n "${PID:-}" ]] && kill -0 "$PID" 2>/dev/null; then
    kill "$PID" 2>/dev/null || true
    sleep 0.4
    kill -9 "$PID" 2>/dev/null || true
    notify "School website closed."
  else
    notify "School website was already closed."
  fi
else
  notify "School website was already closed."
fi

rm -f "$PID_FILE" "$PORT_FILE"
close_terminal
exit 0
