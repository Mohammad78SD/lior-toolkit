#!/bin/bash
#
# Lior Auto-Print - remover. Double-click to stop and delete the print agent.
# (First time, macOS may block it - right-click -> Open -> Open.)

set -u

LABEL="com.lior.printagent"
PLIST="$HOME/Library/LaunchAgents/$LABEL.plist"
DEST="$HOME/Library/Application Support/lior-print-agent"

printf '\n\033[1mRemoving the Lior Auto-Print agent...\033[0m\n'

launchctl unload "$PLIST" 2>/dev/null || true
launchctl bootout "gui/$(id -u)/$LABEL" 2>/dev/null || true
rm -f "$PLIST"
rm -rf "$DEST"
rm -f "$HOME/.lior-printed-ids"
rm -rf /tmp/lior-print-agent.lock

printf '  done.\n\n'
printf 'Logs were kept at ~/Library/Logs/lior-print-agent*.log\n'
printf 'Press Return to close.'
read -r _
