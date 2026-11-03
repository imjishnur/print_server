# Print Server

Lightweight Windows print agent that replaces the Laragon print server for new clients.

It serves the same URL as Laragon, `http(s)://<PC-IP>/print_server/restpos_printer_server.php`,
and prints the same ESC/POS bytes, so the POS and Printers V2 work with no change.
Existing Laragon clients are not affected.

Installing and using it on a client PC: see [SETUP-GUIDE.md](SETUP-GUIDE.md).

| Request | Same as |
|---|---|
| `?action=get_printers` | Printers V2 → Detect |
| `content_data` + `print_type` KOT / BILL / INVOICE / SHIFT | `include/printer_load.php` (v1) |
| `image_base64` | `include/printer_load_v2.php` (v2) |

Differences from Laragon:
- Prints through the Windows spooler by printer name or share name; printer sharing is not required.
- HTTPS on 443 with a local CA (trusted by the installer), plus Chrome Private Network Access headers.
- One job at a time per printer, so jobs from several tablets do not mix.
- Starts at login with a tray icon; status page at `http://localhost/print_server/`.

## Files on a client PC

- `C:\Program Files\PrintServer\PrintServer.exe`, `fonts\ae_AlHor.ttf`
- `C:\ProgramData\PrintServer\config.json` – ports, default printer, and the switches from the top of `printer_load.php`
- `C:\ProgramData\PrintServer\logo\logo.png` – optional; same as Laragon's `print_server\logo\logo.png`. Without it the logo sent by the app is used (`use_batch_logo`).
- `C:\ProgramData\PrintServer\logs\agent.log`
- `C:\ProgramData\PrintServer\certs\print-server-ca.crt` – install on tablets that call it over HTTPS (download from the status page)

## Develop

```powershell
go test ./...                                      # golden byte tests against the PHP output
go run . --console --http-port 8080 --https-port 8443
.\build.ps1 -Version 0.1.0                          # dist\PrintServer.exe (+ installer if Inno Setup 6 is installed)
```

Use other ports while Laragon is running on 80/443.

## Tests

```powershell
go test ./...                                         # everything below except load/fuzz (about 1 s)
go test -run XXX -bench . -benchmem                   # time and memory per receipt
$env:LOAD=1; go test -run TestLoad -v                 # 20 tablets x 25 jobs over real HTTP
go test -run XXX -fuzz=FuzzV1Receipts -fuzztime=60s   # random print data must never crash
go test -run XXX -fuzz=FuzzV2Images -fuzztime=60s
```

| File | Covers |
|---|---|
| `escpos_test.go` | Receipt bytes vs the real PHP output (golden files), incl. POS beta pictures |
| `server_test.go` | The HTTP contract the POS uses (multipart, form, raw JSON, v2), fallback order and errors like PHP, CORS/PNA, get_printers, print log access, 60 parallel jobs, a real TCP network printer, image-size guard, logo from `logo_base64` |
| `certs_test.go` | HTTPS certificate valid for localhost/LAN IPs, CA cannot sign public sites, CA kept across restarts |
| `fuzz_test.go` | Broken JSON and images |
| `perf_test.go` | Benchmarks and the load test |

Printers are replaced by fakes in the tests (`Agent.winSend` / `sockSend`), so nothing is printed.

## Golden tests

`testdata/v1/*.json` and `testdata/v2/*.json` are sample print jobs. The expected bytes
(`*.bin`) are produced by the real PHP print server:

```powershell
php tools\golden\gen.php C:\laragon\www\print_server
```

The print server is not modified; patched copies are written to the temp folder.
Text receipts must match byte for byte. Arabic lines, logos and resized images are drawn by
a different engine (Windows GDI / Go vs GD) and are checked for structure only.
When `printer_load.php` changes, port the change and regenerate the golden files.
