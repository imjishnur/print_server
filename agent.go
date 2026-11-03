//go:build windows

package main

import (
	"crypto/tls"
	"fmt"
	"log"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"
)

type listenerStatus struct {
	Port    int    `json:"port"`
	Running bool   `json:"running"`
	Error   string `json:"error,omitempty"`
}

type Agent struct {
	cfg   Config
	dir   string
	v1    v1Builder
	certs *certManager

	mu      sync.Mutex
	http    listenerStatus
	https   listenerStatus
	servers []*http.Server
	stopped bool

	printLocks sync.Map // printer name / ip:port -> *sync.Mutex, one job at a time per printer
	jobs       jobLog

	// Delivery to the printer; tests replace these with fakes.
	winSend  func(name string, data []byte) error
	sockSend func(ip string, port int, data []byte, timeout time.Duration) error
}

func newAgent(cfg Config, dir string) *Agent {
	return &Agent{cfg: cfg, dir: dir, v1: v1Builder{cfg: cfg, dataDir: dir}, winSend: sendWindows, sockSend: sendSocket}
}

func (a *Agent) start() {
	a.mu.Lock()
	defer a.mu.Unlock()
	a.stopped = false
	mux := a.routes()

	a.http = a.listen(a.cfg.HTTPPort, mux, nil)

	if a.cfg.EnableHTTPS {
		if a.certs == nil {
			certs, err := newCertManager(a.dir)
			if err != nil {
				a.https = listenerStatus{Port: a.cfg.HTTPSPort, Error: err.Error()}
				log.Printf("https disabled: %v", err)
				return
			}
			a.certs = certs
		}
		tlsCfg := &tls.Config{GetCertificate: a.certs.getCertificate, MinVersion: tls.VersionTLS12}
		a.https = a.listen(a.cfg.HTTPSPort, mux, tlsCfg)
	}
}

// stop closes the listeners so ports 80/443 are free (e.g. to run Laragon again).
func (a *Agent) stop() {
	a.mu.Lock()
	defer a.mu.Unlock()
	for _, srv := range a.servers {
		_ = srv.Close()
	}
	a.servers = nil
	a.stopped = true
	a.http.Running, a.https.Running = false, false
	log.Println("server stopped from tray")
}

func (a *Agent) isStopped() bool {
	a.mu.Lock()
	defer a.mu.Unlock()
	return a.stopped
}

// listenIPv4 is the listener that matters: the POS and tablets connect over IPv4.
// A plain ":port" listen on Windows is a dual-stack IPv6 socket, which binds even
// when another program (e.g. Laragon's Apache) already holds 0.0.0.0:port.
func listenIPv4(port int) (net.Listener, error) {
	return net.Listen("tcp4", "0.0.0.0:"+strconv.Itoa(port))
}

func (a *Agent) listen(port int, handler http.Handler, tlsCfg *tls.Config) listenerStatus {
	st := listenerStatus{Port: port}
	ln, err := listenIPv4(port)
	if err != nil {
		st.Error = err.Error()
		log.Printf("cannot listen on port %d: %v", port, err)
		return st
	}
	listeners := []net.Listener{ln}
	if ln6, err := net.Listen("tcp6", "[::]:"+strconv.Itoa(port)); err == nil {
		listeners = append(listeners, ln6)
	}

	srv := &http.Server{
		Handler:           handler,
		ReadHeaderTimeout: 10 * time.Second,
		ErrorLog:          log.New(logDiscard{}, "", 0), // TLS handshake noise from browsers
	}
	a.servers = append(a.servers, srv)
	for _, l := range listeners {
		if tlsCfg != nil {
			l = tls.NewListener(l, tlsCfg)
		}
		go func() {
			if err := srv.Serve(l); err != nil {
				log.Printf("server on %s stopped: %v", l.Addr(), err)
			}
		}()
	}
	st.Running = true
	log.Printf("listening on port %d (tls=%v, ipv6=%v)", port, tlsCfg != nil, len(listeners) > 1)
	return st
}

type logDiscard struct{}

func (logDiscard) Write(p []byte) (int, error) { return len(p), nil }

func (a *Agent) statusLine() string {
	a.mu.Lock()
	defer a.mu.Unlock()
	if a.stopped {
		return "Server stopped (ports free)"
	}
	parts := []string{}
	for _, st := range []struct {
		name string
		s    listenerStatus
	}{{"HTTP", a.http}, {"HTTPS", a.https}} {
		if st.s.Port == 0 {
			continue
		}
		state := "running"
		if !st.s.Running {
			state = "error: " + st.s.Error
		}
		parts = append(parts, fmt.Sprintf("%s :%d %s", st.name, st.s.Port, state))
	}
	if !a.cfg.EnableHTTPS {
		parts = append(parts, "HTTPS off")
	}
	return strings.Join(parts, " | ")
}

func (a *Agent) healthy() bool {
	a.mu.Lock()
	defer a.mu.Unlock()
	return a.http.Running && (!a.cfg.EnableHTTPS || a.https.Running)
}

func (a *Agent) statusURL() string {
	if a.cfg.HTTPPort == 80 {
		return "http://localhost/print_server/"
	}
	return fmt.Sprintf("http://localhost:%d/print_server/", a.cfg.HTTPPort)
}

func (a *Agent) printLogURL() string { return strings.TrimSuffix(a.statusURL(), "/") + "/log" }

func (a *Agent) logsDir() string { return filepath.Join(a.dir, "logs") }

func (a *Agent) lockPrinter(name string) func() {
	m, _ := a.printLocks.LoadOrStore(strings.ToLower(name), &sync.Mutex{})
	mu := m.(*sync.Mutex)
	mu.Lock()
	return mu.Unlock
}

// printWindows and printSocket send one job at a time per printer, so jobs from
// several tablets never mix.
func (a *Agent) printWindows(name string, data []byte) error {
	if name == "" {
		return fmt.Errorf("no printer name")
	}
	unlock := a.lockPrinter(name)
	defer unlock()
	return a.winSend(name, data)
}

func (a *Agent) printSocket(ip string, port int, data []byte, timeout time.Duration) error {
	unlock := a.lockPrinter(net.JoinHostPort(ip, strconv.Itoa(port)))
	defer unlock()
	return a.sockSend(ip, port, data, timeout)
}

// sendSocket prints to a network printer (port 9100).
func sendSocket(ip string, port int, data []byte, timeout time.Duration) error {
	conn, err := net.DialTimeout("tcp", net.JoinHostPort(ip, strconv.Itoa(port)), timeout)
	if err != nil {
		return err
	}
	defer conn.Close()
	_ = conn.SetWriteDeadline(time.Now().Add(15 * time.Second))
	_, err = conn.Write(data)
	return err
}

// sendWindows prints to a Windows printer by share name or printer name. Printer
// sharing is not needed; the \\127.0.0.1\share path is kept as a last resort like
// the PHP server.
func sendWindows(name string, data []byte) error {
	if p, ok := findPrinter(name); ok {
		err := rawPrint(p.Name, "Print Server", data)
		if err == nil {
			return nil
		}
		log.Printf("spooler print to %q failed: %v", p.Name, err)
	}
	path := `\\127.0.0.1\` + strings.ReplaceAll(name, "/", `\`)
	f, err := os.OpenFile(path, os.O_WRONLY, 0)
	if err != nil {
		return fmt.Errorf("printer %q not found", name)
	}
	defer f.Close()
	_, err = f.Write(data)
	return err
}
