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

// Port of print_server/include/printer_load_v2.php: a receipt drawn by the
// browser (576px canvas PNG) turned into GS v 0 raster bands.
func buildV2(imageBase64 string, openDrawer bool) ([]byte, error) {
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
	const chunkHeight = 256

	var buf bytes.Buffer
	buf.WriteString("\x1B@")
	buf.WriteString("\x1Ba\x01")
	if openDrawer {
		buf.WriteString("\x1Bp\x00\x19\xFA") // pin 2
	}

	for y := 0; y < h; y += chunkHeight {
		sliceH := min(chunkHeight, h-y)
		buf.Write([]byte{0x1D, 'v', '0', 0, byte(bytesPerRow), byte(bytesPerRow >> 8), byte(sliceH), byte(sliceH >> 8)})
		for row := 0; row < sliceH; row++ {
			for bx := 0; bx < bytesPerRow; bx++ {
				var b byte
				for bit := 0; bit < 8; bit++ {
					x := bx*8 + bit
					if x < w && gray(x, y+row) < 185 {
						b |= 1 << (7 - bit)
					}
				}
				buf.WriteByte(b)
			}
		}
	}

	buf.WriteString("\x1Bd\x04")     // feed 4 lines
	buf.WriteString("\x1DV\x41\x00") // feed + cut
	return buf.Bytes(), nil
}
