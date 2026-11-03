package main

import (
	"bytes"
	"encoding/binary"
)

// trayIcon draws a 32x32 printer icon as an .ico (blue when running, red on error).
func trayIcon(ok bool) []byte {
	const size = 32
	bg := [3]byte{0xEB, 0x63, 0x25} // BGR #2563EB
	if !ok {
		bg = [3]byte{0x1C, 0x1C, 0xB9} // #B91C1C
	}

	pix := make([]byte, size*size*4) // BGRA, bottom-up rows
	set := func(x, y int, c [3]byte) {
		i := ((size-1-y)*size + x) * 4
		pix[i], pix[i+1], pix[i+2], pix[i+3] = c[0], c[1], c[2], 0xFF
	}
	white := [3]byte{0xFF, 0xFF, 0xFF}
	for y := 0; y < size; y++ {
		for x := 0; x < size; x++ {
			// rounded square
			dx, dy := min(x, size-1-x), min(y, size-1-y)
			if dx+dy < 4 && dx < 3 && dy < 3 {
				continue
			}
			set(x, y, bg)
		}
	}
	for y := 6; y <= 12; y++ { // paper in
		for x := 10; x <= 21; x++ {
			set(x, y, white)
		}
	}
	for y := 13; y <= 21; y++ { // printer body
		for x := 6; x <= 25; x++ {
			if y == 13 || y == 21 || x == 6 || x == 25 {
				set(x, y, white)
			}
		}
	}
	for y := 18; y <= 26; y++ { // paper out
		for x := 10; x <= 21; x++ {
			set(x, y, white)
		}
	}

	mask := make([]byte, size*4) // AND mask, all zero (alpha channel is used)
	var img bytes.Buffer
	binary.Write(&img, binary.LittleEndian, struct {
		Size, Width, Height              int32
		Planes, BitCount                 uint16
		Compression, SizeImage           uint32
		XPels, YPels, ClrUsed, ClrImport int32
	}{40, size, size * 2, 1, 32, 0, uint32(len(pix) + len(mask)), 0, 0, 0, 0})
	img.Write(pix)
	img.Write(mask)

	var ico bytes.Buffer
	binary.Write(&ico, binary.LittleEndian, struct{ Reserved, Type, Count uint16 }{0, 1, 1})
	binary.Write(&ico, binary.LittleEndian, struct {
		W, H, Colors, Reserved uint8
		Planes, BitCount       uint16
		Bytes, Offset          uint32
	}{size, size, 0, 0, 1, 32, uint32(img.Len()), 22})
	ico.Write(img.Bytes())
	return ico.Bytes()
}
