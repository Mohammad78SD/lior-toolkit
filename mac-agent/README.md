# Lior Auto-Print — office Mac agent

This folder is **not** part of the WordPress plugin. It runs on the Mac in the
office and does the actual printing.

## What it does

Every ~20 seconds it asks the website for orders queued to print (orders that
just reached "processing" / paid), renders each to an A4 PDF with headless
Chrome, sends it to the printer, and tells the site it's done.

All traffic is **outbound HTTPS** — nothing connects *in* to the Mac, so there
are no router or firewall changes. If the Mac is off or offline, orders wait on
the server and all print when it comes back.

---

## Easy install (hand this to the client)

1. Make sure the Mac has:
   - **Google Chrome** — https://www.google.com/chrome/
   - **Homebrew** — https://brew.sh (only needed if `jq` isn't already there;
     the installer installs `jq` itself if Homebrew is present)
   - the **printer added** in System Settings → Printers & Scanners (print a
     macOS test page first)
2. Copy this whole `mac-agent` folder to the Mac (e.g. the Desktop).
3. Get the **API key** from the website: WordPress Admin → Tools → Lior
   Auto-Print.
4. Double-click **`install.command`**.
   - First time, macOS blocks files from the internet: **right-click
     `install.command` → Open → Open**.
5. Answer the prompts:
   - Website address (press Return for `https://lior-jewellery.com`)
   - Paste the API key
   - Pick the printer from the numbered list (or `0` for the system default)
6. It tests the connection, installs itself, starts the background agent, and
   does one test run. Done.

Re-run `install.command` any time to change the settings. Double-click
`uninstall.command` to remove it.

**Test it:** on the website move an order to **Processing**. Within ~20s a page
prints and the order's Print column shows `Printed`. WordPress Admin → Tools →
Lior Auto-Print should show "Last agent poll" a few seconds ago.

---

## Where things end up

| Path | What |
|---|---|
| `~/Library/Application Support/lior-print-agent/print-agent.sh` | the agent |
| `~/Library/Application Support/lior-print-agent/config.sh` | settings (URL, key, printer) — chmod 600 |
| `~/Library/LaunchAgents/com.lior.printagent.plist` | runs it at login + every 20s |
| `~/Library/Logs/lior-print-agent.log` | what it's doing |

Change a setting by hand: edit `config.sh`, then

```sh
launchctl kickstart -k gui/$(id -u)/com.lior.printagent
```

---

## Everyday checks

- **Log:** `~/Library/Logs/lior-print-agent.log`
- **Is the site seeing the agent?** WordPress Admin → Tools → Lior Auto-Print →
  "Last agent poll" should say seconds/minutes ago.
- If orders sit unprinted for 20+ min while the agent is quiet, the site emails
  the WordPress admin address.

---

## Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| `install.command` won't open | Right-click it → Open → Open (Gatekeeper, first run only). |
| Installer: "rejected the key (HTTP 403)" | Re-copy the key from WP Admin → Tools → Lior Auto-Print. |
| Installer: "endpoint wasn't found (HTTP 404)" | On the site: Settings → Permalinks → Save Changes. Then re-run. |
| Installer: "Couldn't reach ..." | Mac offline, or wrong website address. |
| Log: `jq not installed` | Install Homebrew, then re-run the installer. |
| Log: `Google Chrome not found` | Install Chrome, then re-run the installer. |
| Log: `no PRINT_KEY set` | Run `install.command` again. |
| Log: `lp failed` | Wrong printer, printer offline, or out of paper. Check `lpstat -p` and the macOS print queue. |
| Nothing prints automatically | `launchctl list \| grep lior` — if missing, re-run the installer. Check `~/Library/Logs/lior-print-agent.err.log`. |
| Prints twice | The "done" callback failed after printing once; the local `~/.lior-printed-ids` guard stops a real second copy. Ignore unless it repeats. |

---

## Manual install (without the installer)

1. `brew install jq`, install Google Chrome, add the printer.
2. Copy `print-agent.sh` somewhere stable, e.g.
   `~/Library/Application Support/lior-print-agent/`.
3. Next to it create `config.sh`:

   ```sh
   SITE_URL="https://lior-jewellery.com"
   PRINT_KEY="paste-key-here"
   PRINTER_QUEUE=""     # blank = default printer, or a name from: lpstat -p
   CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
   ```

   `chmod 600 config.sh` (it holds the key).
4. Test: `/bin/bash "~/Library/Application Support/lior-print-agent/print-agent.sh"`
   then `tail ~/Library/Logs/lior-print-agent.log`.
5. Autorun: copy `com.lior.printagent.plist` to `~/Library/LaunchAgents/`
   (fix the path inside it to match step 2), then
   `launchctl load -w ~/Library/LaunchAgents/com.lior.printagent.plist`.

---

## Switching to a thermal receipt printer later

Two changes, no rework of this agent:

1. In `includes/modules/auto-print-orders.php` on the site, change the `@page`
   size in `lior_print_render_receipt()` to `80mm auto` and collapse the
   billing/shipping blocks to one column.
2. Re-run `install.command` and pick the thermal printer.
