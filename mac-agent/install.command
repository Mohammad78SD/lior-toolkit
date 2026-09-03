#!/bin/bash
#
# Lior Auto-Print - installer for the office Mac.
#
# HOW TO USE: double-click this file. (First time, macOS may block it -
# right-click it -> Open -> Open.) It offers to install anything missing
# (Homebrew, jq, Google Chrome), then asks two questions and the printer.
#
# The macOS account must be an Administrator (installing Homebrew/Chrome asks
# for the Mac password). Re-running is safe - it updates settings and restarts.

set -u

SRC_DIR="$(cd "$(dirname "$0")" 2>/dev/null && pwd)"
DEST="$HOME/Library/Application Support/lior-print-agent"
LABEL="com.lior.printagent"
PLIST="$HOME/Library/LaunchAgents/$LABEL.plist"
LOG="$HOME/Library/Logs/lior-print-agent.log"
DEFAULT_URL="https://lior-jewellery.com"

say()  { printf '\n\033[1m%s\033[0m\n' "$*"; }
ok()   { printf '  \033[32mOK\033[0m  %s\n' "$*"; }
warn() { printf '  \033[33m!\033[0m   %s\n' "$*"; }
die()  { printf '\n\033[31mSTOPPED: %s\033[0m\n\n' "$*"; printf 'Press Return to close.'; read -r _; exit 1; }

say "Lior Auto-Print installer"

[ "$(uname)" = "Darwin" ] || die "This installer is for macOS only."
[ -f "$SRC_DIR/print-agent.sh" ] || die "print-agent.sh is not in the same folder as this installer. Keep the whole folder together and try again."

# ---------------------------------------------------------------------------
# 1. Requirements  (Homebrew, jq, Google Chrome)
# ---------------------------------------------------------------------------
say "1. Requirements"

# Pick up Homebrew if it's installed but not on PATH yet (fresh Terminal).
if ! command -v brew >/dev/null 2>&1; then
	for b in /opt/homebrew/bin/brew /usr/local/bin/brew; do
		[ -x "$b" ] && eval "$("$b" shellenv)" && break
	done
fi

find_chrome() {
	CHROME=""
	for p in \
		"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
		"$HOME/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
		"/Applications/Chromium.app/Contents/MacOS/Chromium" \
		"/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge"
	do
		if [ -x "$p" ]; then CHROME="$p"; return 0; fi
	done
	return 1
}

need_brew=0; need_jq=0; need_chrome=0
command -v brew >/dev/null 2>&1 || need_brew=1
command -v jq   >/dev/null 2>&1 || need_jq=1
find_chrome || need_chrome=1

if [ $need_jq -eq 0 ]; then ok "jq is installed"; fi
if [ $need_chrome -eq 0 ]; then ok "Browser for making PDFs: $(basename "$CHROME")"; fi

if [ $need_brew -eq 1 ] || [ $need_jq -eq 1 ] || [ $need_chrome -eq 1 ]; then
	missing=""
	[ $need_brew -eq 1 ]   && missing="$missing Homebrew"
	[ $need_jq -eq 1 ]     && missing="$missing jq"
	[ $need_chrome -eq 1 ] && missing="$missing 'Google Chrome'"
	warn "Missing:$missing"
	printf "  Install the missing items now? Asks for your Mac password, may take a few minutes. [Y/n]: "
	read -r ans
	case "${ans:-Y}" in
		[Nn]*) die "Nothing installed. Install$missing yourself, then run this again." ;;
	esac

	if [ $need_brew -eq 1 ]; then
		say "Installing Homebrew (follow its prompts)..."
		/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)" \
			|| die "Homebrew install failed."
		for b in /opt/homebrew/bin/brew /usr/local/bin/brew; do
			[ -x "$b" ] && eval "$("$b" shellenv)" && break
		done
		command -v brew >/dev/null 2>&1 || die "Homebrew installed but not found on PATH. Close Terminal, re-open, run this again."
		ok "Homebrew installed"
	fi

	if [ $need_jq -eq 1 ]; then
		say "Installing jq..."
		brew install jq || die "'brew install jq' failed."
		ok "jq installed"
	fi

	if [ $need_chrome -eq 1 ]; then
		say "Installing Google Chrome..."
		brew install --cask google-chrome || die "Chrome install failed. Install it from https://www.google.com/chrome/ then run this again."
		find_chrome || die "Chrome installed but not found. Open it once, then run this again."
		ok "Chrome installed: $(basename "$CHROME")"
	fi
fi

command -v jq >/dev/null 2>&1 || die "jq still missing."
find_chrome    || die "A browser for PDF rendering is still missing."

# ---------------------------------------------------------------------------
# 2. Settings
# ---------------------------------------------------------------------------
say "2. Settings"

