//go:build windows

package main

import (
	"bytes"
	"encoding/base64"
	"testing"
	"time"
)

// rasterRows turns V2 printer bytes back into picture rows: GS v 0 rows as they
// are, ESC J n as n white rows. Two outputs that give the same rows print the
// same receipt.
func rasterRows(t *testing.T, out []byte) (rows [][]byte, feeds int) {
	t.Helper()
	i := bytes.Index(out, []byte{0x1D, 'v', '0'})
	if i < 0 {
		t.Fatal("no picture in output")
	}
	bpr := 0
	for i < len(out) {
		switch {
		case bytes.HasPrefix(out[i:], []byte{0x1D, 'v', '0'}):
			bpr = int(out[i+4]) | int(out[i+5])<<8
			h := int(out[i+6]) | int(out[i+7])<<8
			i += 8
			for r := 0; r < h; r++ {
				rows = append(rows, out[i:i+bpr])
				i += bpr
			}
		case bytes.HasPrefix(out[i:], []byte{0x1B, 'J'}):
			feeds++
			for r := 0; r < int(out[i+2]); r++ {
				rows = append(rows, make([]byte, bpr))
			}
			i += 3
		default:
			return rows, feeds
		}
	}
	return rows, feeds
}

func TestV2SkipBlankRowsPrintsSameReceipt(t *testing.T) {
	for _, file := range []string{"testdata/v2/beta-bill.png", "testdata/v2/beta-kot.png", "testdata/v2/receipt-576.png"} {
		img := base64.StdEncoding.EncodeToString(readFile(t, file))
		plain, err := buildV2(img, true, false)
		if err != nil {
			t.Fatal(err)
		}
		fast, err := buildV2(img, true, true)
		if err != nil {
			t.Fatal(err)
		}
		a, _ := rasterRows(t, plain)
		b, feeds := rasterRows(t, fast)
		if len(a) != len(b) {
			t.Fatalf("%s: %d rows, with blank skipping %d", file, len(a), len(b))
		}
		for i := range a {
			if !bytes.Equal(a[i], b[i]) {
				t.Fatalf("%s: row %d differs", file, i)
			}
		}
		if feeds == 0 || len(fast) >= len(plain) {
			t.Fatalf("%s: nothing skipped (%d feeds, %d vs %d bytes)", file, feeds, len(fast), len(plain))
		}
		if !bytes.HasSuffix(fast, []byte("\x1Bd\x04\x1DV\x41\x00")) || !bytes.Contains(fast[:12], []byte("\x1Bp\x00")) {
			t.Fatalf("%s: drawer/cut commands missing", file)
		}
		t.Logf("%s: %d -> %d bytes (%.0f%% less), %d feeds", file, len(plain), len(fast),
			100*(1-float64(len(fast))/float64(len(plain))), feeds)
	}
}

func TestV1TestPrintIsPrinted(t *testing.T) {
	f := newFakePrinters("OFFICE")
	a := testAgent(t, f)
	content := sample(t, "kot-basic", func(d batch) {
		d["path"], d["type"], d["printer_ip_address"] = "OFFICE", "windows", "192.168.18.10"
	})
	res := decodeResponse(t, serve(a, multipartRequest(map[string]string{"print_type": "TEST", "content_data": content})))
	if res["success"] != true || res["method"] != "windows_share" {
		t.Fatalf("test print failed: %v", res)
	}
	if p := f.printed(); len(p) != 1 || p[0].target != "OFFICE" || !bytes.Contains(p[0].data, []byte("Test print OK")) {
		t.Fatalf("printer got %+v", p)
	}
}

// A V2 job with a dead printer IP and a Windows printer: the first print tries
// the IP, the next ones go straight to Windows instead of waiting again.
func TestV2SkipsRecentlyFailedIP(t *testing.T) {
	f := newFakePrinters("OFFICE")
	a := testAgent(t, f)
	tries := 0
	send := a.sockSend
	a.sockSend = func(ip string, port int, data []byte, timeout time.Duration) error {
		tries++
		return send(ip, port, data, timeout)
	}
	for i := 0; i < 3; i++ {
		res := decodeResponse(t, serve(a, multipartRequest(map[string]string{
			"image_base64": receiptPNGBase64(t), "print_type": "BILL", "path": "OFFICE", "printer_ip": "192.168.18.99",
		})))
		if res["success"] != true || res["method"] != "windows_share" {
			t.Fatalf("print %d: %v", i, res)
		}
	}
	if tries != 1 {
		t.Fatalf("dead IP tried %d times, want 1", tries)
	}

	// Without another way to print, the IP is always tried
	g := newFakePrinters()
	b := testAgent(t, g)
	b.deadSockets.Store("192.168.18.99:9100", time.Now())
	tries = 0
	b.sockSend = func(string, int, []byte, time.Duration) error { tries++; return nil }
	res := decodeResponse(t, serve(b, multipartRequest(map[string]string{
		"image_base64": receiptPNGBase64(t), "print_type": "BILL", "printer_ip": "192.168.18.99",
	})))
	if tries != 1 || res["method"] != "socket" {
		t.Fatalf("IP-only printer must still be tried: tries=%d %v", tries, res)
	}
}

func TestBillTitleAndPaymentStatus(t *testing.T) {
	v1 := v1Builder{cfg: defaultConfig(), dataDir: "testdata/datadir-empty"}
	for _, c := range []struct{ docType, status, want, number string }{
		{"BILL", "", "*** BILL ***", "Bill #    : "},
		{"INVOICE", "paid", "*** INVOICE ***", "Invoice # : "},
		{"", "", "", "Invoice # : "},
	} {
		d := decodeBatch(readFile(t, "testdata/v1/bill-basic.json"))
		if c.docType != "" {
			d["doc_type"], d["payment_status"] = c.docType, c.status
		}
		out := v1.Bill(d)
		if c.want != "" && !bytes.Contains(out, []byte(c.want)) || c.want == "" && bytes.Contains(out, []byte("***")) {
			t.Errorf("doc_type %q: title wrong", c.docType)
		}
		if !bytes.Contains(out, []byte(c.number)) || !bytes.Contains(out, []byte("Powered by bizpoz.co")) {
			t.Errorf("doc_type %q: number label or footer wrong", c.docType)
		}
		// Status lines contain Arabic, so they are printed as a picture after the totals
		withStatus := len(out) > len(v1.Bill(decodeBatch(readFile(t, "testdata/v1/bill-basic.json"))))+40
		if (c.docType != "") != withStatus {
			t.Errorf("doc_type %q status %q: payment status line shown=%v", c.docType, c.status, withStatus)
		}
	}
}

func TestV2DrawerFollowsPOSSetting(t *testing.T) {
	for _, c := range []struct {
		printType, setting string
		open               bool
	}{{"BILL", "", true}, {"BILL", "No", false}, {"KOT", "Yes", true}, {"KOT", "", false}, {"BILL", "yes", true}} {
		f := newFakePrinters("OFFICE")
		a := testAgent(t, f)
		fields := map[string]string{"image_base64": receiptPNGBase64(t), "print_type": c.printType, "path": "OFFICE"}
		if c.setting != "" {
			fields["open_cash_drawer"] = c.setting
		}
		serve(a, multipartRequest(fields))
		data := f.printed()[0].data
		opened := bytes.Contains(data[:20], []byte("\x1Bp\x00\x19\xFA\x1Bp\x01\x19\xFA"))
		if opened != c.open {
			t.Errorf("%s open_cash_drawer=%q: drawer opened=%v, want %v", c.printType, c.setting, opened, c.open)
		}
	}
}
