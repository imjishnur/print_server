package main

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// Golden tests: testdata/*/*.bin are produced by the real PHP print server
// (tools/golden/gen.php). Text receipts must match byte for byte. Arabic lines,
// logos and resized images are drawn by a different engine (GDI / x/image vs GD),
// so for those only the structure is checked.

func approximate(name string) bool {
	return strings.Contains(name, "arabic") || strings.Contains(name, "logo") || strings.Contains(name, "resample")
}

func firstDiff(a, b []byte) int {
	for i := 0; i < min(len(a), len(b)); i++ {
		if a[i] != b[i] {
			return i
		}
	}
	return min(len(a), len(b))
}

func compare(t *testing.T, name string, got, want []byte) {
	t.Helper()
	if approximate(name) {
		if len(got) == 0 {
			t.Fatalf("%s: empty output", name)
		}
		diff := float64(len(got)-len(want)) / float64(len(want))
		t.Logf("%s: approximate, %d bytes (php %d, %+.1f%%)", name, len(got), len(want), diff*100)
		if diff > 0.25 || diff < -0.25 {
			t.Errorf("%s: size differs too much from PHP", name)
		}
		return
	}
	if !bytes.Equal(got, want) {
		i := firstDiff(got, want)
		lo, hi := max(0, i-20), i+20
		t.Fatalf("%s: differs at byte %d (go %d bytes, php %d bytes)\n go: %q\nphp: %q",
			name, i, len(got), len(want), got[lo:min(hi, len(got))], want[lo:min(hi, len(want))])
	}
}

func TestV1Golden(t *testing.T) {
	files, _ := filepath.Glob("testdata/v1/*.json")
	if len(files) == 0 {
		t.Skip("no samples")
	}
	for _, file := range files {
		name := strings.TrimSuffix(filepath.Base(file), ".json")
		t.Run(name, func(t *testing.T) {
			raw, _ := os.ReadFile(file)
			want, err := os.ReadFile(strings.TrimSuffix(file, ".json") + ".bin")
			if err != nil {
				t.Skip("no golden file; run tools/golden/gen.php")
			}
			dir, _ := filepath.Abs("testdata/datadir-empty")
			if strings.Contains(name, "logo") {
				dir, _ = filepath.Abs("testdata/datadir-logo")
			}
			v1 := v1Builder{cfg: defaultConfig(), dataDir: dir}
			d := decodeBatch(raw)

			var got []byte
			switch strings.SplitN(name, "-", 2)[0] {
			case "kot":
				got = v1.KOT(d)
			case "bill":
				got = v1.Bill(d)
			case "shift":
				got = v1.Shift(d)
			}
			compare(t, name, got, want)
		})
	}
}

func TestV2Golden(t *testing.T) {
	files, _ := filepath.Glob("testdata/v2/*.json")
	for _, file := range files {
		name := strings.TrimSuffix(filepath.Base(file), ".json")
		t.Run(name, func(t *testing.T) {
			var sample struct {
				Image          string `json:"image"`
				PrintType      string `json:"print_type"`
				OpenCashDrawer string `json:"open_cash_drawer"`
			}
			raw, _ := os.ReadFile(file)
			_ = json.Unmarshal(raw, &sample)
			png, err := os.ReadFile(filepath.Join("testdata/v2", sample.Image))
			if err != nil {
				t.Fatal(err)
			}
			want, err := os.ReadFile(strings.TrimSuffix(file, ".json") + ".bin")
			if err != nil {
				t.Skip("no golden file; run tools/golden/gen.php")
			}
			openDrawer := sample.OpenCashDrawer == "Yes" || sample.PrintType == "BILL" || sample.PrintType == "INVOICE"
			got, err := buildV2("data:image/png;base64,"+base64.StdEncoding.EncodeToString(png), openDrawer)
			if err != nil {
				t.Fatal(err)
			}
			compare(t, name, got, want)
		})
	}
}

// Dumps the Arabic receipts as images for a visual check next to the PHP ones.
func TestWriteArabicPreview(t *testing.T) {
	if os.Getenv("PREVIEW") == "" {
		t.Skip("set PREVIEW=1")
	}
	for _, name := range []string{"kot-arabic", "bill-arabic"} {
		raw, _ := os.ReadFile("testdata/v1/" + name + ".json")
		v1 := v1Builder{cfg: defaultConfig(), dataDir: "testdata/datadir-empty"}
		d := decodeBatch(raw)
		var out []byte
		if strings.HasPrefix(name, "kot") {
			out = v1.KOT(d)
		} else {
			out = v1.Bill(d)
		}
		_ = os.WriteFile(filepath.Join(os.TempDir(), name+"-go.bin"), out, 0o644)
	}
}
