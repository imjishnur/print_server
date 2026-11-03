# Print Server – Setup Guide

Print Server is a small Windows app that receives print jobs from the POS and sends
them to the receipt printers. It replaces Laragon + `print_server` for new clients and prints
exactly the same KOT and bill layout.

- No Laragon, PHP or printer sharing needed
- Starts automatically when Windows starts, runs in the tray (no window)
- Works over `http://` and `https://`

---

## 1. Install on the client PC

1. Copy **`PrintServer-Setup-<version>.exe`** to the PC.
2. **Stop Laragon / XAMPP** if it is installed. Only one program can use ports 80 and 443.
3. Double-click the installer.
   - "Windows protected your PC" → **More info → Run anyway** (shown until the app is code-signed).
   - "Do you want to allow this app to make changes?" → **Yes**.
4. Click **Install → Finish** (keep **Start Print Server** ticked).
5. A **blue printer icon** appears near the clock. Done.

The installer:

| Step | Result |
|---|---|
| Installs the app | `C:\Program Files\PrintServer\PrintServer.exe` |
| Auto-start | Runs at every login, minimized to the tray |
| Firewall | Opens ports 80 and 443 so tablets/phones can print |
| HTTPS | Trusts the app's certificate, so `https://` shows the padlock |
| Port check | Warns if another program already uses port 80/443 |

> If the installer says port 80 or 443 is in use, stop or uninstall that program
> (Laragon, XAMPP, IIS, Skype), then use **Start server** from the tray menu.

---

## 2. Connect the POS

1. On the PC, open **`http://localhost/print_server/`**.
   The status page shows this PC's IP (for example `192.168.18.130`) and its printers.
2. In the web app go to **Settings → Printers V2**.
3. Enter the PC's IP as the print server, click **Detect**, pick the printer, save.
   (Same as with Laragon – the address `/print_server/restpos_printer_server.php` is unchanged.)
4. On the status page press **Test print** next to the printer to check it.
5. On the first print from each PC / tablet / phone, Chrome may ask the POS website for
   permission to **access devices on your local network** (shown as a box under the address bar).
   Press **Allow**. This is Chrome asking, not Print Server; Laragon clients get the same
   question. If it was blocked, see *"Allow" was blocked* in Troubleshooting.

---

## 3. Everyday use

The app runs in the **tray** (near the clock; if hidden, click the **^** arrow).

| Icon | Meaning |
|---|---|
| Blue | Running, ready to print |
| Red | Not receiving prints (port in use, or server stopped) |

Right-click the icon:

```
HTTP :80 running | HTTPS :443 running
──────────────
Open status page     printers, test print, IP address
Open print log       last print jobs: JSON sent + receipt preview
Open logs folder
──────────────
Stop server          frees ports 80/443 (becomes "Start server")
Quit                 closes the app – printing stops
```

**Print log:** tray → **Open print log**, or `http://localhost/print_server/log` on the print PC.
Shows the last 100 print jobs (KOT / bill / shift, order number, printer, printed or failed).
Click a job to see:

| Tab | Shows |
|---|---|
| Preview | How the receipt looks on paper, drawn from the exact bytes sent to the printer (logo, sizes, Arabic, cut, cash drawer) |
| JSON sent | The data the POS sent (`content_data`), with a Copy button |
| Response | What Print Server answered the POS |
| Printer bytes | Hex dump of the ESC/POS bytes |

The log is kept in memory only (cleared when the app restarts) and opens only on the print PC
itself, because it contains customer names and orders.

**Start it again after Quit:** Start menu → **Print Server**, or restart the PC.

**Turn auto-start on/off:** Task Manager → **Startup apps** → **PrintServer**.

---

## 4. HTTPS and tablets / phones

- On the PC itself, HTTPS works right after installing (restart Chrome once).
- **Each tablet or phone** that prints over HTTPS needs the certificate once:
  1. On the device open `http://<PC-IP>/print_server/`
  2. Tap **print-server-ca.crt** and install it (Android: Settings → Security → Install certificate → CA certificate; iPhone/iPad: install the profile, then Settings → General → About → Certificate Trust Settings → turn it on).

The certificate only works for local addresses (localhost and 192.168.x.x / 10.x.x.x /
172.16–31.x.x), so it cannot be misused for real websites.

