#!/bin/bash
# OPEN the school website (double-click this).
# Installs PHP if needed, starts the server, opens your browser. No typing needed.
# To shut it down later, double-click:  2 Close School Website.command

set +e
set -u

cd "$(dirname "$0")"
ROOT="$(pwd)"
PORT="${PORTAL_PORT:-8011}"
PID_FILE="$ROOT/.portal-server.pid"
LOG_FILE="$ROOT/.portal-server.log"
URL="http://127.0.0.1:${PORT}"

export HOMEBREW_NO_ANALYTICS=1
export HOMEBREW_NO_ENV_HINTS=1
export NONINTERACTIVE=1
export CI=1

notify() {
  /usr/bin/osascript -e "display notification \"$1\" with title \"School Website\"" >/dev/null 2>&1 || true
}

alert() {
  /usr/bin/osascript -e "display alert \"School Website\" message \"$1\" as critical buttons {\"OK\"} default button \"OK\"" >/dev/null 2>&1 || true
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

have_cmd() {
  command -v "$1" >/dev/null 2>&1
}

load_brew() {
  if [[ -x /opt/homebrew/bin/brew ]]; then
    eval "$(/opt/homebrew/bin/brew shellenv)"
  elif [[ -x /usr/local/bin/brew ]]; then
    eval "$(/usr/local/bin/brew shellenv)"
  fi
}

port_in_use() {
  lsof -nP -iTCP:"$1" -sTCP:LISTEN >/dev/null 2>&1
}

pick_port() {
  local try
  for try in 8011 8012 8013 8080 8888 9000; do
    if ! port_in_use "$try"; then
      PORT="$try"
      URL="http://127.0.0.1:${PORT}"
      return 0
    fi
  done
  return 1
}

wait_for_server() {
  local i
  for i in $(seq 1 60); do
    if curl -fsS "$URL/" >/dev/null 2>&1 || curl -fsS "$URL/login.php" >/dev/null 2>&1; then
      return 0
    fi
    sleep 0.25
  done
  return 1
}

open_browser() {
  open "$URL" >/dev/null 2>&1 || open -a Safari "$URL" >/dev/null 2>&1 || true
}

find_php() {
  PHP_BIN=""
  if [[ -x /opt/homebrew/bin/php ]]; then
    PHP_BIN="/opt/homebrew/bin/php"
  elif [[ -x /usr/local/bin/php ]]; then
    PHP_BIN="/usr/local/bin/php"
  elif have_cmd php; then
    PHP_BIN="$(command -v php)"
  fi
}

php_ok() {
  [[ -n "${PHP_BIN:-}" && -x "$PHP_BIN" ]] || return 1
  local major
  major="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION;' 2>/dev/null || echo 0)"
  [[ "$major" -ge 8 ]] || return 1
  "$PHP_BIN" -m 2>/dev/null | grep -qi '^pdo_sqlite$' || return 1
  return 0
}

ensure_php() {
  find_php
  if php_ok; then
    return 0
  fi

  notify "First-time setup… this can take a few minutes. If Mac asks for a password, use your Mac login password."

  load_brew
  if ! have_cmd brew; then
    /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)" </dev/null
    load_brew
  fi

  if ! have_cmd brew; then
    alert "Setup could not finish (Homebrew missing). Ask Bogdan for help."
    close_terminal
    exit 1
  fi

  brew install php </dev/null
  load_brew
  find_php

  if ! php_ok; then
    alert "PHP could not be installed automatically. Ask Bogdan for help."
    close_terminal
    exit 1
  fi
}

# --- already running: just reopen browser ---------------------------------

if [[ -f "$PID_FILE" ]]; then
  OLD_PID="$(cat "$PID_FILE" 2>/dev/null || true)"
  if [[ -n "${OLD_PID:-}" ]] && kill -0 "$OLD_PID" 2>/dev/null; then
    if [[ -f "$ROOT/.portal-server.port" ]]; then
      PORT="$(cat "$ROOT/.portal-server.port")"
      URL="http://127.0.0.1:${PORT}"
    fi
    notify "Website is already running — opening it now."
    open_browser
    close_terminal
    exit 0
  fi
  rm -f "$PID_FILE" "$ROOT/.portal-server.port"
fi

if ! pick_port; then
  alert "Could not find a free port. Restart the Mac and try again, or ask Bogdan."
  close_terminal
  exit 1
fi

ensure_php
mkdir -p "$ROOT/database" "$ROOT/uploads"

# Detach so Terminal can close; Stop script kills this PID later
: > "$LOG_FILE"
nohup "$PHP_BIN" -S "127.0.0.1:${PORT}" -t "$ROOT/public" "$ROOT/scripts/dev-router.php" \
  >"$LOG_FILE" 2>&1 &
echo $! > "$PID_FILE"
echo "$PORT" > "$ROOT/.portal-server.port"
disown $! 2>/dev/null || true

if ! wait_for_server; then
  alert "The website did not start. Ask Bogdan for help."
  if [[ -f "$PID_FILE" ]]; then
    kill "$(cat "$PID_FILE")" 2>/dev/null || true
    rm -f "$PID_FILE" "$ROOT/.portal-server.port"
  fi
  close_terminal
  exit 1
fi

# Create DB on first run
curl -fsS "$URL/" >/dev/null 2>&1 || curl -fsS "$URL/login.php" >/dev/null 2>&1 || true

PW_FILE="$ROOT/database/INITIAL_OWNER_PASSWORD.txt"
if [[ -f "$PW_FILE" ]]; then
  PW="$(tr -d '\r\n' < "$PW_FILE")"
  /usr/bin/osascript >/dev/null 2>&1 <<APPLESCRIPT || true
display dialog "Your website is ready.

Sign in with:
Username: bogdan
Password: ${PW}

Save or change this password after you sign in." with title "School Website" buttons {"Open Website"} default button "Open Website"
APPLESCRIPT
fi

notify "Website is ready."
open_browser
close_terminal
exit 0
