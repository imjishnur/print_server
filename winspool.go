//go:build windows

package main

import (
	"fmt"
	"strings"
	"unsafe"

	"golang.org/x/sys/windows"
)

var (
	winspool             = windows.NewLazySystemDLL("winspool.drv")
	procEnumPrintersW    = winspool.NewProc("EnumPrintersW")
	procOpenPrinterW     = winspool.NewProc("OpenPrinterW")
	procClosePrinter     = winspool.NewProc("ClosePrinter")
	procStartDocPrinterW = winspool.NewProc("StartDocPrinterW")
	procEndDocPrinter    = winspool.NewProc("EndDocPrinter")
	procStartPagePrinter = winspool.NewProc("StartPagePrinter")
	procEndPagePrinter   = winspool.NewProc("EndPagePrinter")
	procWritePrinter     = winspool.NewProc("WritePrinter")
)

const (
	printerEnumLocal       = 0x2
	printerEnumConnections = 0x4
	printerAttributeShared = 0x8
)

// PRINTER_INFO_2W
type printerInfo2 struct {
	ServerName         *uint16
	PrinterName        *uint16
	ShareName          *uint16
	PortName           *uint16
	DriverName         *uint16
	Comment            *uint16
	Location           *uint16
	DevMode            uintptr
	SepFile            *uint16
	PrintProcessor     *uint16
	Datatype           *uint16
	Parameters         *uint16
	SecurityDescriptor uintptr
	Attributes         uint32
	Priority           uint32
	DefaultPriority    uint32
	StartTime          uint32
	UntilTime          uint32
	Status             uint32
	Jobs               uint32
	AveragePPM         uint32
}

// DOC_INFO_1W
type docInfo1 struct {
	DocName    *uint16
	OutputFile *uint16
	Datatype   *uint16
}

type Printer struct {
	Name      string
	ShareName string
	Shared    bool
	Status    uint32
}

func listPrinters() ([]Printer, error) {
	flags := uintptr(printerEnumLocal | printerEnumConnections)
	var needed, returned uint32
	procEnumPrintersW.Call(flags, 0, 2, 0, 0, uintptr(unsafe.Pointer(&needed)), uintptr(unsafe.Pointer(&returned)))
	if needed == 0 {
		return nil, nil
	}
	buf := make([]byte, needed)
	r1, _, err := procEnumPrintersW.Call(flags, 0, 2, uintptr(unsafe.Pointer(&buf[0])), uintptr(needed),
		uintptr(unsafe.Pointer(&needed)), uintptr(unsafe.Pointer(&returned)))
	if r1 == 0 {
		return nil, fmt.Errorf("EnumPrinters: %w", err)
	}

	infos := unsafe.Slice((*printerInfo2)(unsafe.Pointer(&buf[0])), returned)
	printers := make([]Printer, 0, returned)
	for _, in := range infos {
		printers = append(printers, Printer{
			Name:      windows.UTF16PtrToString(in.PrinterName),
			ShareName: windows.UTF16PtrToString(in.ShareName),
			Shared:    in.Attributes&printerAttributeShared != 0,
			Status:    in.Status,
		})
	}
	return printers, nil
}

// findPrinter matches the name the POS sends (Windows share name or printer name).
func findPrinter(name string) (Printer, bool) {
	printers, err := listPrinters()
	if err != nil {
		return Printer{}, false
	}
	for _, p := range printers {
		if strings.EqualFold(p.ShareName, name) {
			return p, true
		}
	}
	for _, p := range printers {
		if strings.EqualFold(p.Name, name) {
			return p, true
		}
	}
	return Printer{}, false
}

// rawPrint sends bytes straight to the printer through the Windows spooler.
func rawPrint(printerName, docName string, data []byte) error {
	if len(data) == 0 {
		return nil
	}
	name, err := windows.UTF16PtrFromString(printerName)
	if err != nil {
		return err
	}
	var h windows.Handle
	if r1, _, err := procOpenPrinterW.Call(uintptr(unsafe.Pointer(name)), uintptr(unsafe.Pointer(&h)), 0); r1 == 0 {
		return fmt.Errorf("OpenPrinter %q: %w", printerName, err)
	}
	defer procClosePrinter.Call(uintptr(h))

	doc, _ := windows.UTF16PtrFromString(docName)
	var job uintptr
	var startErr error
	// v4 (XPS) drivers reject RAW; XPS_PASS sends the bytes through unchanged.
	for _, dt := range []string{"RAW", "XPS_PASS"} {
		datatype, _ := windows.UTF16PtrFromString(dt)
		info := docInfo1{DocName: doc, Datatype: datatype}
		job, _, startErr = procStartDocPrinterW.Call(uintptr(h), 1, uintptr(unsafe.Pointer(&info)))
		if job != 0 {
			break
		}
	}
	if job == 0 {
		return fmt.Errorf("StartDocPrinter %q: %w", printerName, startErr)
	}
	defer procEndDocPrinter.Call(uintptr(h))

	if r1, _, err := procStartPagePrinter.Call(uintptr(h)); r1 == 0 {
		return fmt.Errorf("StartPagePrinter %q: %w", printerName, err)
	}
	var written uint32
	r1, _, err := procWritePrinter.Call(uintptr(h), uintptr(unsafe.Pointer(&data[0])), uintptr(len(data)), uintptr(unsafe.Pointer(&written)))
	procEndPagePrinter.Call(uintptr(h))
	if r1 == 0 {
		return fmt.Errorf("WritePrinter %q: %w", printerName, err)
	}
	if int(written) != len(data) {
		return fmt.Errorf("WritePrinter %q: wrote %d of %d bytes", printerName, written, len(data))
	}
	return nil
}
