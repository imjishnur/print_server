package main

import (
	"encoding/json"
	"io"
	"log"
	"os"
	"path/filepath"
)

// Config is stored as config.json in the data dir. The v1 receipt switches
// match the PUBLIC GLOBAL CONFIGURATION SETTINGS at the top of printer_load.php.
type Config struct {
	HTTPPort       int    `json:"http_port"`
	HTTPSPort      int    `json:"https_port"`
	EnableHTTPS    bool   `json:"enable_https"`
	DefaultPrinter string `json:"default_printer"`

	EnableKOTLogo   bool   `json:"enable_kot_logo"`
	EnableBillLogo  bool   `json:"enable_bill_logo"`
	BigStoreName    bool   `json:"big_store_name"`
	DefaultLogoMode string `json:"default_logo_mode"` // esc_star or gs_v0

	// When there is no logo\logo.png in the data dir, print the logo sent by the app
	// (logo_base64). Laragon only uses its local file.
	UseBatchLogo bool `json:"use_batch_logo"`
}

func defaultConfig() Config {
	return Config{
		HTTPPort:        80,
		HTTPSPort:       443,
		EnableHTTPS:     true,
		DefaultPrinter:  "POS-80C",
		EnableKOTLogo:   false,
		EnableBillLogo:  true,
		BigStoreName:    true,
		DefaultLogoMode: "esc_star",
		UseBatchLogo:    true,
	}
}

func loadConfig(dir string) Config {
	cfg := defaultConfig()
	path := filepath.Join(dir, "config.json")
	data, err := os.ReadFile(path)
	if err != nil {
		if out, err := json.MarshalIndent(cfg, "", "  "); err == nil {
			_ = os.WriteFile(path, out, 0o644)
		}
		return cfg
	}
	if err := json.Unmarshal(data, &cfg); err != nil {
		log.Printf("config.json is invalid, using defaults: %v", err)
		return defaultConfig()
	}
	return cfg
}

// dataDir is C:\ProgramData\PrintServer (config, logs, logo, certificates).
func dataDir() string {
	base := os.Getenv("ProgramData")
	if base == "" {
		base = exeDir()
	}
	dir := filepath.Join(base, "PrintServer")
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return exeDir()
	}
	return dir
}

func exeDir() string {
	p, err := os.Executable()
	if err != nil {
		return "."
	}
	return filepath.Dir(p)
}

func setupLogging(dir string, console bool) {
	logDir := filepath.Join(dir, "logs")
	_ = os.MkdirAll(logDir, 0o755)
	path := filepath.Join(logDir, "agent.log")
	if fi, err := os.Stat(path); err == nil && fi.Size() > 5<<20 {
		_ = os.Rename(path, path+".1")
	}

	var w io.Writer = io.Discard
	if f, err := os.OpenFile(path, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o644); err == nil {
		w = f
	}
	if console {
		w = io.MultiWriter(os.Stdout, w)
	}
	log.SetOutput(w)
	log.SetFlags(log.LstdFlags)
}
