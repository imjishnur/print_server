package main

import (
	"bytes"
	"encoding/json"
	"regexp"
	"strconv"
	"strings"
)

// Port of print_server/include/printer_load.php (KOT, BILL/INVOICE, SHIFT).
// Output must stay byte-identical to the PHP version; see escpos_test.go.

const (
	eqLine   = "================================================"
	dashLine = "------------------------------------------------"
)

// batch is the decoded content_data JSON (numbers kept as json.Number).
type batch map[string]any

func decodeBatch(data []byte) batch {
	dec := json.NewDecoder(bytes.NewReader(data))
	dec.UseNumber()
	var b batch
	if err := dec.Decode(&b); err != nil {
		return nil
	}
	return b
}

// get mirrors PHP isset: a missing key or JSON null is "not set".
func (b batch) get(key string) (any, bool) {
	v, ok := b[key]
	return v, ok && v != nil
}

// pick is PHP's $a ?? $b ?? $default.
func (b batch) pick(def any, keys ...string) any {
	for _, k := range keys {
		if v, ok := b.get(k); ok {
			return v
		}
	}
	return def
}

func (b batch) str(key string) string {
	v, _ := b.get(key)
	return phpStr(v)
}

// phpStr converts a value the way PHP string concatenation does.
func phpStr(v any) string {
	switch t := v.(type) {
	case nil:
		return ""
	case string:
		return t
	case bool:
		if t {
			return "1"
		}
		return ""
	case json.Number:
		s := t.String()
		if !strings.ContainsAny(s, ".eE") {
			return s
		}
		f, err := t.Float64()
		if err != nil {
			return s
		}
		return strconv.FormatFloat(f, 'G', 14, 64)
	case float64:
		return strconv.FormatFloat(t, 'G', 14, 64)
	default:
		return ""
	}
}

// truthy is !empty($v) in PHP.
func truthy(v any) bool {
	switch t := v.(type) {
	case nil:
		return false
	case string:
		return t != "" && t != "0"
	case bool:
		return t
	case json.Number:
		f, err := t.Float64()
		return err != nil || f != 0
	}
	return true
}

func isInt(v any, n int64) bool {
	num, ok := v.(json.Number)
	if !ok || strings.ContainsAny(num.String(), ".eE") {
		return false
	}
	i, err := num.Int64()
	return err == nil && i == n
}

// isYes is $v === 'Yes' || $v === true || $v === 1 || $v === '1'.
func isYes(v any) bool {
	return v == "Yes" || v == true || isInt(v, 1) || v == "1"
}

func phpTrim(s string) string  { return strings.Trim(s, " \t\n\r\x00\x0B") }
func phpRtrim(s string) string { return strings.TrimRight(s, " \t\n\r\x00\x0B") }

func strPad(s string, n int) string {
	if len(s) >= n {
		return s
	}
	return s + strings.Repeat(" ", n-len(s))
}

func splitLines(s string) []string {
	return strings.Split(strings.ReplaceAll(s, "\r", ""), "\n")
}

// arabicClass is PHP/PCRE2's \p{Arabic}, which also matches characters whose script
// extensions include Arabic (، ؛ ؟ tatweel, harakat, Arabic-Indic digits).
const arabicClass = `[\p{Arabic}\x{060C}\x{061B}\x{061C}\x{061F}\x{0640}\x{064B}-\x{0655}\x{0660}-\x{0669}\x{0670}\x{06D4}\x{FD3E}\x{FD3F}]`

var reArabic = regexp.MustCompile(arabicClass)

func hasArabic(s string) bool {
	return truthy(s) && reArabic.MatchString(s)
}

// escText: plain text + LF, or a raster image when the text has Arabic.
func escText(buf *bytes.Buffer, text, align string, fontSize int) {
	if !truthy(text) {
		return
	}
	if !hasArabic(text) {
		buf.WriteString(text)
		buf.WriteByte(0x0A)
		return
	}
	buf.Write(renderTextAsImage(text, fontSize, align))
}

// openCashDrawer pulses pin 2 and pin 5.
func openCashDrawer(buf *bytes.Buffer) {
	buf.WriteString("\x1Bp\x00\x19\xFA\x1Bp\x01\x19\xFA")
}

type v1Builder struct {
	cfg     Config
	dataDir string
}

func yesNo(b bool) string {
	if b {
		return "Yes"
	}
	return "No"
}

func (v v1Builder) storeName(buf *bytes.Buffer, d batch) {
	name := phpStr(d.pick("My Shop", "store_name"))
	bigName := d.pick(yesNo(v.cfg.BigStoreName), "big_name", "store_name_big")
	buf.WriteString("\x1Ba\x01")
	if isYes(bigName) {
		buf.WriteString("\x1B!\x38") // double height + double width + bold
		escText(buf, name, "center", 26)
		buf.WriteString("\x1B!\x00")
	} else {
		escText(buf, name, "center", 20)
	}
}

func (v v1Builder) logoMode(d batch) string {
	return phpStr(d.pick(v.cfg.DefaultLogoMode, "logo_mode"))
}

