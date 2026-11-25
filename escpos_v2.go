package main

import (
	"bytes"
	"errors"
	"image"
)

// Size limits checked before decoding: a tiny PNG can claim huge dimensions and
// would otherwise make the decoder allocate gigabytes.
const (
	maxReceiptW = 4096
	maxReceiptH = 30000 // about 3.7 m of paper at 203 dpi
	maxLogoSide = 4096
)

func decodeImageLimited(data []byte, maxW, maxH int) (image.Image, error) {
	cfg, _, err := image.DecodeConfig(bytes.NewReader(data))
	if err != nil {
		return nil, errors.New("Invalid image format - cannot parse PNG")
	}
	if cfg.Width <= 0 || cfg.Height <= 0 || cfg.Width > maxW || cfg.Height > maxH {
		return nil, errors.New("Image too large")
	}
	img, _, err := image.Decode(bytes.NewReader(data))
	if err != nil {
		return nil, errors.New("Invalid image format - cannot parse PNG")
	}
	return img, nil
}

// Blank runs shorter than this stay in the picture: a feed command for a few dots
// saves nothing and keeps the receipt identical to the PHP output.
const minBlankFeed = 24 // dots, 3 mm at 203 dpi

// Port of print_server/include/printer_load_v2.php: a receipt drawn by the
// browser (576px canvas PNG) turned into GS v 0 raster bands.
//
// With skipBlank, runs of white rows (often a third of a receipt) are sent as
// ESC J paper feeds instead of picture rows. Thermal printers print pictures
// slowly but feed blank paper at full speed, so the receipt comes out faster
// and looks the same. Without it the bytes match printer_load_v2.php exactly.
func buildV2(imageBase64 string, openDrawer, skipBlank bool) ([]byte, error) {
	data := decodeBase64Lenient(imageBase64)
	if len(data) == 0 {
		return nil, errors.New("Failed to decode base64 image")
	}
	img, err := decodeImageLimited(data, maxReceiptW, maxReceiptH)
	if err != nil {
		return nil, err
	}

	const targetW = 576
	src := toNRGBA(img)
	w, h := src.Rect.Dx(), src.Rect.Dy()
	gray := func(x, y int) int {
		i := src.PixOffset(x, y)
		return int(0.299*float64(src.Pix[i]) + 0.587*float64(src.Pix[i+1]) + 0.114*float64(src.Pix[i+2]))
	}
	if w != targetW {
		scaledH := int(float64(h)*(float64(targetW)/float64(w)) + 0.5)
		resized := resampleOnWhite(src, src.Rect, targetW, scaledH)
		w, h = targetW, scaledH
		gray = func(x, y int) int { return grayAt(resized, x, y) }
	}

	bytesPerRow := (w + 7) / 8

	// 1 bit per dot, black where the pixel is darker than 185
	raster := make([]byte, h*bytesPerRow)
	blank := make([]bool, h)
	for y := 0; y < h; y++ {
		row := raster[y*bytesPerRow : (y+1)*bytesPerRow]
		empty := true
		for bx := range row {
			var b byte
			for bit := 0; bit < 8; bit++ {
				x := bx*8 + bit
				if x < w && gray(x, y) < 185 {
					b |= 1 << (7 - bit)
				}
			}
			row[bx] = b
			if b != 0 {
				empty = false
			}
		}
		blank[y] = empty
	}

	var buf bytes.Buffer
	buf.WriteString("\x1B@")
	buf.WriteString("\x1Ba\x01")
	if openDrawer {
		buf.WriteString("\x1Bp\x00\x19\xFA") // pin 2
		buf.WriteString("\x1Bp\x01\x19\xFA") // and pin 5, like v1, for drawers wired to pin 5
	}

	if !skipBlank {
		writeBands(&buf, raster, bytesPerRow, 0, h)
	} else {
		start := 0 // first row not written yet
		for y := 0; y < h; {
			if !blank[y] {
				y++
				continue
			}
			end := y
			for end < h && blank[end] {
				end++
			}
			if end-y >= minBlankFeed {
				writeBands(&buf, raster, bytesPerRow, start, y)
				for n := end - y; n > 0; n -= 255 {
					buf.Write([]byte{0x1B, 'J', byte(min(n, 255))}) // feed n dots
				}
				start = end
			}
			y = end
		}
		writeBands(&buf, raster, bytesPerRow, start, h)
	}

	buf.WriteString("\x1Bd\x04")     // feed 4 lines
	buf.WriteString("\x1DV\x41\x00") // feed + cut
	return buf.Bytes(), nil
}

// writeBands sends rows [from, to) as GS v 0 pictures of at most 256 rows each.
func writeBands(buf *bytes.Buffer, raster []byte, bytesPerRow, from, to int) {
	const chunkHeight = 256
	for y := from; y < to; y += chunkHeight {
		sliceH := min(chunkHeight, to-y)
		buf.Write([]byte{0x1D, 'v', '0', 0, byte(bytesPerRow), byte(bytesPerRow >> 8), byte(sliceH), byte(sliceH >> 8)})
		buf.Write(raster[y*bytesPerRow : (y+sliceH)*bytesPerRow])
	}
}
