//go:build windows

package main

import (
	"bytes"
	"encoding/base64"
	"encoding/binary"
	"encoding/json"
	"errors"
	"hash/crc32"
	"io"
	"mime/multipart"
	"net"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"
)

// fakePrinters stands in for the Windows spooler and network printers, so the
// tests exercise the real HTTP handler and fallback order without paper.
type fakePrinters struct {
	mu        sync.Mutex
	windows   map[string]bool // installed Windows printers (lower case)
	sockets   map[string]bool // reachable ip:port
	delay     time.Duration   // simulated print time
	jobs      []fakeJob
	active    map[string]int
	maxActive map[string]int
}

type fakeJob struct {
	via, target string
	data        []byte
}

func newFakePrinters(windows ...string) *fakePrinters {
	f := &fakePrinters{windows: map[string]bool{}, sockets: map[string]bool{}, active: map[string]int{}, maxActive: map[string]int{}}
	for _, w := range windows {
		f.windows[strings.ToLower(w)] = true
	}
	return f
}

func (f *fakePrinters) send(via, target string, ok bool, data []byte) error {
	f.mu.Lock()
	if !ok {
		f.mu.Unlock()
		return errors.New(via + " " + target + " not reachable")
	}
	f.active[target]++
	if f.active[target] > f.maxActive[target] {
		f.maxActive[target] = f.active[target]
	}
	f.mu.Unlock()

	time.Sleep(f.delay)

	f.mu.Lock()
	f.active[target]--
	f.jobs = append(f.jobs, fakeJob{via: via, target: target, data: append([]byte(nil), data...)})
	f.mu.Unlock()
	return nil
}

func (f *fakePrinters) install(a *Agent) {
	a.winSend = func(name string, data []byte) error {
		return f.send("windows", name, f.windows[strings.ToLower(name)], data)
	}
	a.sockSend = func(ip string, port int, data []byte, _ time.Duration) error {
		addr := net.JoinHostPort(ip, strconv.Itoa(port))
		return f.send("socket", addr, f.sockets[addr], data)
	}
}

func (f *fakePrinters) printed() []fakeJob {
	f.mu.Lock()
	defer f.mu.Unlock()
	return append([]fakeJob(nil), f.jobs...)
}

func testAgent(t testing.TB, f *fakePrinters) *Agent {
	t.Helper()
	dir, _ := filepath.Abs("testdata/datadir-empty")
	a := newAgent(defaultConfig(), dir)
	f.install(a)
	return a
}

func readFile(t testing.TB, path string) []byte {
	t.Helper()
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	return data
}

// sample loads a test print job and lets the test change fields.
func sample(t testing.TB, name string, change func(batch)) string {
	t.Helper()
	d := decodeBatch(readFile(t, "testdata/v1/"+name+".json"))
	if change != nil {
		change(d)
	}
	out, _ := json.Marshal(d)
	return string(out)
}

func multipartRequest(fields map[string]string) *http.Request {
	var body bytes.Buffer
	w := multipart.NewWriter(&body)
	for k, v := range fields {
		_ = w.WriteField(k, v)
	}
	_ = w.Close()
	r := httptest.NewRequest(http.MethodPost, printPath, &body)
	r.Header.Set("Content-Type", w.FormDataContentType())
	return r
}

func formRequest(fields map[string]string) *http.Request {
	v := url.Values{}
	for k, val := range fields {
		v.Set(k, val)
	}
	r := httptest.NewRequest(http.MethodPost, printPath, strings.NewReader(v.Encode()))
	r.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	return r
}

func serve(a *Agent, r *http.Request) *httptest.ResponseRecorder {
	w := httptest.NewRecorder()
	a.routes().ServeHTTP(w, r)
	return w
}

func decodeResponse(t testing.TB, w *httptest.ResponseRecorder) obj {
	t.Helper()
	var res obj
	if err := json.Unmarshal(w.Body.Bytes(), &res); err != nil {
		t.Fatalf("response is not JSON (%d): %q", w.Code, w.Body.String())
	}
	return res
}

