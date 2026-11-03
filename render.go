//go:build windows

package main

import (
	"errors"
	"log"
	"math"
	"os"
	"path/filepath"
	"regexp"
	"sync"
	"unsafe"

	"golang.org/x/image/font/sfnt"
	"golang.org/x/sys/windows"
)

// Text images for Arabic lines, drawn with Windows GDI (which shapes Arabic itself).
// Mirrors the GD drawing in printer_load.php: same font (ae_AlHor), point sizes at
// 96 dpi, baseline positions and threshold 160.

var (
	gdi32                     = windows.NewLazySystemDLL("gdi32.dll")
	procCreateCompatibleDC    = gdi32.NewProc("CreateCompatibleDC")
	procCreateDIBSection      = gdi32.NewProc("CreateDIBSection")
	procSelectObject          = gdi32.NewProc("SelectObject")
	procDeleteObject          = gdi32.NewProc("DeleteObject")
	procDeleteDC              = gdi32.NewProc("DeleteDC")
	procCreateFontW           = gdi32.NewProc("CreateFontW")
	procSetTextColor          = gdi32.NewProc("SetTextColor")
	procSetBkMode             = gdi32.NewProc("SetBkMode")
	procSetTextAlign          = gdi32.NewProc("SetTextAlign")
	procExtTextOutW           = gdi32.NewProc("ExtTextOutW")
	procGetTextExtentPoint32W = gdi32.NewProc("GetTextExtentPoint32W")
	procAddFontResourceExW    = gdi32.NewProc("AddFontResourceExW")
	procGdiFlush              = gdi32.NewProc("GdiFlush")
)

const (
	frPrivate          = 0x10
	defaultCharset     = 1
	antialiasedQuality = 4
	bkTransparent      = 1
	etoRTLReading      = 0x80
	taBaselineLeft     = 24
)

type bitmapInfoHeader struct {
	Size          uint32
	Width         int32
	Height        int32
	Planes        uint16
	BitCount      uint16
	Compression   uint32
	SizeImage     uint32
	XPelsPerMeter int32
	YPelsPerMeter int32
	ClrUsed       uint32
	ClrImportant  uint32
}

type bitmapInfo struct {
	Header bitmapInfoHeader
	Colors [1]uint32
}

type gdiSize struct{ CX, CY int32 }

var (
	renderMu   sync.Mutex // GDI drawing is serialised; receipts are small
	fontOnce   sync.Once
	fontFamily = "Arial" // printer_load.php falls back to Arial when ae_AlHor is missing
	arabicRun  = regexp.MustCompile(arabicClass + `+`)
)

func fontPaths() []string {
	cwd, _ := os.Getwd()
	return []string{
		filepath.Join(exeDir(), "fonts", "ae_AlHor.ttf"),
		filepath.Join(cwd, "fonts", "ae_AlHor.ttf"),
		filepath.Join(dataDir(), "fonts", "ae_AlHor.ttf"),
	}
}

func receiptFont() string {
	fontOnce.Do(func() {
		for _, path := range fontPaths() {
			data, err := os.ReadFile(path)
			if err != nil {
				continue
			}
			f, err := sfnt.Parse(data)
			if err != nil {
				continue
			}
			family, err := f.Name(nil, sfnt.NameIDFamily)
			if err != nil || family == "" {
				continue
			}
			p, _ := windows.UTF16PtrFromString(path)
			if r1, _, _ := procAddFontResourceExW.Call(uintptr(unsafe.Pointer(p)), frPrivate, 0); r1 == 0 {
				continue
			}
			fontFamily = family
			return
		}
		log.Println("ae_AlHor.ttf not found, Arabic text uses Arial")
	})
	return fontFamily
}

type textCanvas struct {
	w, h            int
	dc, bmp, oldBmp uintptr
	font, oldFont   uintptr
	pix             []byte // BGRA, top-down
}

