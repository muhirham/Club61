package main

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"io"
	"log"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"strconv"
	"strings"
	"testing"
	"time"
)

const testToken = "token-rahasia-untuk-tes-123456"

// Printer palsu: menerima satu koneksi TCP dan mengirim byte yang diterima ke channel.
func fakePrinter(t *testing.T) (string, <-chan []byte) {
	t.Helper()
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { ln.Close() })
	got := make(chan []byte, 1)
	go func() {
		conn, err := ln.Accept()
		if err != nil {
			return
		}
		defer conn.Close()
		data, _ := io.ReadAll(conn)
		got <- data
	}()
	return ln.Addr().String(), got
}

func newTestServer(printers ...string) *server {
	return &server{
		cfg: Config{
			Port:           9120,
			Token:          testToken,
			AllowedOrigins: []string{"https://club61.id", "http://localhost:8000"},
			Printers:       printers,
		},
		dial:   net.DialTimeout,
		logger: log.New(io.Discard, "", 0),
	}
}

func printReq(host string, port int, data []byte, token, origin string) *http.Request {
	body, _ := json.Marshal(map[string]any{"host": host, "port": port, "data_base64": base64.StdEncoding.EncodeToString(data)})
	r := httptest.NewRequest(http.MethodPost, "http://127.0.0.1:9120/print", bytes.NewReader(body))
	r.Header.Set("Content-Type", "application/json")
	if token != "" {
		r.Header.Set("X-Club61-Agent-Token", token)
	}
	if origin != "" {
		r.Header.Set("Origin", origin)
	}
	return r
}

func TestPrintSendsTheExactBytesToTheRegisteredPrinter(t *testing.T) {
	addr, got := fakePrinter(t)
	host, portStr, _ := net.SplitHostPort(addr)
	port, _ := strconv.Atoi(portStr)

	srv := newTestServer(addr)
	slip := []byte{0x1B, 0x40, 'K', 'I', 'T', 'C', 'H', 'E', 'N', 0x0A, 0x1D, 0x56, 0x42, 0x00}
	rec := httptest.NewRecorder()
	srv.handler().ServeHTTP(rec, printReq(host, port, slip, testToken, "https://club61.id"))

	if rec.Code != http.StatusOK {
		t.Fatalf("status %d, body %s", rec.Code, rec.Body.String())
	}
	if rec.Header().Get("Access-Control-Allow-Origin") != "https://club61.id" {
		t.Fatalf("CORS origin tidak dipantulkan")
	}
	select {
	case data := <-got:
		if !bytes.Equal(data, slip) {
			t.Fatalf("byte berbeda: %v", data)
		}
	case <-time.After(3 * time.Second):
		t.Fatal("printer palsu tidak menerima data")
	}
}

func TestPrintRejectsWrongTokenUnknownPrinterAndForeignOrigin(t *testing.T) {
	srv := newTestServer("10.10.2.137:9100")
	cases := map[string]struct {
		req  *http.Request
		code int
	}{
		"token salah":           {printReq("10.10.2.137", 9100, []byte("x"), "salah", "https://club61.id"), http.StatusUnauthorized},
		"tanpa token":           {printReq("10.10.2.137", 9100, []byte("x"), "", "https://club61.id"), http.StatusUnauthorized},
		"printer tak terdaftar": {printReq("10.10.2.200", 9100, []byte("x"), testToken, "https://club61.id"), http.StatusForbidden},
		"origin asing":          {printReq("10.10.2.137", 9100, []byte("x"), testToken, "https://situs-jahat.com"), http.StatusForbidden},
	}
	for name, c := range cases {
		rec := httptest.NewRecorder()
		srv.handler().ServeHTTP(rec, c.req)
		if rec.Code != c.code {
			t.Errorf("%s: status %d, mau %d (%s)", name, rec.Code, c.code, rec.Body.String())
		}
	}
}

