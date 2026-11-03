//go:build windows

package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"math/big"
	"net"
	"testing"
	"time"
)

func TestCertificateTrustedForThisPC(t *testing.T) {
	m, err := newCertManager(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	cert, err := m.getCertificate(nil)
	if err != nil {
		t.Fatal(err)
	}
	roots := x509.NewCertPool()
	roots.AddCert(m.ca)

	names := []string{"localhost", "127.0.0.1"}
	for _, ip := range lanIPs() {
		names = append(names, ip.String())
	}
	for _, name := range names {
		if _, err := cert.Leaf.Verify(x509.VerifyOptions{DNSName: name, Roots: roots}); err != nil {
			t.Errorf("certificate not valid for %s: %v", name, err)
		}
	}
	if d := time.Until(cert.Leaf.NotAfter); d < 390*24*time.Hour || d > 398*24*time.Hour {
		t.Errorf("certificate lifetime %v, want about 397 days (browser limit 398)", d)
	}

	again, _ := m.getCertificate(nil)
	if again != cert {
		t.Error("certificate should be reused, not re-issued on every connection")
	}
}

// The local CA is name-constrained: even with its key, nobody can make a
// certificate a browser accepts for a public website.
func TestCACannotSignPublicWebsites(t *testing.T) {
	m, err := newCertManager(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	roots := x509.NewCertPool()
	roots.AddCert(m.ca)

	for _, target := range []struct {
		dns string
		ip  net.IP
	}{{"google.com", nil}, {"", net.ParseIP("8.8.8.8")}} {
		key, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
		tmpl := &x509.Certificate{
			SerialNumber: big.NewInt(time.Now().UnixNano()),
			Subject:      pkix.Name{CommonName: "evil"},
			NotBefore:    time.Now().Add(-time.Hour),
			NotAfter:     time.Now().Add(time.Hour),
			ExtKeyUsage:  []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		}
		name := target.dns
		if target.ip != nil {
			tmpl.IPAddresses = []net.IP{target.ip}
			name = target.ip.String()
		} else {
			tmpl.DNSNames = []string{target.dns}
		}
		der, err := x509.CreateCertificate(rand.Reader, tmpl, m.ca, &key.PublicKey, m.caKey)
		if err != nil {
			continue // refusing to sign is also fine
		}
		leaf, _ := x509.ParseCertificate(der)
		if _, err := leaf.Verify(x509.VerifyOptions{DNSName: name, Roots: roots}); err == nil {
			t.Errorf("CA could sign a trusted certificate for %s", name)
		}
	}
}

func TestCAIsReusedAcrossRestarts(t *testing.T) {
	dir := t.TempDir()
	m1, err := newCertManager(dir)
	if err != nil {
		t.Fatal(err)
	}
	m2, err := newCertManager(dir)
	if err != nil {
		t.Fatal(err)
	}
	if !m1.ca.Equal(m2.ca) {
		t.Fatal("a restart must keep the same CA, or every device would need it installed again")
	}
}