func TestPrintKOTMultipartLikePOS(t *testing.T) {
	f := newFakePrinters("POS-80C")
	a := testAgent(t, f)

	w := serve(a, multipartRequest(map[string]string{"print_type": "KOT", "content_data": sample(t, "kot-basic", nil)}))
	res := decodeResponse(t, w)

	if w.Code != 200 || res["success"] != true || res["method"] != "windows_share" || res["path"] != "POS-80C" || res["print_type"] != "KOT" {
		t.Fatalf("unexpected response %d %v", w.Code, res)
	}
	jobs := f.printed()
	if len(jobs) != 1 || !bytes.Equal(jobs[0].data, readFile(t, "testdata/v1/kot-basic.bin")) {
		t.Fatal("printer did not receive the PHP-identical KOT bytes")
	}
}

func TestPrintBillFormEncoded(t *testing.T) {
	f := newFakePrinters("POS-80C")
	a := testAgent(t, f)

	w := serve(a, formRequest(map[string]string{"print_type": "BILL", "content_data": sample(t, "bill-basic", nil)}))
	res := decodeResponse(t, w)
	if res["success"] != true || res["method"] != "windows_share" {
		t.Fatalf("unexpected response %v", res)
	}
	if _, hasPath := res["path"]; hasPath {
		t.Error("PHP does not return path for bills; response should match")
	}
	if !bytes.Equal(f.printed()[0].data, readFile(t, "testdata/v1/bill-basic.bin")) {
		t.Fatal("bill bytes differ from PHP")
	}
}

func TestPrintRawJSONBody(t *testing.T) {
	f := newFakePrinters("POS-80C")
	a := testAgent(t, f)

	body := sample(t, "kot-basic", func(d batch) { d["print_type"] = "KOT" })
	r := httptest.NewRequest(http.MethodPost, printPath, strings.NewReader(body))
	r.Header.Set("Content-Type", "application/json")
	res := decodeResponse(t, serve(a, r))
	if res["success"] != true {
		t.Fatalf("raw JSON body not printed: %v", res)
	}
	if !bytes.Equal(f.printed()[0].data, readFile(t, "testdata/v1/kot-basic.bin")) {
		t.Fatal("bytes differ from PHP")
	}
}

// Same order as printer_load.php: Windows printer, then network IP, then the
// popup default printer; same error messages.
func TestFallbackOrderLikePHP(t *testing.T) {
	cases := []struct {
		name       string
		printType  string
		windows    []string
		sockets    []string
		change     func(batch)
		wantMethod string
		wantTarget string
		wantError  string
	}{
		{"windows printer first", "KOT", []string{"POS-80C"}, []string{"192.168.1.20:9100"}, nil, "windows_share", "POS-80C", ""},
		{"network when windows printer missing", "KOT", nil, []string{"192.168.1.20:9100"}, nil, "socket", "192.168.1.20:9100", ""},
		{"popup default printer", "KOT", []string{"POS-80C"}, nil, func(d batch) {
			d["path"] = "Kitchen"
			d["connection_type"] = "popup"
		}, "popup", "POS-80C", ""},
		{"KOT reports socket error", "KOT", nil, nil, nil, "", "", "Socket failed:"},
		{"bill reports generic error", "BILL", nil, nil, nil, "", "", "Could not connect to printer"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			f := newFakePrinters(c.windows...)
			for _, s := range c.sockets {
				f.sockets[s] = true
			}
			a := testAgent(t, f)
			name := "kot-basic"
			if c.printType == "BILL" {
				name = "bill-basic"
			}
			res := decodeResponse(t, serve(a, multipartRequest(map[string]string{"print_type": c.printType, "content_data": sample(t, name, c.change)})))

			if c.wantError != "" {
				if res["success"] != false || !strings.HasPrefix(res["error"].(string), c.wantError) {
					t.Fatalf("want error %q, got %v", c.wantError, res)
				}
				return
			}
			jobs := f.printed()
			if res["method"] != c.wantMethod || len(jobs) != 1 || jobs[0].target != c.wantTarget {
				t.Fatalf("want %s to %s, got %v (jobs %d)", c.wantMethod, c.wantTarget, res, len(jobs))
			}
		})
	}
}

func receiptPNGBase64(t testing.TB) string {
	return "data:image/png;base64," + base64.StdEncoding.EncodeToString(readFile(t, "testdata/v2/receipt-576.png"))
}

