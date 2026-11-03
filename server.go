//go:build windows

package main

import (
	"encoding/json"
	"fmt"
	"html/template"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"strconv"
	"strings"
	"time"
)

const printPath = "/print_server/restpos_printer_server.php"

func (a *Agent) routes() *http.ServeMux {
	mux := http.NewServeMux()
	mux.HandleFunc(printPath, a.handlePrint)
	mux.HandleFunc("/print_server/print-server-ca.crt", a.handleCA)
	mux.HandleFunc("/print_server/agent/status", a.handleStatus)
	mux.HandleFunc("/print_server/agent/test", a.handleTest)
	mux.HandleFunc("GET /print_server/log", localOnly(a.handleLogPage))
	mux.HandleFunc("GET /print_server/agent/jobs", localOnly(a.handleJobs))
	mux.HandleFunc("GET /print_server/agent/jobs/{id}", localOnly(a.handleJob))
	mux.HandleFunc("/print_server/", a.handleHome)
	mux.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/" {
			http.NotFound(w, r)
			return
		}
		http.Redirect(w, r, "/print_server/", http.StatusFound)
	})
	return mux
}

// setCORS sends the same headers as restpos_printer_server.php, plus Chrome's
// Private Network Access header so an https site may call this local server.
func setCORS(w http.ResponseWriter, r *http.Request) {
	h := w.Header()
	h.Set("Access-Control-Allow-Origin", "*")
	h.Set("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
	h.Set("Access-Control-Allow-Headers", "Content-Type, Authorization, X-Requested-With, X-API-KEY")
	h.Set("Access-Control-Allow-Credentials", "true")
	if r.Header.Get("Access-Control-Request-Private-Network") == "true" {
		h.Set("Access-Control-Allow-Private-Network", "true")
	}
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

type obj = map[string]any

func (a *Agent) handlePrint(w http.ResponseWriter, r *http.Request) {
	setCORS(w, r)
	if r.Method == http.MethodOptions {
		w.WriteHeader(http.StatusOK)
		return
	}

	post := map[string]string{}
	var raw []byte
	ct := r.Header.Get("Content-Type")
	switch {
	case strings.HasPrefix(ct, "multipart/form-data"):
		if err := r.ParseMultipartForm(64 << 20); err == nil {
			for k, v := range r.MultipartForm.Value {
				post[k] = v[0]
			}
		}
	case strings.HasPrefix(ct, "application/x-www-form-urlencoded"):
		if err := r.ParseForm(); err == nil {
			for k, v := range r.PostForm {
				post[k] = v[0]
			}
		}
	default:
		raw, _ = io.ReadAll(io.LimitReader(r.Body, 64<<20))
	}

	action := r.URL.Query().Get("action")
	if action == "" {
		action = post["action"]
	}
	if action == "get_printers" || action == "detect_printers" {
		a.handleGetPrinters(w, r)
		return
	}

	job := newJob(r, post, raw)

	var content batch
	if v, ok := post["content_data"]; ok && v != "" {
		content = decodeBatch([]byte(v))
	} else if len(raw) > 0 {
		content = decodeBatch(raw)
	}

	// V2: image already drawn by the browser
	imageB64, hasImage := post["image_base64"]
	if !hasImage && content != nil {
		imageB64 = content.str("image_base64")
	}
	if imageB64 != "" {
		data := content
		if data == nil {
			data = batch{}
			for k, v := range post {
				data[k] = v
			}
		}
		printType := firstSet(post, data, "print_type", "BILL")
		job.Kind, job.PrintType, job.OrderNo = "v2", printType, data.str("sale_no_p")
		job.image = imageB64
		a.printV2(w, job, imageB64, data, printType)
		return
	}

	if content == nil {
		if r.Method == http.MethodGet && strings.Contains(r.Header.Get("Accept"), "text/html") {
			// Opened in a browser tab (e.g. the "Allow Cert" button): show that it works
			a.handleHome(w, r)
			return
		}
		job.Kind = "v1"
		a.reply(w, job, http.StatusBadRequest, obj{"success": false, "error": "No content_data or image_base64 received"})
		return
	}

	printType := firstSet(post, content, "print_type", "KOT")
	job.Kind, job.PrintType, job.OrderNo = "v1", printType, content.str("sale_no_p")
	a.printV1(w, job, content, printType)
}

func printerLabel(path, ip string, port int) string {
	switch {
	case path != "" && ip != "":
		return path + " / " + ip + ":" + strconv.Itoa(port)
	case ip != "":
		return ip + ":" + strconv.Itoa(port)
	}
	return path
}

// firstSet is $postData[$key] ?? ($data[$key] ?? $default).
func firstSet(post map[string]string, data batch, key, def string) string {
	if v, ok := post[key]; ok {
		return v
	}
	if v, ok := data.get(key); ok {
		return phpStr(v)
	}
	return def
}

func intOr(v any, def int) int {
	if n, err := strconv.Atoi(phpStr(v)); err == nil && n != 0 {
		return n
	}
	return def
}

func (a *Agent) printV1(w http.ResponseWriter, job *printJob, d batch, printType string) {
	printerIP := d.str("printer_ip_address")
	path := d.str("path")
	printerType := phpStr(d.pick("windows", "type"))
	connectionType := phpStr(d.pick("popup", "connection_type"))
	port := intOr(d.pick(nil, "printer_port"), 9100)
	job.Printer = printerLabel(path, printerIP, port)

	var commands []byte
	switch printType {
	case "KOT":
		commands = a.v1.KOT(d)
	case "BILL", "INVOICE":
		commands = a.v1.Bill(d)
	case "SHIFT":
		a.printShift(w, job, d, printerIP, path, printerType, connectionType, port)
		return
	default:
		// printer_load.php prints nothing for other types
		job.Error = "print_type " + printType + " is not printed by v1"
		job.DurationMs = time.Since(job.start).Milliseconds()
		a.jobs.add(job)
		w.WriteHeader(http.StatusOK)
		return
	}
	job.output = commands

	log.Printf("v1 %s order=%s path=%q ip=%q", printType, d.str("sale_no_p"), path, printerIP)
	var errorMsg string

	if path != "" && printerType == "windows" {
		if err := a.printWindows(path, commands); err == nil {
			res := obj{"success": true, "print_type": printType, "method": "windows_share"}
			if printType == "KOT" {
				res["path"] = path
			}
			a.reply(w, job, http.StatusOK, res)
			return
		} else {
			log.Printf("windows print %q failed: %v", path, err)
		}
	}

	if printerIP != "" {
		if err := a.printSocket(printerIP, port, commands, time.Second); err == nil {
			res := obj{"success": true, "print_type": printType, "printer_ip": printerIP, "method": "socket"}
			if printType == "KOT" {
				res["port"] = port
			}
			a.reply(w, job, http.StatusOK, res)
			return
		} else {
			errorMsg = "Socket failed: " + err.Error()
			log.Printf("socket print %s:%d failed: %v", printerIP, port, err)
		}
	}

	if connectionType == "popup" || connectionType == "" {
		if err := a.printWindows(a.cfg.DefaultPrinter, commands); err == nil {
			a.reply(w, job, http.StatusOK, obj{"success": true, "print_type": printType, "method": "popup", "printer": a.cfg.DefaultPrinter})
			return
		}
	}

	if errorMsg == "" || printType != "KOT" {
		errorMsg = "Could not connect to printer"
	}
	a.reply(w, job, http.StatusOK, obj{"success": false, "error": errorMsg})
}

func (a *Agent) printShift(w http.ResponseWriter, job *printJob, d batch, printerIP, path, printerType, connectionType string, port int) {
	commands := a.v1.Shift(d)
	job.output = commands
	var errorMsg string

	if printerIP != "" && connectionType == "php_print_server" {
		if err := a.printSocket(printerIP, port, commands, 5*time.Second); err == nil {
			a.reply(w, job, http.StatusOK, obj{"success": true, "print_type": "SHIFT", "printer_ip": printerIP, "port": port, "method": "socket"})
			return
		} else {
			errorMsg = "Socket failed: " + err.Error()
		}
	}
	if path != "" && printerType == "windows" {
		if err := a.printWindows(path, commands); err == nil {
			a.reply(w, job, http.StatusOK, obj{"success": true, "print_type": "SHIFT", "method": "windows_share", "path": path})
			return
		}
	}
	if connectionType == "popup" || connectionType == "" {
		target := path
		if target == "" {
			target = a.cfg.DefaultPrinter
		}
		if err := a.printWindows(target, commands); err == nil {
			a.reply(w, job, http.StatusOK, obj{"success": true, "print_type": "SHIFT", "method": "popup", "printer": target})
			return
		}
	}
	if errorMsg == "" {
		errorMsg = "Could not connect to printer"
	}
	a.reply(w, job, http.StatusOK, obj{"success": false, "error": errorMsg})
}

func (a *Agent) printV2(w http.ResponseWriter, job *printJob, imageB64 string, d batch, printType string) {
	printerIP := phpStr(d.pick("", "printer_ip", "printer_ip_address"))
	port := intOr(d.pick(nil, "printer_port"), 9100)
	path := phpStr(d.pick("", "path", "printer_name"))
	openDrawer := d.str("open_cash_drawer") == "Yes" || printType == "BILL" || printType == "INVOICE"
	job.Printer = printerLabel(path, printerIP, port)

	commands, err := buildV2(imageB64, openDrawer)
	if err != nil {
		a.reply(w, job, http.StatusBadRequest, obj{"success": false, "error": err.Error()})
		return
	}
	job.output = commands
	log.Printf("v2 %s path=%q ip=%q bytes=%d", printType, path, printerIP, len(commands))

	if printerIP != "" {
		if err := a.printSocket(printerIP, port, commands, 2*time.Second); err == nil {
			a.reply(w, job, http.StatusOK, obj{"success": true, "v2": true, "print_type": printType, "printer_ip": printerIP, "port": port, "method": "socket"})
			return
		}
	}
	if path != "" {
		if err := a.printWindows(path, commands); err == nil {
			a.reply(w, job, http.StatusOK, obj{"success": true, "v2": true, "print_type": printType, "path": path, "method": "windows_share"})
			return
		} else {
			log.Printf("windows print %q failed: %v", path, err)
		}
	}
	if err := a.printWindows(a.cfg.DefaultPrinter, commands); err == nil {
		a.reply(w, job, http.StatusOK, obj{"success": true, "v2": true, "print_type": printType, "path": a.cfg.DefaultPrinter, "method": "popup_fallback"})
		return
	}
	a.reply(w, job, http.StatusOK, obj{"success": false, "v2": true,
		"error": "Could not deliver print job to printer (Socket failed, Windows share inaccessible)"})
}

type printerJSON struct {
	Name      string `json:"name"`
	Shared    bool   `json:"shared"`
	ShareName string `json:"share_name"`
	Status    string `json:"status"`
}

func printersJSON() []printerJSON {
	printers, err := listPrinters()
	if err != nil {
		log.Printf("list printers: %v", err)
	}
	out := make([]printerJSON, 0, len(printers))
	for _, p := range printers {
		status := "Normal"
		if p.Status == 0 {
			status = "Ready"
		}
		out = append(out, printerJSON{Name: p.Name, Shared: p.Shared, ShareName: p.ShareName, Status: status})
	}
	return out
}

// serverIP is the address the request came in on, or the first LAN IP for localhost.
func serverIP(r *http.Request) string {
	if addr, ok := r.Context().Value(http.LocalAddrContextKey).(net.Addr); ok {
		if host, _, err := net.SplitHostPort(addr.String()); err == nil {
			if ip := net.ParseIP(host); ip != nil && !ip.IsLoopback() {
				if ip4 := ip.To4(); ip4 != nil {
					return ip4.String()
				}
				return ip.String()
			}
		}
	}
	if ips := lanIPs(); len(ips) > 0 {
		return ips[0].String()
	}
	return "127.0.0.1"
}

func (a *Agent) handleGetPrinters(w http.ResponseWriter, r *http.Request) {
	printers := printersJSON()
	writeJSON(w, http.StatusOK, obj{"success": true, "server_ip": serverIP(r), "count": len(printers), "printers": printers})
}

func (a *Agent) handleCA(w http.ResponseWriter, r *http.Request) {
	certPath, _ := caPaths(a.dir)
	data, err := os.ReadFile(certPath)
	if err != nil {
		http.Error(w, "HTTPS is not set up", http.StatusNotFound)
		return
	}
	w.Header().Set("Content-Type", "application/x-x509-ca-cert")
	w.Header().Set("Content-Disposition", `attachment; filename="print-server-ca.crt"`)
	_, _ = w.Write(data)
}

func (a *Agent) statusData(r *http.Request) obj {
	ips := []string{}
	for _, ip := range lanIPs() {
		ips = append(ips, ip.String())
	}
	a.mu.Lock()
	httpSt, httpsSt := a.http, a.https
	a.mu.Unlock()
	return obj{
		"version":   version,
		"http":      httpSt,
		"https":     httpsSt,
		"ips":       ips,
		"server_ip": serverIP(r),
		"printers":  printersJSON(),
		"data_dir":  a.dir,
	}
}

func (a *Agent) handleStatus(w http.ResponseWriter, r *http.Request) {
	setCORS(w, r)
	writeJSON(w, http.StatusOK, a.statusData(r))
}

// handleTest prints a short test ticket; from this PC or any tablet/phone on the
// network (the print endpoint is open to them anyway).
func (a *Agent) handleTest(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "POST only", http.StatusMethodNotAllowed)
		return
	}
	name := r.FormValue("printer")
	ticket := fmt.Sprintf("\x1B@\x1Ba\x01\x1B!\x38PRINT SERVER\x0A\x1B!\x00%s\x0A%s\x0A%s\x0ATest print OK\x0A%s\x0A\x1Bd\x05\x1DV\x00",
		eqLine, name, time.Now().Format("02-01-2006 03:04 PM"), eqLine)
	// Logged like a POS job so the Print Log shows test prints too
	job := newJob(r, map[string]string{"printer": name}, nil)
	job.Kind, job.PrintType, job.Printer, job.output = "test", "TEST", name, []byte(ticket)
	if err := a.printWindows(name, job.output); err != nil {
		a.reply(w, job, http.StatusOK, obj{"success": false, "error": err.Error()})
		return
	}
	a.reply(w, job, http.StatusOK, obj{"success": true, "print_type": "TEST", "method": "windows"})
}

