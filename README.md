# Idar_Server_backend DAB RECORDER
Radio Nova Backup Recording System (Intern server)
IDAR er en dedikert backup-server som kontinuerlig tar opp Radio Novas sending i 2-timers intervaller. Hvis hovedserveren (Fred) går ned, kan ansatte hente opptak fra IDAR via et webgrensesnitt.

### Teknisk Stack
- **OS:** Ubuntu Server 24.04 LTS
- **Recording:** ffmpeg
- **Web:** Nginx + PHP 8.3
- **Scheduling:** systemd + cron
- **Format:** WAV (PCM 16-bit, 44.1kHz, stereo)

---

## Arkitektur
```
┌─────────────────────────────────────────────────────────┐
│                    IDAR SERVER                          │
│                   (172.20.1.130)                        │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  ┌──────────────┐      ┌──────────────┐               │
│  │   systemd    │──────▶│  Recording   │               │
│  │   service    │      │    Script    │               │
│  └──────────────┘      └──────┬───────┘               │
│                               │                         │
│                               ▼                         │
│  ┌──────────────────────────────────────┐              │
│  │        stream.radionova.no/ogg       │              │
│  │              (ffmpeg)                 │              │
│  └──────────────┬───────────────────────┘              │
│                 │                                       │
│                 ▼                                       │
│  ┌──────────────────────────────────────┐              │
│  │      /recordings/                    │              │
│  │   2025-11-24_0600.wav (~1.2GB)      │              │
│  │   2025-11-24_0800.wav               │              │
│  │   2025-11-24_1000.wav               │              │
│  │         ...                          │              │
│  └──────────────┬───────────────────────┘              │
│                 │                                       │
│       ┌─────────┴─────────┐                            │
│       ▼                   ▼                            │
│  ┌─────────┐       ┌──────────┐                       │
│  │  Nginx  │       │   cron   │                       │
│  │   +     │       │ (cleanup)│                       │
│  │  PHP    │       └──────────┘                       │
│  └────┬────┘                                           │
│       │                                                │
└───────┼────────────────────────────────────────────────┘
        │
        ▼
   ┌─────────┐
   │ Browser │  http://172.20.1.130/
   │ (Staff) │
   └─────────┘
```

---

##  Filstruktur
```
/usr/local/bin/
├── scheduled_recorder.sh       # Hovedscript: Tar opp i 2-timers intervaller
└── cleanup_recordings.sh       # Sletter filer eldre enn 48 timer

/etc/systemd/system/
└── idar-recorder.service       # Systemd service for auto-start

/etc/nginx/sites-available/
└── idar-recordings             # Nginx webserver-konfigurasjon

/recordings/
├── index.php                   # Webgrensesnitt (liste med opptak)
├── style.css                   # Design (Radio Nova farger)
├── 2025-11-24_0600.wav        # Opptak 06:00-08:00
├── 2025-11-24_0800.wav        # Opptak 08:00-10:00
└── ...                         # Siste 48 timer med opptak

/var/log/
├── idar_recorder.log           # Recording-logger
└── idar_cleanup.log            # Cleanup-logger

/var/spool/cron/crontabs/
└── idar                        # Cron-job for idar-bruker
```

---

## 🔧 Scriptbeskrivelser

### 1. `/usr/local/bin/scheduled_recorder.sh`
**Formål:** Tar opp stream i 2-timers intervaller fra 06:00-24:00.

**Hvordan det fungerer:**
- Kjører som systemd service (starter ved boot)
- Sjekker klokkeslettet kontinuerlig
- Starter opptak ved partall-timer (06, 08, 10, 12, 14, 16, 18, 20, 22)
- Tar opp i 2 timer (7200 sekunder)
- Venter til 06:00 neste dag etter 22:00-opptak
- Logger til `/var/log/idar_recorder.log`

**Viktige parametere i scriptet:**
```bash
OUTPUT_DIR="/recordings"
STREAM_URL="https://stream.radionova.no/ogg"
RECORDING_DURATION=7200  # 2 timer i sekunder
```

**Start/Stopp:**
```bash
sudo systemctl start idar-recorder
sudo systemctl stop idar-recorder
sudo systemctl status idar-recorder
```

---

### 2. `/usr/local/bin/cleanup_recordings.sh`
**Formål:** Sletter WAV-filer eldre enn 48 timer.

**Hvordan det fungerer:**
- Kjøres automatisk hver time via cron
- Finner filer eldre enn 2880 minutter (48 timer)
- Sletter automatisk
- Logger til `/var/log/idar_cleanup.log`

**Cron-konfigurasjon:**
```bash
# Se cron-jobber for idar-bruker:
sudo crontab -u idar -l

# Output:
0 * * * * /usr/local/bin/cleanup_recordings.sh
```

**Manuell kjøring:**
```bash
sudo -u idar /usr/local/bin/cleanup_recordings.sh
```

---

### 3. `/etc/systemd/system/idar-recorder.service`
**Formål:** Sørger for at recording-scriptet starter automatisk ved boot og restarter ved feil.

**Konfigurasjon:**
```ini
[Unit]
Description=Idar Stream Recorder
After=network-online.target

[Service]
Type=simple
User=idar
Group=idar
ExecStart=/usr/local/bin/scheduled_recorder.sh
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

**Kommandoer:**
```bash
# Enable (start ved boot)
sudo systemctl enable idar-recorder