func TestPrintV2PictureLikePOSBeta(t *testing.T) {
	f := newFakePrinters("POS-80C")
	a := testAgent(t, f)

	// Same fields POS beta sends in printBillSilentlyV2
	res := decodeResponse(t, serve(a, multipartRequest(map[string]string{
		"image_base64": receiptPNGBase64(t), "print_type": "BILL", "print_server_version": "v2",
		"printer_name": "POS-80C", "path": "POS-80C", "connection_type": "windows", "open_cash_drawer": "Yes",
	})))
	if res["success"] != true || res["v2"] != true || res["method"] != "windows_share" {
		t.Fatalf("unexpected response %v", res)
	}
	if !bytes.Equal(f.printed()[0].data, readFile(t, "testdata/v2/bill-576.bin")) {
		t.Fatal("v2 bytes differ from printer_load_v2.php")
	}
}

func TestPrintShiftPassesCommandsThrough(t *testing.T) {
	f := newFakePrinters("POS-80C")
	a := testAgent(t, f)
	content := sample(t, "shift-basic", nil)
	res := decodeResponse(t, serve(a, multipartRequest(map[string]string{"print_type": "SHIFT", "content_data": content})))
	if res["success"] != true {
		t.Fatalf("shift not printed: %v", res)
	}
	if want := decodeBatch([]byte(content)).str("items"); string(f.printed()[0].data) != want {
		t.Fatal("shift commands were changed")
	}
}

func TestOtherPrintTypesPrintNothing(t *testing.T) {
	f := newFakePrinters("POS-80C")
	a := testAgent(t, f)
	w := serve(a, multipartRequest(map[string]string{"print_type": "TEST", "content_data": sample(t, "kot-basic", nil)}))
	if w.Code != 200 || w.Body.Len() != 0 || len(f.printed()) != 0 {
		t.Fatalf("printer_load.php prints nothing for other types; got %d %q", w.Code, w.Body.String())
	}
}

func TestBadRequests(t *testing.T) {
	a := testAgent(t, newFakePrinters())

	w := serve(a, httptest.NewRequest(http.MethodPost, printPath, nil))
	if w.Code != 400 || decodeResponse(t, w)["error"] != "No content_data or image_base64 received" {
		t.Errorf("empty POST: %d %s", w.Code, w.Body.String())
	}

	w = serve(a, multipartRequest(map[string]string{"image_base64": "not-an-image", "print_type": "BILL"}))
	if w.Code != 400 {
		t.Errorf("bad image: %d %s", w.Code, w.Body.String())
	}

	r := httptest.NewRequest(http.MethodGet, printPath, nil)
	r.Header.Set("Accept", "text/html")
	w = serve(a, r)
	if w.Code != 200 || !strings.Contains(w.Body.String(), "Print Server") {
		t.Errorf("browser GET should show the status page, got %d", w.Code)
	}
}

func TestCORSAndPrivateNetworkAccess(t *testing.T) {
	a := testAgent(t, newFakePrinters())
	r := httptest.NewRequest(http.MethodOptions, printPath, nil)
	r.Header.Set("Origin", "https://pos.example")
	r.Header.Set("Access-Control-Request-Private-Network", "true")
	w := serve(a, r)
	h := w.Header()
	if w.Code != 200 || h.Get("Access-Control-Allow-Origin") != "*" || h.Get("Access-Control-Allow-Private-Network") != "true" ||
		!strings.Contains(h.Get("Access-Control-Allow-Methods"), "POST") {
		t.Fatalf("preflight headers: %d %v", w.Code, h)
	}
}

func TestGetPrintersShape(t *testing.T) {
	a := testAgent(t, newFakePrinters())
	res := decodeResponse(t, serve(a, httptest.NewRequest(http.MethodGet, printPath+"?action=get_printers", nil)))
	printers, ok := res["printers"].([]any)
	if res["success"] != true || !ok || res["count"] != float64(len(printers)) || res["server_ip"] == "" {
		t.Fatalf("get_printers shape differs from PHP: %v", res)
	}
}

