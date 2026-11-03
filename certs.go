//go:build windows

package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"errors"
	"fmt"
	"math/big"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"time"
)

// HTTPS: a local "Print Server Local CA" is created once and trusted in Windows by
// the installer (--install-cert, admin). The server certificate is issued from it in
// memory for localhost + this PC's LAN IPs and re-issued when an IP changes, so no
// admin rights are needed after install.
//
// The CA is name-constrained to localhost and private IP ranges, so its key can
// never be used to impersonate a public website.

const caCommonName = "Print Server Local CA"

type certManager struct {
	dir string

	mu        sync.Mutex
	ca        *x509.Certificate
	caKey     *ecdsa.PrivateKey
	leaf      *tls.Certificate
	leafIPs   string
	checkedAt time.Time
}

func caPaths(dir string) (certPath, keyPath string) {
	return filepath.Join(dir, "certs", "print-server-ca.crt"), filepath.Join(dir, "certs", "print-server-ca.key")
}

func newCertManager(dir string) (*certManager, error) {
	ca, key, err := loadOrCreateCA(dir)
	if err != nil {
		return nil, err
	}
	return &certManager{dir: dir, ca: ca, caKey: key}, nil
}

func loadOrCreateCA(dir string) (*x509.Certificate, *ecdsa.PrivateKey, error) {
	certPath, keyPath := caPaths(dir)
	if ca, key, err := readCA(certPath, keyPath); err == nil {
		return ca, key, nil
	}

	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, nil, err
	}
	serial, _ := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 120))
	_, private10, _ := net.ParseCIDR("10.0.0.0/8")
	_, private172, _ := net.ParseCIDR("172.16.0.0/12")
	_, private192, _ := net.ParseCIDR("192.168.0.0/16")
	_, linkLocal, _ := net.ParseCIDR("169.254.0.0/16")
	_, loopback, _ := net.ParseCIDR("127.0.0.0/8")
	_, loopback6, _ := net.ParseCIDR("::1/128")
	_, ula6, _ := net.ParseCIDR("fc00::/7")

	tmpl := &x509.Certificate{
		SerialNumber:                serial,
		Subject:                     pkix.Name{CommonName: caCommonName, Organization: []string{"Print Server"}},
		NotBefore:                   time.Now().Add(-time.Hour),
		NotAfter:                    time.Now().AddDate(20, 0, 0),
		KeyUsage:                    x509.KeyUsageCertSign | x509.KeyUsageCRLSign,
		BasicConstraintsValid:       true,
		IsCA:                        true,
		MaxPathLenZero:              true,
		PermittedDNSDomainsCritical: true,
		PermittedDNSDomains:         []string{"localhost"},
		PermittedIPRanges:           []*net.IPNet{private10, private172, private192, linkLocal, loopback, loopback6, ula6},
	}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, tmpl, &key.PublicKey, key)
	if err != nil {
		return nil, nil, err
	}
	keyDER, err := x509.MarshalECPrivateKey(key)
	if err != nil {
		return nil, nil, err
	}

	if err := os.MkdirAll(filepath.Dir(certPath), 0o755); err != nil {
		return nil, nil, err
	}
	if err := os.WriteFile(certPath, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), 0o644); err != nil {
		return nil, nil, err
	}
	if err := os.WriteFile(keyPath, pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: keyDER}), 0o600); err != nil {
		return nil, nil, err
	}
	ca, err := x509.ParseCertificate(der)
	return ca, key, err
}

func readCA(certPath, keyPath string) (*x509.Certificate, *ecdsa.PrivateKey, error) {
	certPEM, err := os.ReadFile(certPath)
	if err != nil {
		return nil, nil, err
	}
	keyPEM, err := os.ReadFile(keyPath)
	if err != nil {
		return nil, nil, err
	}
	cb, _ := pem.Decode(certPEM)
	kb, _ := pem.Decode(keyPEM)
	if cb == nil || kb == nil {
		return nil, nil, errors.New("invalid CA files")
	}
	ca, err := x509.ParseCertificate(cb.Bytes)
	if err != nil {
		return nil, nil, err
	}
	key, err := x509.ParseECPrivateKey(kb.Bytes)
	if err != nil {
		return nil, nil, err
	}
	return ca, key, nil
}

// lanIPs lists this PC's private IPv4 addresses (the ones tablets and the POS use).
func lanIPs() []net.IP {
	var ips []net.IP
	ifaces, _ := net.Interfaces()
	for _, iface := range ifaces {
		if iface.Flags&net.FlagUp == 0 || iface.Flags&net.FlagLoopback != 0 {
			continue
		}
		addrs, _ := iface.Addrs()
		for _, a := range addrs {
			ipNet, ok := a.(*net.IPNet)
			if !ok {
				continue
			}
			if ip4 := ipNet.IP.To4(); ip4 != nil && (ip4.IsPrivate() || ip4.IsLinkLocalUnicast()) {
				ips = append(ips, ip4)
			}
		}
	}
	return ips
}

func ipKey(ips []net.IP) string {
	s := make([]string, len(ips))
	for i, ip := range ips {
		s[i] = ip.String()
	}
	slices.Sort(s)
	return strings.Join(s, ",")
}

func (m *certManager) getCertificate(*tls.ClientHelloInfo) (*tls.Certificate, error) {
	m.mu.Lock()
	defer m.mu.Unlock()

	if m.leaf != nil && time.Since(m.checkedAt) < time.Minute {
		return m.leaf, nil
	}
	m.checkedAt = time.Now()
	ips := lanIPs()
	key := ipKey(ips)
	if m.leaf != nil && key == m.leafIPs && time.Until(m.leaf.Leaf.NotAfter) > 30*24*time.Hour {
		return m.leaf, nil
	}

	leaf, err := m.issue(ips)
	if err != nil {
		return nil, err
	}
	m.leaf, m.leafIPs = leaf, key
	return leaf, nil
}

func (m *certManager) issue(ips []net.IP) (*tls.Certificate, error) {
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, err
	}
	serial, _ := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 120))
	tmpl := &x509.Certificate{
		SerialNumber: serial,
		Subject:      pkix.Name{CommonName: "Print Server"},
		NotBefore:    time.Now().Add(-time.Hour),
		NotAfter:     time.Now().AddDate(0, 0, 397),
		KeyUsage:     x509.KeyUsageDigitalSignature,
		ExtKeyUsage:  []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		DNSNames:     []string{"localhost"},
		IPAddresses:  append([]net.IP{net.ParseIP("127.0.0.1"), net.IPv6loopback}, ips...),
	}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, m.ca, &key.PublicKey, m.caKey)
	if err != nil {
		return nil, err
	}
	leaf, err := x509.ParseCertificate(der)
	if err != nil {
		return nil, err
	}
	return &tls.Certificate{Certificate: [][]byte{der, m.ca.Raw}, PrivateKey: key, Leaf: leaf}, nil
}

// installCA trusts the local CA for every user on this PC (needs administrator).
func installCA(dir string) error {
	if _, _, err := loadOrCreateCA(dir); err != nil {
		return err
	}
	certPath, _ := caPaths(dir)
	out, err := exec.Command("certutil", "-addstore", "-f", "Root", certPath).CombinedOutput()
	if err != nil {
		return fmt.Errorf("certutil: %v: %s", err, out)
	}
	return nil
}

func uninstallCA() error {
	out, err := exec.Command("certutil", "-delstore", "Root", caCommonName).CombinedOutput()
	if err != nil {
		return fmt.Errorf("certutil: %v: %s", err, out)
	}
	return nil
}