func TestUnreachablePrinterReturnsAClearError(t *testing.T) {
	ln, _ := net.Listen("tcp", "127.0.0.1:0")
	addr := ln.Addr().String()
	ln.Close() // port tertutup → koneksi ditolak
	host, portStr, _ := net.SplitHostPort(addr)
	port, _ := strconv.Atoi(portStr)

	srv := newTestServer(addr)
	rec := httptest.NewRecorder()
	srv.handler().ServeHTTP(rec, printReq(host, port, []byte("x"), testToken, "https://club61.id"))
	if rec.Code != http.StatusBadGateway || !strings.Contains(rec.Body.String(), "tidak terjangkau") {
		t.Fatalf("status %d body %s", rec.Code, rec.Body.String())
	}
}

func TestPreflightAllowsPrivateNetworkOnlyForKnownOrigins(t *testing.T) {
	srv := newTestServer("10.10.2.137:9100")

	r := httptest.NewRequest(http.MethodOptions, "http://127.0.0.1:9120/print", nil)
	r.Header.Set("Origin", "https://club61.id")
	r.Header.Set("Access-Control-Request-Private-Network", "true")
	rec := httptest.NewRecorder()
	srv.handler().ServeHTTP(rec, r)
	if rec.Code != http.StatusNoContent || rec.Header().Get("Access-Control-Allow-Private-Network") != "true" {
		t.Fatalf("preflight: %d %v", rec.Code, rec.Header())
	}

	r = httptest.NewRequest(http.MethodOptions, "http://127.0.0.1:9120/print", nil)
	r.Header.Set("Origin", "https://situs-jahat.com")
	rec = httptest.NewRecorder()
	srv.handler().ServeHTTP(rec, r)
	if rec.Code != http.StatusForbidden || rec.Header().Get("Access-Control-Allow-Private-Network") != "" {
		t.Fatalf("origin asing lolos preflight: %d", rec.Code)
	}
}

func TestHealthAndDnsRebindingGuard(t *testing.T) {
	srv := newTestServer("10.10.2.137:9100")

	rec := httptest.NewRecorder()
	srv.handler().ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "http://127.0.0.1:9120/health", nil))
	if rec.Code != http.StatusOK || !strings.Contains(rec.Body.String(), "club61-print-agent") {
		t.Fatalf("health: %d %s", rec.Code, rec.Body.String())
	}

	r := httptest.NewRequest(http.MethodGet, "http://evil.example:9120/health", nil)
	rec = httptest.NewRecorder()
	srv.handler().ServeHTTP(rec, r)
	if rec.Code != http.StatusForbidden {
		t.Fatalf("host asing lolos: %d", rec.Code)
	}
}

func TestConfigValidation(t *testing.T) {
	dir := t.TempDir()
	write := func(body string) string {
		p := dir + "/agent.json"
		if err := writeFile(p, body); err != nil {
			t.Fatal(err)
		}
		return p
	}

	if _, err := loadConfig(write(`{"token":"pendek","allowed_origins":["https://club61.id"],"printers":["10.10.2.137:9100"]}`)); err == nil {
		t.Error("token pendek harus ditolak")
	}
	if _, err := loadConfig(write(`{"token":"` + testToken + `","allowed_origins":["https://club61.id"],"printers":["10.10.2.137"]}`)); err == nil {
		t.Error("printer tanpa port harus ditolak")
	}
	cfg, err := loadConfig(write(`{"token":"` + testToken + `","allowed_origins":["https://club61.id/"],"printers":["10.10.2.137:9100"]}`))
	if err != nil || cfg.Port != 9120 || cfg.AllowedOrigins[0] != "https://club61.id" {
		t.Fatalf("config sah ditolak / tidak dirapikan: %v %+v", err, cfg)
	}
}

func writeFile(path, body string) error {
	return os.WriteFile(path, []byte(body), 0o644)
}

func TestConfigWithUtf8BomIsAccepted(t *testing.T) {
	p := t.TempDir() + "/agent.json"
	body := "\xEF\xBB\xBF" + `{"token":"` + testToken + `","allowed_origins":["https://club61.id"],"printers":["10.10.2.137:9100"]}`
	if err := writeFile(p, body); err != nil {
		t.Fatal(err)
	}
	if _, err := loadConfig(p); err != nil {
		t.Fatalf("agent.json ber-BOM (Notepad) ditolak: %v", err)
	}
}