# Start nå
sudo systemctl start idar-recorder

# Se status
sudo systemctl status idar-recorder

# Se logger
sudo journalctl -u idar-recorder -f
```

---

### 4. `/recordings/index.php`
**Formål:** Webgrensesnitt for å liste og laste ned opptak.

**Funksjonalitet:**
- Viser opptak gruppert etter dato
- Sorterer etter tidspunkt
- Filstørrelse og helse-sjekk (advarer hvis fil er unormalt liten/stor)
- Auto-refresh hvert 5. minutt
- Last ned-knapp for hver fil

**URL:** `http://172.20.1.130/`

---

## ⚙️ Konfigurasjonsendringer

### Endre opptakstider

**Problem:** Standard opptaksvindu er 06:00-24:00. Hva hvis jeg vil ta opp fra 00:00-06:00 også?

**Løsning:** Rediger `/usr/local/bin/scheduled_recorder.sh`
```bash
sudo nano /usr/local/bin/scheduled_recorder.sh
```

**Finn denne seksjonen:**
```bash
# Check if we're in recording window (06:00-23:59)
if [ $current_hour_int -ge 6 ] && [ $current_hour_int -lt 24 ]; then
```

**Endre til (for 24/7 opptak):**
```bash
# Record 24/7
if [ $current_hour_int -ge 0 ] && [ $current_hour_int -lt 24 ]; then
```

**Eller for spesifikke tidspunkt (f.eks. 04:00-24:00):**
```bash
if [ $current_hour_int -ge 4 ] && [ $current_hour_int -lt 24 ]; then
```

**Restart tjenesten:**
```bash
sudo systemctl restart idar-recorder
```

---

### Endre oppbevaringstid

**Standard:** 48 timer

**Endre til 72 timer (3 dager):**
```bash
sudo nano /usr/local/bin/cleanup_recordings.sh
```

**Endre:**
```bash
RETENTION_HOURS=48
```

**Til:**
```bash
RETENTION_HOURS=72
```

**Ingen restart nødvendig** (cron kjører scriptet hver time).

---

### Endre volumjustering

**Problem:** Opptakene er for høye/lave.

**Løsning:**
```bash
sudo nano /usr/local/bin/scheduled_recorder.sh
```

**Finn ffmpeg-kommandoen og legg til/endre:**
```bash
ffmpeg -i "$STREAM_URL" \
    -t 7200 \
    -af "volume=0.5" \        # ← Legg til denne linjen (0.5 = 50% volum)
    -acodec pcm_s16le \
    -ar 44100 \
    -ac 2 \
    "$filename"
```

**Volum-verdier:**
- `0.3` = 30% (mye lavere)
- `0.5` = 50% (halvparten)
- `0.8` = 80% (litt lavere)
- `1.0` = 100% (original)
- `1.5` = 150% (høyere - kan distordere)

**Restart:**
```bash
sudo systemctl restart idar-recorder
```

---

### Ta opp spesifikk tid (engangsopptak)

**Scenario:** Du vil ta opp fra 02:00-04:00 i natt (utenom vanlig schedule).

**Løsning: Lag et ad-hoc opptak**
```bash
# SSH til Idar
ssh root@172.20.1.130

# Ta opp manuelt (eksempel: 2 timer fra nå)
sudo -u idar ffmpeg -i https://stream.radionova.no/ogg \
    -t 7200 \
    -acodec pcm_s16le \
    -ar 44100 \
    -ac 2 \
    /recordings/manual_$(date +%Y-%m-%d_%H%M).wav

# Eller schedule med 'at' kommando
echo "sudo -u idar ffmpeg -i https://stream.radionova.no/ogg -t 7200 -acodec pcm_s16le -ar 44100 -ac 2 /recordings/special_$(date +%Y-%m-%d_%H%M).wav" | at 02:00
```

---

## 🔍 Feilsøking

### Problem: Ingen opptak vises på websiden

**Sjekk:**
```bash
# 1. Er recording-tjenesten aktiv?
sudo systemctl status idar-recorder

# 2. Finnes det faktisk filer?
ls -lh /recordings/

# 3. Sjekk logger
tail -50 /var/log/idar_recorder.log

# 4. Er Nginx aktiv?
sudo systemctl status nginx

# 5. Sjekk diskplass
df -h /
```

---

### Problem: Opptakene stopper plutselig

**Sjekk:**
```bash
# 1. Se systemd-logger
sudo journalctl -u idar-recorder -n 100

# 2. Sjekk at streamen er tilgjengelig
ffmpeg -i https://stream.radionova.no/ogg -t 5 /tmp/test.wav

# 3. Restart tjenesten
sudo systemctl restart idar-recorder
```

---

### Problem: Websiden er ikke tilgjengelig

**Sjekk:**
```bash
# 1. Er Nginx aktiv?
sudo systemctl status nginx

# 2. Test lokalt på Idar
curl http://localhost/

# 3. Sjekk PHP-FPM
sudo systemctl status php8.3-fpm

# 4. Restart Nginx
sudo systemctl restart nginx
```

---