func TestTestPrintIsInPrintLog(t *testing.T) {
	f := newFakePrinters("POS-80C")
	a := testAgent(t, f)
	for _, name := range []string{"POS-80C", "NoSuchPrinter"} {
		r := httptest.NewRequest(http.MethodPost, "/print_server/agent/test", strings.NewReader("printer="+name))
		r.Header.Set("Content-Type", "application/x-www-form-urlencoded")
		r.RemoteAddr = "127.0.0.1:50000"
		serve(a, r)
	}
	jobs := a.jobs.list() // newest first
	if len(jobs) != 2 || jobs[1].PrintType != "TEST" || jobs[1].Printer != "POS-80C" || !jobs[1].Success ||
		jobs[0].Success || jobs[0].Error == "" || jobs[1].Bytes == 0 {
		t.Fatalf("test prints not logged: %+v", jobs)
	}
	if p := f.printed(); len(p) != 1 || !bytes.Contains(p[0].data, []byte("Test print OK")) {
		t.Fatalf("printer got %+v", p)
	}
}

func TestPrintLogOnlyOnThisPC(t *testing.T) {
	f := newFakePrinters("POS-80C")
	a := testAgent(t, f)
	content := sample(t, "kot-basic", nil)
	serve(a, multipartRequest(map[string]string{"print_type": "KOT", "content_data": content}))

	for _, path := range []string{"/print_server/log", "/print_server/agent/jobs", "/print_server/agent/jobs/1"} {
		if w := serve(a, httptest.NewRequest(http.MethodGet, path, nil)); w.Code != 403 {
			t.Errorf("%s from another device: want 403, got %d", path, w.Code)
		}
	}
	remoteTest := httptest.NewRequest(http.MethodPost, "/print_server/agent/test", strings.NewReader("printer=POS-80C"))
	remoteTest.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	// Test print is allowed from tablets and phones
	if res := decodeResponse(t, serve(a, remoteTest)); res["success"] != true {
		t.Errorf("test print from a tablet: %v", res)
	}

	// Opening the page by this PC's own LAN IP counts as this PC
	for _, ip := range lanIPs() {
		own := httptest.NewRequest(http.MethodGet, "/print_server/log", nil)
		own.RemoteAddr = net.JoinHostPort(ip.String(), "50000")
		if w := serve(a, own); w.Code != 200 {
			t.Errorf("print log from this PC's own IP %s: want 200, got %d", ip, w.Code)
		}
	}

	local := httptest.NewRequest(http.MethodGet, "/print_server/agent/jobs/1", nil)
	local.RemoteAddr = "127.0.0.1:50000"
	w := serve(a, local)
	if w.Code != 200 {
		t.Fatalf("job detail from this PC: %d", w.Code)
	}
	var detail struct {
		Job     printJob `json:"job"`
		Request string   `json:"request"`
		Output  string   `json:"output"`
	}
	_ = json.Unmarshal(w.Body.Bytes(), &detail)
	out, _ := base64.StdEncoding.DecodeString(detail.Output)
	if detail.Request != content || !bytes.Equal(out, readFile(t, "testdata/v1/kot-basic.bin")) ||
		!detail.Job.Success || detail.Job.OrderNo != "ORD-1042" || detail.Job.PrintType != "KOT" {
		t.Fatalf("print log does not show what was sent and printed: %+v", detail.Job)
	}
}

func TestPrintLogKeepsLast100(t *testing.T) {
	var l jobLog
	for i := 0; i < 150; i++ {
		l.add(&printJob{OrderNo: strconv.Itoa(i)})
	}
	list := l.list()
	if len(list) != maxJobs || list[0].OrderNo != "149" || list[len(list)-1].OrderNo != "50" || l.get(1) != nil {
		t.Fatalf("want newest 100 jobs, got %d (first %s)", len(list), list[0].OrderNo)
	}
}

// Many tablets printing at once: one job at a time per printer, nothing mixed.
func TestConcurrentJobsDoNotMix(t *testing.T) {
	f := newFakePrinters("POS-80C", "Kitchen")
	f.delay = 3 * time.Millisecond
	a := testAgent(t, f)
	kot := sample(t, "kot-basic", nil)
	kitchen := sample(t, "kot-basic", func(d batch) { d["path"] = "Kitchen" })

	var wg sync.WaitGroup
	failures := make(chan string, 60)
	for i := 0; i < 60; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			content := kot
			if i%3 == 0 {
				content = kitchen
			}
			w := serve(a, multipartRequest(map[string]string{"print_type": "KOT", "content_data": content}))
			if !strings.Contains(w.Body.String(), `"success":true`) {
				failures <- w.Body.String()
			}
		}(i)
	}
	wg.Wait()
	close(failures)
	for msg := range failures {
		t.Error("job failed:", msg)
	}

	want := readFile(t, "testdata/v1/kot-basic.bin")
	jobs := f.printed()
	if len(jobs) != 60 {
		t.Fatalf("want 60 jobs printed, got %d", len(jobs))
	}
	for _, j := range jobs {
		if !bytes.Equal(j.data, want) {
			t.Fatal("a job's bytes were changed or mixed")
		}
	}
	for printer, n := range f.maxActive {
		if n != 1 {
			t.Errorf("%s received %d jobs at the same time", printer, n)
		}
	}
}

