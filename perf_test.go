//go:build windows

package main

import (
	"bytes"
	"encoding/base64"
	"fmt"
	"mime/multipart"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"
)

// Performance:
//   go test -run XXX -bench . -benchmem          time and memory per receipt
//   LOAD=1 go test -run TestLoad -v               many tablets printing at once

func benchBatch(b *testing.B, name string) batch {
	b.Helper()
	data, err := os.ReadFile("testdata/v1/" + name + ".json")
	if err != nil {
		b.Fatal(err)
	}
	return decodeBatch(data)
}

func BenchmarkKOT(b *testing.B) {
	d := benchBatch(b, "kot-basic")
	v1 := v1Builder{cfg: defaultConfig(), dataDir: "testdata/datadir-empty"}
	for b.Loop() {
		v1.KOT(d)
	}
}

func BenchmarkBill(b *testing.B) {
	d := benchBatch(b, "bill-basic")
	v1 := v1Builder{cfg: defaultConfig(), dataDir: "testdata/datadir-empty"}
	for b.Loop() {
		v1.Bill(d)
	}
}

func BenchmarkBillWithLogo(b *testing.B) {
	d := benchBatch(b, "bill-logo")
	dir, _ := filepath.Abs("testdata/datadir-logo")
	v1 := v1Builder{cfg: defaultConfig(), dataDir: dir}
	for b.Loop() {
		v1.Bill(d)
	}
}

func BenchmarkBillArabic(b *testing.B) {
	d := benchBatch(b, "bill-arabic")
	v1 := v1Builder{cfg: defaultConfig(), dataDir: "testdata/datadir-empty"}
	for b.Loop() {
		v1.Bill(d)
	}
}

func BenchmarkV2Picture(b *testing.B) {
	png, err := os.ReadFile("testdata/v2/receipt-576.png")
	if err != nil {
		b.Fatal(err)
	}
	img := base64.StdEncoding.EncodeToString(png)
	for b.Loop() {
		if _, err := buildV2(img, true); err != nil {
			b.Fatal(err)
		}
	}
}

// Whole request: parse multipart, build receipt, deliver, log, respond.
func BenchmarkHTTPRequestKOT(b *testing.B) {
	f := newFakePrinters("POS-80C")
	a := testAgent(b, f)
	content := sample(b, "kot-basic", nil)
	h := a.routes()
	for b.Loop() {
		w := httptest.NewRecorder()
		h.ServeHTTP(w, multipartRequest(map[string]string{"print_type": "KOT", "content_data": content}))
	}
}

// TestLoad: tablets x jobs over a real HTTP server with simulated printers that
// take printDelay per job (a fast thermal printer).
func TestLoad(t *testing.T) {
	if os.Getenv("LOAD") == "" {
		t.Skip("set LOAD=1")
	}
	const (
		tablets    = 20
		jobsEach   = 25
		printDelay = 20 * time.Millisecond
	)
	f := newFakePrinters("Bill", "Kitchen", "Bar", "Grill")
	f.delay = printDelay
	a := testAgent(t, f)
	srv := httptest.NewServer(a.routes())
	defer srv.Close()

	png, _ := os.ReadFile("testdata/v2/receipt-576.png")
	picture := "data:image/png;base64," + base64.StdEncoding.EncodeToString(png)
	printers := []string{"Kitchen", "Bar", "Grill"}
	jobs := []struct {
		kind   string
		fields func(i int) map[string]string
	}{
		{"KOT v1", func(i int) map[string]string {
			return map[string]string{"print_type": "KOT", "content_data": sample(t, "kot-basic", func(d batch) { d["path"] = printers[i%3] })}
		}},
		{"Bill v1", func(int) map[string]string {
			return map[string]string{"print_type": "BILL", "content_data": sample(t, "bill-basic", func(d batch) { d["path"] = "Bill" })}
		}},
		{"Arabic v1", func(int) map[string]string {
			return map[string]string{"print_type": "BILL", "content_data": sample(t, "bill-arabic", func(d batch) { d["path"] = "Bill"; d["type"] = "windows" })}
		}},
		{"Bill v2 (beta)", func(int) map[string]string {
			return map[string]string{"image_base64": picture, "print_type": "BILL", "path": "Bill", "printer_name": "Bill"}
		}},
	}

	var memBefore runtime.MemStats
	runtime.GC()
	runtime.ReadMemStats(&memBefore)

	type result struct {
		kind string
		d    time.Duration
		ok   bool
	}
	results := make(chan result, tablets*jobsEach)
	start := time.Now()
	var wg sync.WaitGroup
	for tab := 0; tab < tablets; tab++ {
		wg.Add(1)
		go func(tab int) {
			defer wg.Done()
			for n := 0; n < jobsEach; n++ {
				job := jobs[(tab+n)%len(jobs)]
				var body bytes.Buffer
				mw := multipart.NewWriter(&body)
				for k, v := range job.fields(tab + n) {
					_ = mw.WriteField(k, v)
				}
				_ = mw.Close()
				t0 := time.Now()
				resp, err := http.Post(srv.URL+printPath, mw.FormDataContentType(), &body)
				ok := false
				if err == nil {
					var buf bytes.Buffer
					_, _ = buf.ReadFrom(resp.Body)
					resp.Body.Close()
					ok = strings.Contains(buf.String(), `"success":true`)
				}
				results <- result{job.kind, time.Since(t0), ok}
			}
		}(tab)
	}
	wg.Wait()
	elapsed := time.Since(start)
	close(results)

	var memAfter runtime.MemStats
	runtime.ReadMemStats(&memAfter)

	byKind := map[string][]time.Duration{}
	failed := 0
	for r := range results {
		byKind[r.kind] = append(byKind[r.kind], r.d)
		if !r.ok {
			failed++
		}
	}
	pct := func(ds []time.Duration, p float64) time.Duration {
		slices.Sort(ds)
		return ds[int(float64(len(ds)-1)*p)]
	}
	total := tablets * jobsEach
	t.Logf("%d tablets x %d jobs = %d jobs in %v -> %.0f jobs/sec, %d failed", tablets, jobsEach, total, elapsed.Round(time.Millisecond), float64(total)/elapsed.Seconds(), failed)
	t.Logf("simulated printer time %v per job; 4 printers in parallel", printDelay)
	t.Logf("%-16s %6s %8s %8s %8s", "job", "count", "p50", "p95", "p99")
	for _, j := range jobs {
		ds := byKind[j.kind]
		t.Logf("%-16s %6d %8v %8v %8v", j.kind, len(ds), pct(ds, .5).Round(time.Millisecond), pct(ds, .95).Round(time.Millisecond), pct(ds, .99).Round(time.Millisecond))
	}
	t.Logf("Go heap allocated during test: %.1f MB total", float64(memAfter.TotalAlloc-memBefore.TotalAlloc)/1e6)
	fmt.Println()

	if failed > 0 {
		t.Errorf("%d jobs failed", failed)
	}
	for printer, n := range f.maxActive {
		if n > 1 {
			t.Errorf("%s got %d jobs at once", printer, n)
		}
	}
}