printf "  Website address [%s]: " "$DEFAULT_URL"
read -r SITE_URL
SITE_URL="${SITE_URL:-$DEFAULT_URL}"
SITE_URL="${SITE_URL%/}"

printf "  Paste the API key (WordPress Admin -> Tools -> Lior Auto-Print): "
read -r PRINT_KEY
[ -n "$PRINT_KEY" ] || die "The API key is required."

# ---------------------------------------------------------------------------
# 3. Printer
# ---------------------------------------------------------------------------
say "3. Printer"

i=0
PRINTERS=""
while IFS= read -r name; do
	i=$((i + 1))
	PRINTERS="$PRINTERS$name
"
	printf "  %d) %s\n" "$i" "$name"
done < <(lpstat -p 2>/dev/null | awk '/^printer /{print $2}')

PRINTER_QUEUE=""
if [ "$i" -eq 0 ]; then
	warn "No printers set up on this Mac yet."
	warn "Add the printer in System Settings -> Printers & Scanners, then re-run this installer."
	warn "For now the agent will use whatever the system default printer is."
else
	printf "  0) (use the system default printer)\n"
	printf "  Pick a number [0]: "
	read -r choice
	choice="${choice:-0}"
	if [ "$choice" != "0" ]; then
		PRINTER_QUEUE="$(printf '%s' "$PRINTERS" | sed -n "${choice}p")"
		[ -n "$PRINTER_QUEUE" ] || die "That wasn't one of the choices."
		ok "Using printer: $PRINTER_QUEUE"
	else
		ok "Using the system default printer"
	fi
fi

# ---------------------------------------------------------------------------
# 4. Test the connection to the website
# ---------------------------------------------------------------------------
say "4. Testing the connection"

code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 \
	-H "X-Lior-Key: $PRINT_KEY" "$SITE_URL/wp-json/lior/v1/print-queue" 2>/dev/null)"

case "$code" in
	200) ok "Website reachable and the key works." ;;
	401|403) die "The website answered but rejected the key (HTTP $code).
Re-copy it from WordPress Admin -> Tools -> Lior Auto-Print and run this again." ;;
	404) die "The print endpoint wasn't found (HTTP 404).
On the website: Settings -> Permalinks -> Save Changes, then run this again." ;;
	000) die "Couldn't reach $SITE_URL.
Check this Mac's internet connection and that the address is right." ;;
	*)   warn "Unexpected response (HTTP $code). Carrying on anyway - check the log later." ;;
esac

# ---------------------------------------------------------------------------
# 5. Install
# ---------------------------------------------------------------------------
say "5. Installing"

mkdir -p "$DEST" "$HOME/Library/LaunchAgents" "$(dirname "$LOG")"

cp "$SRC_DIR/print-agent.sh" "$DEST/print-agent.sh"
chmod 755 "$DEST/print-agent.sh"

( umask 077
cat > "$DEST/config.sh" <<EOF
# Written by install.command on $(date).
# Edit values here, then run:  launchctl kickstart -k gui/\$(id -u)/$LABEL
SITE_URL="$SITE_URL"
PRINT_KEY="$PRINT_KEY"
PRINTER_QUEUE="$PRINTER_QUEUE"
CHROME="$CHROME"
EOF
)
chmod 600 "$DEST/config.sh"
ok "Agent + settings written to:"
printf "      %s\n" "$DEST"

cat > "$PLIST" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>Label</key><string>$LABEL</string>
	<key>ProgramArguments</key>
	<array>
		<string>/bin/bash</string>
		<string>$DEST/print-agent.sh</string>
	</array>
	<key>RunAtLoad</key><true/>
	<key>StartInterval</key><integer>20</integer>
	<key>StandardOutPath</key><string>$HOME/Library/Logs/lior-print-agent.out.log</string>
	<key>StandardErrorPath</key><string>$HOME/Library/Logs/lior-print-agent.err.log</string>
	<key>ProcessType</key><string>Background</string>
</dict>
</plist>
EOF

launchctl unload "$PLIST" 2>/dev/null || true
launchctl load -w "$PLIST" || die "Couldn't start the background agent (launchctl load failed)."
ok "Background agent started - runs at login and every 20 seconds."

# ---------------------------------------------------------------------------
# 6. Test run
# ---------------------------------------------------------------------------
say "6. Test run"
/bin/bash "$DEST/print-agent.sh"
sleep 1
echo "  --- last few log lines ---"
tail -n 12 "$LOG" 2>/dev/null | sed 's/^/  /' || echo "  (log not written yet)"

# ---------------------------------------------------------------------------
say "All set."
cat <<EOF

  Try it: on the website, move an order to "Processing". Within about
  20 seconds it prints here and the order's Print column shows "Printed".

  Log file:        ~/Library/Logs/lior-print-agent.log
  Change settings: run this installer again
  Remove it:       double-click  uninstall.command

EOF
printf 'Press Return to close.'
read -r _