var homeTmpl = template.Must(template.New("home").Parse(`<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Print Server</title>
<style>
body{font-family:Segoe UI,Arial,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:24px}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:18px 20px;max-width:720px;margin:0 auto 16px}
h1{font-size:20px;margin:0 0 4px} h2{font-size:15px;margin:0 0 10px}
.ok{color:#15803d;font-weight:600} .err{color:#b91c1c;font-weight:600} .muted{color:#64748b;font-size:13px}
code{background:#f1f5f9;padding:2px 6px;border-radius:4px}
table{width:100%;border-collapse:collapse;font-size:14px} td,th{text-align:left;padding:6px 4px;border-bottom:1px solid #f1f5f9}
button{background:#2563eb;color:#fff;border:0;border-radius:6px;padding:5px 12px;cursor:pointer}
details{margin:6px 0;font-size:14px} summary{cursor:pointer;padding:4px 0} ol,ul{padding-left:20px;margin:6px 0} li{margin:3px 0}
</style></head><body>
<div class="card"><h1>Print Server {{.version}}</h1>
<div class="muted">Replaces the Laragon print server. Data folder: <code>{{.data_dir}}</code></div>
<p><a href="/print_server/log">Print log</a> <span class="muted">– last print jobs, the JSON sent and a receipt preview (this PC only)</span></p></div>
<div class="card"><h2>Status</h2>
<div>HTTP :{{.http.Port}} {{if .http.Running}}<span class="ok">running</span>{{else}}<span class="err">{{.http.Error}}</span>{{end}}</div>
{{if .https.Port}}<div>HTTPS :{{.https.Port}} {{if .https.Running}}<span class="ok">running</span>{{else}}<span class="err">{{.https.Error}}</span>{{end}}</div>{{end}}
<p class="muted">In Printers V2, use this PC's IP as the print server: {{range .ips}}<code>{{.}}</code> {{end}}</p>
{{if .https.Running}}<p class="muted">Tablets/phones using HTTPS: install <a href="/print_server/print-server-ca.crt">print-server-ca.crt</a> once.</p>
<p><button id="httpsBtn" onclick="checkHTTPS({{.https.Port}})">Check HTTPS on this device</button> <span id="httpsResult"></span></p>{{end}}
</div>
<div class="card"><h2>Printers on this PC</h2>
<table><tr><th>Name</th><th>Share name</th><th>Status</th><th></th></tr>
{{range .printers}}<tr><td>{{.Name}}</td><td>{{.ShareName}}</td><td>{{.Status}}</td>
<td><button onclick="testPrint('{{.Name}}', this)">Test print</button></td></tr>{{end}}
</table>
</div>
<div class="card"><h2>Setup guide</h2>
<details open><summary><b>1. Connect the POS</b></summary><ol>
<li>In the web app open <b>Settings → Printers V2</b>.</li>
<li>Enter this PC's IP as the print server: {{range .ips}}<code>{{.}}</code> {{end}}</li>
<li>Click <b>Detect</b>, pick the printer, save.</li>
</ol></details>
<details open><summary><b>2. Press "Allow" in Chrome</b></summary>
<p>On the first print from each PC, tablet or phone, Chrome asks if the POS website may
<b>access devices on your local network</b> (a box under the address bar). Press <b>Allow</b>.
This question comes from Chrome, not Print Server.</p>
<p><b>Pressed Block by mistake?</b> On the POS page tap the icon left of the address →
<b>Site settings</b> → <b>Local network access</b> → <b>Allow</b>, then reload the POS.</p></details>
{{if .https.Running}}<details><summary><b>3. Tablets and phones using HTTPS</b></summary>
<p>Install the certificate once on each device, or prints fail with "connection isn't private".</p>
<p><a href="/print_server/print-server-ca.crt"><button>Download certificate</button></a></p>
<ul><li><b>Android:</b> Settings → Security → Install certificate → CA certificate → pick print-server-ca.crt.</li>
<li><b>iPhone/iPad:</b> install the profile, then Settings → General → About → Certificate Trust Settings → turn it on.</li></ul>
<p class="muted">It only works for local addresses (localhost, 192.168.x.x, 10.x.x.x, 172.16–31.x.x), never for real websites.</p></details>{{end}}
<details><summary><b>Problems</b></summary><ul>
<li><b>POS can't reach the print server</b> but this page opens: Chrome blocked it, see step 2.</li>
<li><b>This page doesn't open on a tablet</b>: same Wi-Fi as this PC? Try <code>http://{{.server_ip}}/print_server/</code>.</li>
<li><b>Printer missing</b>: install its driver in Windows first (Printers &amp; scanners).</li>
<li><b>Receipt looks wrong</b>: open the <a href="/print_server/log">Print log</a> on this PC and click the job.</li>
</ul></details>
</div>
<script>
// Tries the HTTPS address of this print server from this device: works only when
// the certificate is installed, then offers to open the HTTPS page.
function checkHTTPS(port){
  const base = 'https://' + location.hostname + (port === 443 ? '' : ':' + port) + '/print_server/';
  const out = document.getElementById('httpsResult');
  out.textContent = 'Checking…';
  fetch(base + 'agent/status', {cache: 'no-store'})
    .then(r => { if (!r.ok) throw new Error(r.status); })
    .then(() => { out.innerHTML = '<span class="ok">HTTPS works on this device.</span> '; out.append(Object.assign(document.createElement('a'), {href: base, textContent: 'Open ' + base})); })
    .catch(() => { out.innerHTML = '<span class="err">Not trusted yet.</span> <a href="/print_server/print-server-ca.crt">Download certificate</a>, install it (see Setup guide step 3), then check again.'; });
}
function testPrint(name, btn){
  btn.disabled = true;
  fetch('/print_server/agent/test', {method:'POST', body:new URLSearchParams({printer:name})})
    .then(r => r.text()).then(t => {
      let j; try { j = JSON.parse(t); } catch { j = {success:false, error:t}; }
      btn.textContent = j.success ? 'Sent' : 'Failed'; if(!j.success) alert(j.error);
    })
    .catch(e => { btn.textContent = 'Failed'; alert(e); })
    .finally(() => { btn.disabled = false; });
}
</script>
</body></html>`))

func (a *Agent) handleHome(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	if err := homeTmpl.Execute(w, a.statusData(r)); err != nil {
		log.Printf("status page: %v", err)
	}
}