func (v v1Builder) KOT(d batch) []byte {
	var buf bytes.Buffer
	orderNo := d.str("sale_no_p")

	buf.WriteString("\x1B@")

	openDrawer := d.pick("No", "open_cash_drawer_when_printing_invoice", "open_cash_drawer", "open_drawer")
	if strings.EqualFold(phpStr(openDrawer), "Yes") || openDrawer == true || isInt(openDrawer, 1) || openDrawer == "1" {
		openCashDrawer(&buf)
	}

	if isYes(d.pick(yesNo(v.cfg.EnableKOTLogo), "print_kot_logo", "kot_logo", "print_logo")) {
		buf.Write(v.logo(d, v.logoMode(d)))
	}

	v.storeName(&buf, d)

	buf.WriteString("\x1Ba\x01")
	buf.WriteString(eqLine + "\x0A")

	buf.WriteString("\x1B!\x30") // double height
	buf.WriteString("K O T\x0A")
	buf.WriteString("\x1B!\x00")

	buf.WriteString(eqLine + "\x0A")

	buf.WriteString("\x1Ba\x00")
	escText(&buf, "Order #  : "+orderNo, "left", 14)
	escText(&buf, "Date     : "+d.str("date")+" "+d.str("time_inv"), "left", 14)
	if customer, _ := d.get("customer_name"); truthy(customer) {
		escText(&buf, "Customer : "+phpStr(customer), "left", 14)
	}
	if table, _ := d.get("customer_table"); truthy(table) {
		escText(&buf, "Table    : "+phpStr(table), "left", 14)
	}

	buf.WriteString(dashLine + "\x0A")
	buf.WriteString(strPad("Qty", 8) + strPad("Item", 32) + "\x0A")
	buf.WriteString(dashLine + "\x0A")

	totalItems := 0
	reHeader := regexp.MustCompile(`(?i)^(#|Qty)\s+(Item|Qty)`)
	reDashes := regexp.MustCompile(`^-+$`)
	reTrailingNum := regexp.MustCompile(`(\d+)\s*$`)
	if items, ok := d.pick("", "items").(string); ok {
		var filtered []string
		for _, line := range splitLines(items) {
			t := phpTrim(line)
			switch {
			case t == "", reDashes.MatchString(t), reHeader.MatchString(t):
				continue
			case strings.Contains(strings.ToLower(t), "total items:"):
				if m := reTrailingNum.FindStringSubmatch(t); m != nil {
					totalItems, _ = strconv.Atoi(m[1])
				}
				continue
			}
			filtered = append(filtered, line)
		}

		clean := strings.Join(filtered, "\n")
		if hasArabic(clean) {
			buf.Write(renderMultiLineAsImage(clean, 18, "left"))
		} else {
			for _, line := range filtered {
				buf.WriteString(phpRtrim(line) + "\x0A")
			}
		}
	}

	buf.WriteString(dashLine + "\x0A")

	displayTotal := "1"
	if totalItems > 0 {
		displayTotal = strconv.Itoa(totalItems)
	}
	buf.WriteString("\x1B!\x08") // bold
	buf.WriteString(strPad("Total Items:", 22) + strPad(displayTotal, 5) + "\x0A")
	buf.WriteString("\x1B!\x00")

	buf.WriteString("\x1Ba\x01")
	buf.WriteString("\x1B!\x38")
	buf.WriteString("#" + orderNo + "\x0A")
	buf.WriteString("\x1B!\x00")

	buf.WriteString(eqLine + "\x0A")

	buf.WriteString("\x1Ba\x01")
	buf.WriteString("Powered by BIZPOZ\x0A")

	buf.WriteString("\x1Bd\x05")
	buf.WriteString("\x1DV\x00") // cut
	return buf.Bytes()
}

func (v v1Builder) Bill(d batch) []byte {
	const nl = "\r\n"
	var buf bytes.Buffer

	buf.WriteString("\x1B@")

	// Opens unless explicitly 'No' / '0' / false
	openDrawer := d.pick("Yes", "open_cash_drawer_when_printing_invoice", "open_cash_drawer", "open_drawer")
	if openDrawer != false && openDrawer != "0" && !strings.EqualFold(phpStr(openDrawer), "No") {
		openCashDrawer(&buf)
	}

	printBillLogo := d.pick(yesNo(v.cfg.EnableBillLogo), "print_bill_logo", "bill_logo", "print_logo")
	if printBillLogo != "No" && printBillLogo != "0" && printBillLogo != false {
		buf.Write(v.logo(d, v.logoMode(d)))
	}

	v.storeName(&buf, d)

	address := d.str("address")
	if truthy(address) {
		if hasArabic(address) {
			buf.Write(renderMultiLineAsImage(address, 18, "center"))
		} else {
			for _, line := range splitLines(address) {
				if phpTrim(line) != "" {
					buf.WriteString(phpTrim(line) + nl)
				}
			}
		}
	}
	if phone := d.str("phone"); truthy(phone) {
		escText(&buf, "Mob : "+phone, "center", 18)
	}

	buf.WriteString(eqLine + nl)

	buf.WriteString("\x1Ba\x00")
	escText(&buf, "Invoice # : "+d.str("sale_no_p"), "left", 18)
	escText(&buf, "Date      : "+d.str("date")+" "+d.str("time_inv"), "left", 18)
	if customer := d.str("customer_name"); truthy(customer) {
		escText(&buf, "Customer  : "+customer, "left", 18)
	}

	buf.WriteString(eqLine + nl)

	for _, key := range []string{"items", "totals", "payments"} {
		block := d.str(key)
		if hasArabic(block) {
			buf.Write(renderMultiLineAsImage(block, 18, "left"))
		} else {
			buf.WriteString(strings.ReplaceAll(block, "\n", nl))
		}
	}

	if footer := d.str("invoice_footer"); truthy(footer) {
		buf.WriteString(dashLine + "\x0A")
		buf.WriteString("\x1Ba\x01")
		escText(&buf, footer, "center", 18)
	}

	buf.WriteString(eqLine + "\x0A")
	buf.WriteString("\x1Ba\x01")
	buf.WriteString("Powered by BIZPOZ\x0A")

	buf.WriteString("\x1Bd\x05")
	buf.WriteString("\x1DV\x00")
	return buf.Bytes()
}

// Shift reports arrive with the ESC/POS commands already built in items.
func (v v1Builder) Shift(d batch) []byte {
	return []byte(d.str("items"))
}
