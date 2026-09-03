# Lior Auto-Print — office Mac agent

This folder is **not** part of the WordPress plugin. WordPress ignores it. It
runs on the Mac in the office and does the actual printing.

## What it does

Every ~20 seconds it asks lior-jewellery.com for orders queued to print
(orders that just reached "processing" / paid), renders each to an A4 PDF with
headless Chrome, sends it to the printer, and tells the site it's done.

All traffic is **outbound HTTPS** — nothing needs to reach the Mac from
outside, so there are no router or firewall changes. If the Mac is off or
offline, orders wait on the server and all print when it comes back.

## One-time setup

### 1. Install dependencies

- **Google Chrome** — https://www.google.com/chrome/
- **jq** — install Homebrew (https://brew.sh) then:

  ```sh
  brew install jq
  ```

### 2. Add the printer

Plug in / add the printer in **System Settings → Printers & Scanners**. Print a
macOS test page to confirm it works. Then find its queue name:

```sh
lpstat -p
```

e.g. `printer HP_LaserJet_M15w is idle.` → the queue name is `HP_LaserJet_M15w`.
Leave `PRINTER_QUEUE` blank in the script to use the Mac's default printer.

### 3. Install the agent files

```sh
sudo mkdir -p /Users/Shared/lior-print-agent
sudo cp print-agent.sh /Users/Shared/lior-print-agent/
sudo chmod +x /Users/Shared/lior-print-agent/print-agent.sh
sudo chown "$(whoami)" /Users/Shared/lior-print-agent/print-agent.sh
```

### 4. Configure it

Edit `/Users/Shared/lior-print-agent/print-agent.sh` and set the three values at
the top:

| Variable        | Value                                                                 |
|-----------------|----------------------------------------------------------------------|
| `SITE_URL`      | `https://lior-jewellery.com`                                          |
| `PRINT_KEY`     | Copy from **WP Admin → Tools → Lior Auto-Print** (the "API key" box)  |
| `PRINTER_QUEUE` | Queue name from `lpstat -p`, or leave blank for the default printer   |

### 5. Test by hand

```sh
/bin/bash /Users/Shared/lior-print-agent/print-agent.sh
tail -n 40 ~/Library/Logs/lior-print-agent.log
```

Place a test order on the site and move it to **Processing** (or use an existing
processing order and click **Reprint** on the orders list). Run the script
again — a page should print and the order's Print column should flip to
`Printed`.

### 6. Run it automatically

```sh
cp com.lior.printagent.plist ~/Library/LaunchAgents/
launchctl load -w ~/Library/LaunchAgents/com.lior.printagent.plist
```

It now runs at login and every 20 seconds. Confirm:

```sh
launchctl list | grep lior
```

## Everyday checks

- **Log:** `~/Library/Logs/lior-print-agent.log`
- **Is the site seeing the agent?** WP Admin → Tools → Lior Auto-Print → "Last
  agent poll" should say a few seconds ago.
- If orders sit unprinted for 20+ min and the agent is quiet, the site emails
  the admin address.

## Updating the key

If the key is regenerated in WP Admin, edit `print-agent.sh`, paste the new
`PRINT_KEY`, then:

```sh
launchctl kickstart -k gui/$(id -u)/com.lior.printagent
```

## Uninstall

```sh
launchctl unload -w ~/Library/LaunchAgents/com.lior.printagent.plist
rm ~/Library/LaunchAgents/com.lior.printagent.plist
sudo rm -rf /Users/Shared/lior-print-agent
rm -f ~/.lior-printed-ids
```

## Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| Log: `could not fetch print queue` | Mac offline, wrong `SITE_URL`, or site behind Basic Auth / firewall. |
| HTTP 403 in the log | `PRINT_KEY` doesn't match the one in WP Admin → Tools → Lior Auto-Print. |
| Log: `jq not installed` | `brew install jq`. |
| Log: `Google Chrome not found` | Install Chrome, or fix the `CHROME` path in the script. |
| `PDF render failed` | Chrome is running as another user / blocked. Try running the script by hand while logged in. |
| `lp failed` | Wrong `PRINTER_QUEUE`, printer offline, or out of paper. Check `lpstat -p` and the print queue. |
| Nothing runs automatically | `launchctl list \| grep lior` — reload the plist; check `agent.err.log`. |
| Prints twice | The `done` callback failed after printing; the local `~/.lior-printed-ids` guard prevents a real second print. Safe to ignore unless it repeats. |

## Switching to a thermal receipt printer later

Only two changes, no rework of this agent:

1. In the WP module `includes/modules/auto-print-orders.php`, change the
   `@page` size in `lior_print_render_receipt()` to `80mm auto` and collapse the
   billing/shipping blocks to one column.
2. Set `PRINTER_QUEUE` here to the thermal printer's queue name.
