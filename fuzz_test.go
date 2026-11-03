package main

import (
	"encoding/base64"
	"os"
	"path/filepath"
	"testing"
)

// Random or broken print data from the POS must never crash the app.
//   go test -fuzz=FuzzV1Receipts -fuzztime=30s

func FuzzV1Receipts(f *testing.F) {
	files, _ := filepath.Glob("testdata/v1/*.json")
	for _, file := range files {
		if data, err := os.ReadFile(file); err == nil {
			f.Add(data)
		}
	}
	f.Add([]byte(`{"items":null,"sale_no_p":1e400,"big_name":[1],"logo_base64":"!!!"}`))
	f.Add([]byte(`{"items":"\u0000\u001b\u001d","store_name":{"a":1},"customer_name":true}`))

	v1 := v1Builder{cfg: defaultConfig(), dataDir: "testdata/datadir-empty"}
	f.Fuzz(func(t *testing.T, data []byte) {
		d := decodeBatch(data)
		if d == nil {
			return
		}
		v1.KOT(d)
		v1.Bill(d)
		v1.Shift(d)
	})
}

func FuzzV2Images(f *testing.F) {
	if png, err := os.ReadFile("testdata/v2/receipt-400.png"); err == nil {
		f.Add(png)
	}
	f.Add([]byte("\x89PNG\r\n\x1a\n"))
	f.Add([]byte("GIF89a"))
	f.Fuzz(func(t *testing.T, data []byte) {
		_, _ = buildV2(base64.StdEncoding.EncodeToString(data), true)
	})
}
