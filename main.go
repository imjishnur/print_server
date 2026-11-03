//go:build windows

// Print Server: drop-in replacement for the Laragon print server.
//
// It serves the same URL as Laragon (/print_server/restpos_printer_server.php)
// and prints the same ESC/POS bytes, so the POS works with no change.
package main

import (
	"flag"
	"fmt"
	"log"
	"os"
	"os/signal"

	"golang.org/x/sys/windows"
)

var version = "0.1.0"

func main() {
	console := flag.Bool("console", false, "run without tray icon and log to the terminal (development)")
	installCert := flag.Bool("install-cert", false, "create the local CA and trust it in Windows (run as administrator)")
	uninstallCert := flag.Bool("uninstall-cert", false, "remove the local CA from the Windows trust store (run as administrator)")
	httpPort := flag.Int("http-port", 0, "override http_port from config.json")
	httpsPort := flag.Int("https-port", 0, "override https_port from config.json")
	checkPorts := flag.Bool("check-ports", false, "exit 1 if the configured ports are taken by another program (used by the installer)")
	flag.Parse()

	dir := dataDir()
	setupLogging(dir, *console)
	cfg := loadConfig(dir)
	if *httpPort > 0 {
		cfg.HTTPPort = *httpPort
	}
	if *httpsPort > 0 {
		cfg.HTTPSPort = *httpsPort
	}

	switch {
	case *checkPorts:
		if busy := busyPorts(cfg); len(busy) > 0 {
			log.Printf("ports in use: %v", busy)
			os.Exit(1)
		}
		return
	case *installCert:
		if err := installCA(dir); err != nil {
			log.Printf("install-cert failed: %v", err)
			os.Exit(1)
		}
		log.Println("local CA installed in Windows trust store")
		return
	case *uninstallCert:
		if err := uninstallCA(); err != nil {
			log.Printf("uninstall-cert failed: %v", err)
			os.Exit(1)
		}
		log.Println("local CA removed from Windows trust store")
		return
	}

	if !acquireSingleInstance() {
		// Clicking the shortcut again shows the status page instead of doing nothing
		log.Println("Print Server is already running")
		if !*console {
			shellOpen((&Agent{cfg: cfg}).statusURL())
		}
		return
	}

	agent := newAgent(cfg, dir)
	agent.start()
	log.Printf("Print Server %s started (data dir %s)", version, dir)

	if *console {
		fmt.Printf("Print Server %s\n  %s\n  Ctrl+C to stop\n", version, agent.statusLine())
		stop := make(chan os.Signal, 1)
		signal.Notify(stop, os.Interrupt)
		<-stop
		return
	}
	runTray(agent)
}

func busyPorts(cfg Config) []int {
	ports := []int{cfg.HTTPPort}
	if cfg.EnableHTTPS {
		ports = append(ports, cfg.HTTPSPort)
	}
	var busy []int
	for _, p := range ports {
		ln, err := listenIPv4(p)
		if err != nil {
			busy = append(busy, p)
			continue
		}
		ln.Close()
	}
	return busy
}

// acquireSingleInstance stops a second copy from fighting over ports 80/443.
func acquireSingleInstance() bool {
	name, _ := windows.UTF16PtrFromString(`Local\PrintServerAgent`)
	_, err := windows.CreateMutex(nil, false, name)
	return err != windows.ERROR_ALREADY_EXISTS
}