func newTextCanvas(w, h, sizePt int) (*textCanvas, error) {
	family := receiptFont()
	dc, _, _ := procCreateCompatibleDC.Call(0)
	if dc == 0 {
		return nil, errors.New("CreateCompatibleDC failed")
	}
	bi := bitmapInfo{Header: bitmapInfoHeader{
		Width:    int32(w),
		Height:   -int32(h), // top-down rows
		Planes:   1,
		BitCount: 32,
	}}
	bi.Header.Size = uint32(unsafe.Sizeof(bi.Header))
	var bits unsafe.Pointer
	bmp, _, _ := procCreateDIBSection.Call(dc, uintptr(unsafe.Pointer(&bi)), 0, uintptr(unsafe.Pointer(&bits)), 0, 0)
	if bmp == 0 || bits == nil {
		procDeleteDC.Call(dc)
		return nil, errors.New("CreateDIBSection failed")
	}
	c := &textCanvas{w: w, h: h, dc: dc, bmp: bmp}
	c.oldBmp, _, _ = procSelectObject.Call(dc, bmp)
	c.pix = unsafe.Slice((*byte)(bits), w*h*4)
	for i := range c.pix {
		c.pix[i] = 0xFF
	}

	// GD sizes are points at 96 dpi; a negative height asks GDI for that em size in pixels
	height := -int32(math.Round(float64(sizePt) * 96 / 72))
	face, _ := windows.UTF16PtrFromString(family)
	c.font, _, _ = procCreateFontW.Call(uintptr(height), 0, 0, 0, 400, 0, 0, 0,
		defaultCharset, 0, 0, antialiasedQuality, 0, uintptr(unsafe.Pointer(face)))
	c.oldFont, _, _ = procSelectObject.Call(dc, c.font)
	procSetTextColor.Call(dc, 0)
	procSetBkMode.Call(dc, bkTransparent)
	procSetTextAlign.Call(dc, taBaselineLeft)
	return c, nil
}

func (c *textCanvas) close() {
	procSelectObject.Call(c.dc, c.oldFont)
	procSelectObject.Call(c.dc, c.oldBmp)
	procDeleteObject.Call(c.font)
	procDeleteObject.Call(c.bmp)
	procDeleteDC.Call(c.dc)
}

// segments splits text into Arabic word runs and the rest. printer_load.php shapes
// each Arabic run on its own (shape_arabic_text) and GD lays the runs out left to
// right, so drawing run by run gives the same visual order.
func segments(s string) []string {
	var out []string
	last := 0
	for _, loc := range arabicRun.FindAllStringIndex(s, -1) {
		if loc[0] > last {
			out = append(out, s[last:loc[0]])
		}
		out = append(out, s[loc[0]:loc[1]])
		last = loc[1]
	}
	if last < len(s) {
		out = append(out, s[last:])
	}
	return out
}

func (c *textCanvas) runWidth(s string) int {
	u := windows.StringToUTF16(s)
	if len(u) <= 1 {
		return 0
	}
	var sz gdiSize
	procGetTextExtentPoint32W.Call(c.dc, uintptr(unsafe.Pointer(&u[0])), uintptr(len(u)-1), uintptr(unsafe.Pointer(&sz)))
	return int(sz.CX)
}

func (c *textCanvas) width(s string) int {
	total := 0
	for _, seg := range segments(s) {
		total += c.runWidth(seg)
	}
	return total
}

// draw writes text with its baseline at y, like imagettftext. Arabic runs are drawn
// right-to-left on their own, so punctuation inside a run (e.g. "،" at the end of a
// word) lands on the left like the reversed run utf8Glyphs gives GD.
func (c *textCanvas) draw(x, y int, s string) {
	for _, seg := range segments(s) {
		u := windows.StringToUTF16(seg)
		if len(u) > 1 {
			var opts uintptr
			if reArabic.MatchString(seg) {
				opts = etoRTLReading
			}
			procExtTextOutW.Call(c.dc, uintptr(int32(x)), uintptr(int32(y)), opts, 0,
				uintptr(unsafe.Pointer(&u[0])), uintptr(len(u)-1), 0)
		}
		x += c.runWidth(seg)
	}
}

// hline draws a 1px black line like imageline (end point included).
func (c *textCanvas) hline(x1, x2, y int) {
	procGdiFlush.Call()
	if y < 0 || y >= c.h {
		return
	}
	for x := max(0, x1); x <= x2 && x < c.w; x++ {
		i := (y*c.w + x) * 4
		c.pix[i], c.pix[i+1], c.pix[i+2] = 0, 0, 0
	}
}

// raster is imageToRasterCommands(): one GS v 0 block, threshold 160.
func (c *textCanvas) raster() []byte {
	procGdiFlush.Call()
	bytesPerRow := (c.w + 7) / 8
	out := make([]byte, 0, 8+bytesPerRow*c.h)
	out = append(out, 0x1D, 'v', '0', 0, byte(bytesPerRow), byte(bytesPerRow>>8), byte(c.h), byte(c.h>>8))
	for y := 0; y < c.h; y++ {
		for bx := 0; bx < bytesPerRow; bx++ {
			var b byte
			for bit := 0; bit < 8; bit++ {
				x := bx*8 + bit
				if x >= c.w {
					continue
				}
				i := (y*c.w + x) * 4
				gray := int(0.299*float64(c.pix[i+2]) + 0.587*float64(c.pix[i+1]) + 0.114*float64(c.pix[i]))
				if gray < 160 {
					b |= 1 << (7 - bit)
				}
			}
			out = append(out, b)
		}
	}
	return out
}

