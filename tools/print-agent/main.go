// Club61 Print Agent — jembatan cetak slip stasiun (Kitchen, dst.) untuk kasir yang memakai BROWSER di PC/laptop.
//
// Browser tidak boleh membuka koneksi ke printer LAN, jadi halaman kasir Club61 menitipkan byte ESC/POS slip ke agent
// ini (http://127.0.0.1:<port>/print), lalu agent meneruskannya ke IP:port printer. Fungsinya sama dengan jembatan
// Club61Print di aplikasi Android (docs/FLUTTER_LAN_PRINT.md).
//
// Keamanan:
//   - hanya mendengar di 127.0.0.1 (perangkat lain di jaringan tidak bisa memanggilnya);
//   - /print wajib token (header X-Club61-Agent-Token) yang sama dengan STATION_PRINT_AGENT_TOKEN di server;
//   - permintaan dari browser hanya diterima dari origin domain Club61 yang terdaftar di agent.json;
//   - tujuan cetak hanya IP:port printer yang terdaftar di agent.json.
package main

import (
	"bytes"
	"crypto/subtle"
	"encoding/base64"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

const (
	version      = "1.0.0"
	maxSlipBytes = 256 * 1024
	dialTimeout  = 4 * time.Second
	writeTimeout = 5 * time.Second
)

type Config struct {
	Port           int      `json:"port"`
	Token          string   `json:"token"`
	AllowedOrigins []string `json:"allowed_origins"`
	Printers       []string `json:"printers"`
}

func loadConfig(path string) (Config, error) {
	var cfg Config
	raw, err := os.ReadFile(path)
	if err != nil {
		return cfg, fmt.Errorf("agent.json tidak bisa dibaca (%s): %w", path, err)
	}
	// Notepad / PowerShell 5 menyimpan UTF-8 dengan BOM — tanpa ini agent gagal membaca agent.json.
	raw = bytes.TrimPrefix(raw, []byte{0xEF, 0xBB, 0xBF})
	if err := json.Unmarshal(raw, &cfg); err != nil {
		return cfg, fmt.Errorf("agent.json tidak valid: %w", err)
	}
	if cfg.Port == 0 {
		cfg.Port = 9120
	}
	if cfg.Port < 1 || cfg.Port > 65535 {
		return cfg, errors.New("port harus 1-65535")
	}
	if len(strings.TrimSpace(cfg.Token)) < 24 {
		return cfg, errors.New("token minimal 24 karakter (samakan dengan STATION_PRINT_AGENT_TOKEN di server)")
	}
	if len(cfg.AllowedOrigins) == 0 {
		return cfg, errors.New("allowed_origins kosong — isi domain Club61 (mis. https://club61.id)")
	}
	if len(cfg.Printers) == 0 {
		return cfg, errors.New("printers kosong — isi IP:port printer stasiun (mis. 10.10.2.137:9100)")
	}
	for i, p := range cfg.Printers {
		host, port, err := net.SplitHostPort(strings.TrimSpace(p))
		if err != nil || host == "" || port == "" {
			return cfg, fmt.Errorf("printer %q harus berformat IP:port", p)
		}
		cfg.Printers[i] = net.JoinHostPort(host, port)
	}
	for i, o := range cfg.AllowedOrigins {
		cfg.AllowedOrigins[i] = strings.TrimRight(strings.TrimSpace(o), "/")
	}
	return cfg, nil
}

type server struct {
	cfg    Config
	dial   func(network, address string, timeout time.Duration) (net.Conn, error)
	logger *log.Logger
}

func (s *server) originAllowed(origin string) bool {
	for _, o := range s.cfg.AllowedOrigins {
		if o == origin {
			return true
		}
	}
	return false
}

func (s *server) printerAllowed(target string) bool {
	for _, p := range s.cfg.Printers {
		if p == target {
			return true
		}
	}
	return false
}

func writeJSON(w http.ResponseWriter, status int, body map[string]any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(body)
}

func fail(w http.ResponseWriter, status int, message string) {
	writeJSON(w, status, map[string]any{"ok": false, "error": message})
}

func (s *server) handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/health", s.health)
	mux.HandleFunc("/print", s.print)

	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// DNS rebinding: hanya alamat loopback agent ini sendiri.
		host := r.Host
		if h, _, err := net.SplitHostPort(r.Host); err == nil {
			host = h
		}
		if host != "127.0.0.1" && host != "localhost" {
			fail(w, http.StatusForbidden, "Host tidak diizinkan.")
			return
		}

		if origin := r.Header.Get("Origin"); origin != "" {
			if !s.originAllowed(strings.TrimRight(origin, "/")) {
				s.logger.Printf("DITOLAK origin %s %s", origin, r.URL.Path)
				fail(w, http.StatusForbidden, "Halaman ini tidak diizinkan memakai print agent.")
				return
			}
			w.Header().Set("Access-Control-Allow-Origin", origin)
			w.Header().Add("Vary", "Origin")
		}

		if r.Method == http.MethodOptions {
			w.Header().Set("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
			w.Header().Set("Access-Control-Allow-Headers", "Content-Type, X-Club61-Agent-Token")
			w.Header().Set("Access-Control-Max-Age", "600")
			// Chrome Private Network Access: halaman HTTPS memanggil 127.0.0.1.
			if r.Header.Get("Access-Control-Request-Private-Network") == "true" {
				w.Header().Set("Access-Control-Allow-Private-Network", "true")
			}
			w.WriteHeader(http.StatusNoContent)
			return
		}

		mux.ServeHTTP(w, r)
	})
}