// A real TCP "network printer" on port 9100-style socket.
func TestRealNetworkPrinterSocket(t *testing.T) {
	ln, err := net.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer ln.Close()
	received := make(chan []byte, 1)
	go func() {
		conn, err := ln.Accept()
		if err != nil {
			return
		}
		data, _ := io.ReadAll(conn)
		conn.Close()
		received <- data
	}()

	f := newFakePrinters() // no Windows printers
	a := testAgent(t, f)
	a.sockSend = sendSocket // the real socket code
	port := ln.Addr().(*net.TCPAddr).Port

	content := sample(t, "kot-basic", func(d batch) {
		d["printer_ip_address"] = "127.0.0.1"
		d["printer_port"] = json.Number(strconv.Itoa(port))
	})
	res := decodeResponse(t, serve(a, multipartRequest(map[string]string{"print_type": "KOT", "content_data": content})))
	if res["success"] != true || res["method"] != "socket" {
		t.Fatalf("socket print failed: %v", res)
	}
	select {
	case got := <-received:
		if !bytes.Equal(got, readFile(t, "testdata/v1/kot-basic.bin")) {
			t.Fatal("network printer received different bytes")
		}
	case <-time.After(5 * time.Second):
		t.Fatal("network printer received nothing")
	}
}

func TestUnreachableNetworkPrinterFailsFast(t *testing.T) {
	ln, _ := net.Listen("tcp4", "127.0.0.1:0")
	port := ln.Addr().(*net.TCPAddr).Port
	ln.Close() // nothing listening now

	start := time.Now()
	if err := sendSocket("127.0.0.1", port, []byte("x"), time.Second); err == nil {
		t.Fatal("expected an error")
	}
	if d := time.Since(start); d > 3*time.Second {
		t.Fatalf("took %v, should give up within the timeout", d)
	}
}

func TestHugeImageRejected(t *testing.T) {
	// A valid PNG header (with CRC) claiming 100000 x 100000 pixels: ~40 GB if decoded
	ihdr := []byte("IHDR\x00\x01\x86\xa0\x00\x01\x86\xa0\x08\x06\x00\x00\x00")
	png := append([]byte("\x89PNG\r\n\x1a\n\x00\x00\x00\r"), ihdr...)
	png = binary.BigEndian.AppendUint32(png, crc32.ChecksumIEEE(ihdr))
	_, err := buildV2(base64.StdEncoding.EncodeToString(png), false)
	if err == nil || err.Error() != "Image too large" {
		t.Fatalf("want 'Image too large', got %v", err)
	}
}

func TestLogoFromWebAppBase64(t *testing.T) {
	logo := readFile(t, "testdata/datadir-logo/logo/logo.png")
	d := decodeBatch(readFile(t, "testdata/v1/bill-logo.json"))
	d["logo_base64"] = base64.StdEncoding.EncodeToString(logo)

	empty, _ := filepath.Abs("testdata/datadir-empty")
	withFile, _ := filepath.Abs("testdata/datadir-logo")
	fromB64 := v1Builder{cfg: defaultConfig(), dataDir: empty}.Bill(d)
	fromFile := v1Builder{cfg: defaultConfig(), dataDir: withFile}.Bill(d)
	if !bytes.Equal(fromB64, fromFile) {
		t.Fatal("logo from logo_base64 should print the same as logo\\logo.png")
	}

	off := defaultConfig()
	off.UseBatchLogo = false
	if bytes.Contains(v1Builder{cfg: off, dataDir: empty}.Bill(d), []byte("\x1B*\x21")) {
		t.Fatal("use_batch_logo=false should ignore logo_base64")
	}
}