// renderTextAsImage: one line of text as a raster image (printer_load.php).
func renderTextAsImage(text string, fontSize int, align string) []byte {
	const width = 576
	renderMu.Lock()
	defer renderMu.Unlock()

	lineHeight := int(float64(fontSize) * 1.8)
	c, err := newTextCanvas(width, lineHeight, fontSize)
	if err != nil {
		log.Printf("render text: %v", err)
		return nil
	}
	defer c.close()

	tw := c.width(text)
	x := 5
	switch align {
	case "center":
		x = max(0, (width-tw)/2)
	case "right":
		x = max(0, width-tw-10)
	}
	c.draw(x, int(float64(lineHeight)*0.7), text)
	return c.raster()
}

var (
	reDashLine   = regexp.MustCompile(`^-{3,}$`)
	reEqLine     = regexp.MustCompile(`^={3,}$`)
	reOrderType  = regexp.MustCompile(`(?i)^Order Type`)
	reBillHeader = regexp.MustCompile(`(?i)^Item\s+Rate\s+Qty\s+Total`)
	reKotHeader1 = regexp.MustCompile(`(?i)^(#\s+)?(Qty\s+Item|Item\s+Qty)`)
	reKotHeader2 = regexp.MustCompile(`(?i)^Qty\s+Item`)
	reKotItem    = regexp.MustCompile(`^\s*(\d+)\s{2,}(.+)$`)
	reKotSerial  = regexp.MustCompile(`^\s*(\d+)\s+(\d+)\s+(.+)$`)
	reIndented   = regexp.MustCompile(`^\s{4,}(.+)$`)
	reTwoColumns = regexp.MustCompile(`^(.+?)\s{2,}(.+?)$`)
)

// renderMultiLineAsImage: a block of lines as one raster image (printer_load.php).
func renderMultiLineAsImage(text string, fontSize int, align string) []byte {
	const width = 576
	if !truthy(text) {
		return nil
	}
	renderMu.Lock()
	defer renderMu.Unlock()

	rawLines := splitLines(text)
	lineHeight := int(float64(fontSize) * 1.8)
	padding := 8
	c, err := newTextCanvas(width, len(rawLines)*lineHeight+padding*2, fontSize)
	if err != nil {
		log.Printf("render lines: %v", err)
		return nil
	}
	defer c.close()

	y := padding + fontSize
	rightMargin := width - 12

	for _, line := range rawLines {
		trimmed := phpTrim(line)
		switch {
		case trimmed == "":
		case reDashLine.MatchString(trimmed) || reEqLine.MatchString(trimmed):
			c.hline(10, rightMargin, y-int(float64(fontSize)*0.4))
		case reOrderType.MatchString(trimmed):
			c.draw(10, y, line)
			c.draw(11, y, line)
			c.draw(10, y+1, line)
		case reBillHeader.MatchString(trimmed):
			c.draw(10, y, "Item")
			c.draw(310-c.width("Rate")/2, y, "Rate")
			c.draw(410-c.width("Qty")/2, y, "Qty")
			c.draw(rightMargin-c.width("Total"), y, "Total")
		case reKotHeader1.MatchString(trimmed) || reKotHeader2.MatchString(trimmed):
			c.draw(10, y, "Qty")
			c.draw(100, y, "Item")
		default:
			if m := reKotItem.FindStringSubmatch(line); m != nil && !reKotSerial.MatchString(line) {
				c.draw(10, y, phpTrim(m[1]))
				c.draw(100, y, phpTrim(m[2]))
			} else if m := reKotSerial.FindStringSubmatch(line); m != nil {
				c.draw(10, y, phpTrim(m[2]))
				c.draw(100, y, phpTrim(m[3]))
			} else if m := reIndented.FindStringSubmatch(line); m != nil {
				c.draw(100, y, phpTrim(m[1]))
			} else if m := reTwoColumns.FindStringSubmatch(line); m != nil {
				right := phpTrim(m[2])
				c.draw(10, y, phpTrim(m[1]))
				c.draw(rightMargin-c.width(right), y, right)
			} else {
				tw := c.width(line)
				x := 10
				switch align {
				case "center":
					x = max(10, (width-tw)/2)
				case "right":
					x = max(10, rightMargin-tw)
				}
				c.draw(x, y, line)
			}
		}
		y += lineHeight
	}
	return c.raster()
}