func (s *server) health(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		fail(w, http.StatusMethodNotAllowed, "Gunakan GET.")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "name": "club61-print-agent", "version": version})
}

type printRequest struct {
	Host       string `json:"host"`
	Port       int    `json:"port"`
	DataBase64 string `json:"data_base64"`
}

func (s *server) print(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		fail(w, http.StatusMethodNotAllowed, "Gunakan POST.")
		return
	}
	token := r.Header.Get("X-Club61-Agent-Token")
	if subtle.ConstantTimeCompare([]byte(token), []byte(s.cfg.Token)) != 1 {
		s.logger.Printf("DITOLAK token salah dari %s", r.Header.Get("Origin"))
		fail(w, http.StatusUnauthorized, "Token print agent tidak cocok (samakan STATION_PRINT_AGENT_TOKEN dengan agent.json).")
		return
	}

	var req printRequest
	body := http.MaxBytesReader(w, r.Body, maxSlipBytes*2)
	if err := json.NewDecoder(body).Decode(&req); err != nil {
		fail(w, http.StatusBadRequest, "Permintaan cetak tidak valid.")
		return
	}
	if req.Port == 0 {
		req.Port = 9100
	}
	target := net.JoinHostPort(strings.TrimSpace(req.Host), strconv.Itoa(req.Port))
	if !s.printerAllowed(target) {
		s.logger.Printf("DITOLAK printer %s tidak terdaftar", target)
		fail(w, http.StatusForbidden, "Printer "+target+" belum terdaftar di agent.json print agent.")
		return
	}

	data, err := base64.StdEncoding.DecodeString(req.DataBase64)
	if err != nil || len(data) == 0 || len(data) > maxSlipBytes {
		fail(w, http.StatusBadRequest, "Isi slip tidak valid.")
		return
	}

	if err := s.send(target, data); err != nil {
		s.logger.Printf("GAGAL %s: %v", target, err)
		fail(w, http.StatusBadGateway, "Gagal kirim ke printer "+target+": "+err.Error())
		return
	}

	s.logger.Printf("OK %s (%d byte)", target, len(data))
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (s *server) send(target string, data []byte) error {
	conn, err := s.dial("tcp", target, dialTimeout)
	if err != nil {
		return fmt.Errorf("printer tidak terjangkau (cek printer menyala & satu jaringan): %w", err)
	}
	defer conn.Close()
	_ = conn.SetWriteDeadline(time.Now().Add(writeTimeout))
	if _, err := conn.Write(data); err != nil {
		return err
	}
	return nil
}

// bestEffort mengabaikan kegagalan tulis (stdout tidak ada di aplikasi tanpa jendela).
type bestEffort struct{ w io.Writer }

func (b bestEffort) Write(p []byte) (int, error) {
	_, _ = b.w.Write(p)
	return len(p), nil
}

func main() {
	exeDir := "."
	if exe, err := os.Executable(); err == nil {
		exeDir = filepath.Dir(exe)
	}
	configPath := flag.String("config", filepath.Join(exeDir, "agent.json"), "lokasi agent.json")
	flag.Parse()

	// Build -H windowsgui tidak punya konsol: tulis ke stdout boleh gagal tanpa menghentikan log ke agent.log.
	var out io.Writer = bestEffort{os.Stdout}
	if f, err := os.OpenFile(filepath.Join(exeDir, "agent.log"), os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o644); err == nil {
		out = io.MultiWriter(f, bestEffort{os.Stdout})
		defer f.Close()
	}
	logger := log.New(out, "", log.LstdFlags)

	cfg, err := loadConfig(*configPath)
	if err != nil {
		logger.Fatalf("Club61 Print Agent tidak bisa jalan: %v", err)
	}

	srv := &server{cfg: cfg, dial: net.DialTimeout, logger: logger}
	addr := "127.0.0.1:" + strconv.Itoa(cfg.Port)
	logger.Printf("Club61 Print Agent %s jalan di http://%s (printer: %s)", version, addr, strings.Join(cfg.Printers, ", "))

	httpServer := &http.Server{Addr: addr, Handler: srv.handler(), ReadHeaderTimeout: 5 * time.Second}
	if err := httpServer.ListenAndServe(); err != nil {
		logger.Fatalf("Club61 Print Agent berhenti: %v", err)
	}
}