---

## 5. Troubleshooting

| Problem | Fix |
|---|---|
| No tray icon | Start menu → **Print Server**. Check the **^** hidden icons. |
| Red icon | Another program uses port 80/443. Stop Laragon/XAMPP/IIS/Skype, then tray → **Start server**. |
| `ERR_CERT_AUTHORITY_INVALID` / "Your connection isn't private" | The certificate is not trusted on this device. On the PC: reinstall with the installer and restart Chrome. On tablets: install `print-server-ca.crt` (section 4). |
| Page opens on the PC but not from a tablet | Same Wi-Fi? Firewall rule "Print Server" present? (The installer adds it.) Try `http://<PC-IP>/print_server/` on the tablet. |
| Printer not in the list | Install the printer driver in Windows first; it must appear in Windows **Printers & scanners**. |
| Test print fails | Printer on, paper loaded, cable/network OK. Check **Open logs folder → agent.log**. |
| *"Allow" was blocked* – POS says it cannot reach the print server, but the status page opens | Chrome is blocking the POS site from the local network. On the POS page tap the icon left of the address → **Site settings** → **Local network access** → **Allow**, then reload. Same on tablets and phones. |
| Receipt looks wrong / missing data | Tray → **Open print log**, click the job: compare **JSON sent** (what the POS sent) with **Preview** (what was printed). |
| Cash drawer does not open | Bills open it unless the POS sends "No". KOTs never open it. |
| Logo missing on bill | Put the logo at `C:\ProgramData\PrintServer\logo\logo.png`, or the app uses the logo from the POS. |

**Logs:** `C:\ProgramData\PrintServer\logs\agent.log` (tray → Open logs folder).

---

## 6. Settings (optional)

`C:\ProgramData\PrintServer\config.json` – restart the app after editing.

| Setting | Default | Meaning |
|---|---|---|
| `http_port` / `https_port` | `80` / `443` | Ports (the POS expects 80/443) |
| `enable_https` | `true` | HTTPS on/off |
| `default_printer` | `POS-80C` | Fallback printer name |
| `big_store_name` | `true` | Large bold store name (same as Laragon `$BIG_STORE_NAME`) |
| `enable_bill_logo` / `enable_kot_logo` | `true` / `false` | Logo on bill / KOT |
| `default_logo_mode` | `esc_star` | `esc_star` or `gs_v0` (try `gs_v0` if the logo prints badly) |
| `use_batch_logo` | `true` | Use the POS logo when there is no local `logo.png` |

---

## 7. Moving a Laragon client to Print Server (optional)

Nothing changes in the web app.

1. Stop Laragon and remove it from Windows startup.
2. Copy `C:\laragon\www\print_server\logo\logo.png` to `C:\ProgramData\PrintServer\logo\logo.png` (if they use a logo).
3. If they changed settings at the top of `printer_load.php` (e.g. `$BIG_STORE_NAME`), set the same in `config.json`.
4. Run the installer, then do a test print from the POS.

To go back: uninstall Print Server and start Laragon again.

---

## 8. Uninstall

**Settings → Apps → Print Server → Uninstall.**
Removes the app, auto-start, firewall rule and certificate. Settings and logs stay in
`C:\ProgramData\PrintServer` (delete the folder to remove them).

---

## For developers / testing without the installer

After `.\build.ps1` the app is `dist\PrintServer.exe` in the repo folder.
Double-click it – no window opens, only the tray icon. It does **not** set up auto-start,
firewall or HTTPS trust. To trust HTTPS on this PC, run in an **administrator** PowerShell
from the repo folder:

```powershell
& ".\dist\PrintServer.exe" --install-cert
# undo:
& ".\dist\PrintServer.exe" --uninstall-cert
```

Auto-start for your user only (no admin), from the repo folder:

```powershell
reg add "HKCU\Software\Microsoft\Windows\CurrentVersion\Run" /v PrintServer /t REG_SZ /d "\"$PWD\dist\PrintServer.exe\"" /f
# undo:
reg delete "HKCU\Software\Microsoft\Windows\CurrentVersion\Run" /v PrintServer /f
```

Building and tests: see [README.md](README.md).
