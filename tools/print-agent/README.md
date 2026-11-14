# Club61 Print Agent

Jembatan cetak slip stasiun (Kitchen) dari browser PC kasir ke printer LAN. Panduan lengkap: `docs/PRINT_AGENT.md`.

```bash
go test ./...
GOOS=windows GOARCH=amd64 go build -trimpath -ldflags "-s -w -H windowsgui" -o dist/club61-print-agent.exe .
```

Di PC kasir: `club61-print-agent.exe` + `agent.json` (dari `agent.example.json`), lalu
`powershell -ExecutionPolicy Bypass -File .\install-autostart.ps1`. Cek: `http://127.0.0.1:9120/health`.
