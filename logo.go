package main

import (
	"bytes"
	"encoding/base64"
	"image"
	"image/color"
	stddraw "image/draw"
	_ "image/gif"
	_ "image/jpeg"
	_ "image/png"
	"os"
	"path/filepath"
	"regexp"

	xdraw "golang.org/x/image/draw"
	_ "golang.org/x/image/webp"
)

// logo resolves the logo like printer_load.php (logo_path, then logo\logo.png / .jpg
// in the data dir) and, when there is no local file, the logo sent by the app.
func (v v1Builder) logo(d batch, mode string) []byte {
	img := v.loadLogo(d)
	if img == nil {
		return nil
	}
	return buildLogoCommands(img, 320, mode)
}

func (v v1Builder) loadLogo(d batch) image.Image {
	logoFile := phpStr(d.pick("logo/logo.png", "logo_path"))
	candidates := []string{}
	if logoFile != "" {
		if filepath.IsAbs(logoFile) {
			candidates = append(candidates, logoFile)
		}
		candidates = append(candidates, filepath.Join(v.dataDir, filepath.FromSlash(logoFile)))
	}
	candidates = append(candidates,
		filepath.Join(v.dataDir, "logo", "logo.png"),
		filepath.Join(v.dataDir, "logo", "logo.jpg"))

	for _, path := range candidates {
		data, err := os.ReadFile(path)
		if err != nil {
			continue
		}
		if img, err := decodeImageLimited(data, maxLogoSide, maxLogoSide); err == nil {
			return img
		}
	}

	if !v.cfg.UseBatchLogo {
		return nil
	}
	if b64 := d.str("logo_base64"); b64 != "" {
		if data := decodeBase64Lenient(b64); data != nil {
			if img, err := decodeImageLimited(data, maxLogoSide, maxLogoSide); err == nil {
				return img
			}
		}
	}
	return nil
}

var reDataURL = regexp.MustCompile(`(?i)^data:image/\w+;base64,`)
var reNotBase64 = regexp.MustCompile(`[^A-Za-z0-9+/]`)

// decodeBase64Lenient behaves like PHP base64_decode (skips characters it does not know).
func decodeBase64Lenient(s string) []byte {
	s = reDataURL.ReplaceAllString(s, "")
	s = reNotBase64.ReplaceAllString(s, "")
	if len(s)%4 == 1 {
		s = s[:len(s)-1]
	}
	data, err := base64.RawStdEncoding.DecodeString(s)
	if err != nil {
		return nil
	}
	return data
}

// toNRGBA gives straight (non-premultiplied) RGB like GD's imagecolorat.
func toNRGBA(img image.Image) *image.NRGBA {
	if n, ok := img.(*image.NRGBA); ok && n.Rect.Min == (image.Point{}) {
		return n
	}
	b := img.Bounds()
	n := image.NewNRGBA(image.Rect(0, 0, b.Dx(), b.Dy()))
	stddraw.Draw(n, n.Rect, img, b.Min, stddraw.Src)
	return n
}

// resampleOnWhite is imagecreatetruecolor + white fill + imagecopyresampled.
func resampleOnWhite(src image.Image, srcRect image.Rectangle, w, h int) *image.RGBA {
	dst := image.NewRGBA(image.Rect(0, 0, w, h))
	stddraw.Draw(dst, dst.Rect, image.NewUniform(color.White), image.Point{}, stddraw.Src)
	if srcRect.Dx() == w && srcRect.Dy() == h {
		stddraw.Draw(dst, dst.Rect, src, srcRect.Min, stddraw.Over)
	} else {
		xdraw.BiLinear.Scale(dst, dst.Rect, src, srcRect, xdraw.Over, nil)
	}
	return dst
}

func grayAt(img *image.RGBA, x, y int) int {
	i := img.PixOffset(x, y)
	return int(0.299*float64(img.Pix[i]) + 0.587*float64(img.Pix[i+1]) + 0.114*float64(img.Pix[i+2]))
}

// buildLogoCommands: same trimming, resizing and bit-image modes as printer_load.php.
func buildLogoCommands(img image.Image, maxWidth int, mode string) []byte {
	src := toNRGBA(img)
	srcW, srcH := src.Rect.Dx(), src.Rect.Dy()
	if srcW == 0 || srcH == 0 {
		return nil
	}
	isBlack := func(x, y int) bool {
		i := src.PixOffset(x, y)
		return src.Pix[i] < 100 && src.Pix[i+1] < 100 && src.Pix[i+2] < 100
	}

	// Trim outer black border lines (left columns, bottom rows)
	minX, maxY := 0, srcH-1
	for x := 0; x < min(10, srcW); x++ {
		count := 0
		for y := 0; y < srcH; y++ {
			if isBlack(x, y) {
				count++
			}
		}
		if float64(count) > float64(srcH)*0.7 {
			minX = x + 1
		} else {
			break
		}
	}
	for y := srcH - 1; y > max(0, srcH-10); y-- {
		count := 0
		for x := 0; x < srcW; x++ {
			if isBlack(x, y) {
				count++
			}
		}
		if float64(count) > float64(srcW)*0.7 {
			maxY = y - 1
		} else {
			break
		}
	}

	cropW := max(1, srcW-minX)
	cropH := max(1, maxY+1)
	newW, newH := cropW, cropH
	if cropW > maxWidth {
		newW = maxWidth
		newH = int(float64(cropH)/float64(cropW)*float64(maxWidth) + 0.5)
	}
	resized := resampleOnWhite(src, image.Rect(minX, 0, minX+cropW, cropH), newW, newH)

	var buf bytes.Buffer
	buf.WriteString("\x1Ba\x01") // center

	if mode == "gs_v0" {
		bytesPerRow := (newW + 7) / 8
		buf.Write([]byte{0x1D, 'v', '0', 0, byte(bytesPerRow), byte(bytesPerRow >> 8), byte(newH), byte(newH >> 8)})
		for y := 0; y < newH; y++ {
			for bx := 0; bx < bytesPerRow; bx++ {
				var b byte
				for bit := 0; bit < 8; bit++ {
					x := bx*8 + bit
					if x < newW && grayAt(resized, x, y) < 128 {
						b |= 1 << (7 - bit)
					}
				}
				buf.WriteByte(b)
			}
		}
		return buf.Bytes()
	}

	// ESC * 33: 24-dot double density column bit-image
	buf.WriteString("\x1B3\x18")
	for y := 0; y < newH; y += 24 {
		buf.Write([]byte{0x1B, '*', 0x21, byte(newW), byte(newW >> 8)})
		for x := 0; x < newW; x++ {
			for k := 0; k < 3; k++ {
				var b byte
				for bit := 0; bit < 8; bit++ {
					py := y + k*8 + bit
					if py < newH && grayAt(resized, x, py) < 128 {
						b |= 1 << (7 - bit)
					}
				}
				buf.WriteByte(b)
			}
		}
		buf.WriteByte(0x0A)
	}
	buf.WriteString("\x1B2")
	return buf.Bytes()
}
