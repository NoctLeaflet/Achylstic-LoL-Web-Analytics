# CLAUDE.md — LoL Guild Tracker

Website untuk men-track akun League of Legends milik saya dan teman-teman satu grup,
lalu membandingkan performa kami dengan leaderboard dan grafik.
Inspirasi: halaman guild di dorans.bot (leaderboard, match history, head-to-head,
"climber of the week", tracking LP).

Bahasa komunikasi dengan saya: **Bahasa Indonesia**. Kode, nama variabel, dan komentar kode: English.

---

## Tech Stack

- **Backend:** Laravel (versi stabil terbaru), PHP 8.2+
- **Dev environment:** Laragon (Windows), VS Code
- **Database:** MySQL (bawaan Laragon)
- **Frontend:** Blade + Tailwind CSS (via Vite), Alpine.js untuk interaksi kecil
- **Grafik:** Chart.js
- **HTTP client:** Laravel `Http` facade (jangan pakai Guzzle langsung)
- **Job/Scheduler:** Laravel Scheduler + Queue (driver `database`)

Jangan menambah framework frontend berat (React/Vue/Inertia) tanpa bertanya dulu.

---

## Keamanan API Key (WAJIB)

- Riot API key HANYA disimpan di `.env` sebagai `RIOT_API_KEY`.
- Akses lewat `config('services.riot.key')`, tidak pernah `env()` langsung di luar file config.
- Key tidak boleh muncul di kode frontend, di log, di response JSON, atau di commit.
- `.env` harus ada di `.gitignore`. Sediakan `.env.example` dengan nilai kosong.
- Development key hangus tiap 24 jam. Kalau request mendapat `401`/`403`, tampilkan pesan
  jelas "API key kedaluwarsa/invalid", jangan diam-diam gagal.

`.env` yang dibutuhkan:

```
RIOT_API_KEY=
RIOT_PLATFORM=sg2        # platform routing (summoner, league, mastery)
RIOT_REGION=sea          # regional routing untuk match-v5
RIOT_ACCOUNT_REGION=asia # regional routing untuk account-v1
```

> Catatan region: server Asia Tenggara memakai platform seperti `sg2`, `ph2`, `th2`,
> `tw2`, `vn2`, dengan routing `sea` untuk match-v5. Account-v1 memakai `asia`.
> Jangan hardcode, selalu ambil dari config.

---

## Riot API yang Dipakai

Base URL:
- Platform: `https://{platform}.api.riotgames.com`
- Regional: `https://{region}.api.riotgames.com`

| Kebutuhan | Endpoint | Routing |
|---|---|---|
| Riot ID → PUUID | `GET /riot/account/v1/accounts/by-riot-id/{gameName}/{tagLine}` | account region |
| Profil (icon, level) | `GET /lol/summoner/v4/summoners/by-puuid/{puuid}` | platform |
| Rank & LP | `GET /lol/league/v4/entries/by-puuid/{puuid}` | platform |
| Daftar match ID | `GET /lol/match/v5/matches/by-puuid/{puuid}/ids?queue=&start=&count=` | region |
| Detail match | `GET /lol/match/v5/matches/{matchId}` | region |
| Mastery | `GET /lol/champion-mastery/v4/champion-masteries/by-puuid/{puuid}/top` | platform |

Header: `X-Riot-Token: {key}`.

Aset gambar (icon champion, item, profil) diambil dari **Data Dragon**
(`https://ddragon.leagueoflegends.com`). Ambil versi terbaru dari `/api/versions.json`
dan cache versinya.

Queue ID penting: `420` Ranked Solo/Duo, `440` Ranked Flex, `400` Normal Draft, `450` ARAM.

### Rate Limit

- Batas key personal/dev: **20 request / 1 detik** dan **100 request / 2 menit**.
- Semua panggilan Riot HARUS lewat satu class `App\Services\Riot\RiotClient` yang:
  - membatasi request (pakai `RateLimiter` Laravel atau throttle sederhana),
  - menangani `429` dengan membaca header `Retry-After` lalu retry,
  - retry ringan untuk `5xx`,
  - me-log error tanpa menyertakan API key.
- Detail match **tidak pernah berubah**. Jika `match_id` sudah ada di DB, jangan fetch ulang.

---

## Prinsip Data

Riot API hanya memberi rank/LP **saat ini**, bukan riwayatnya. Karena itu:
- Simpan snapshot LP secara berkala ke tabel `rank_snapshots`. Ini sumber grafik LP.
- Simpan detail match ke DB sendiri supaya halaman web tidak memanggil Riot API langsung.
- Halaman web **hanya membaca dari database**. Semua pemanggilan Riot dilakukan oleh
  job/command di background.

---

## Skema Database (rencana awal)

- `players`: id, game_name, tag_line, puuid (unique), display_name (nama panggilan di grup),
  profile_icon_id, summoner_level, is_active, timestamps
- `rank_snapshots`: id, player_id, queue_type (`RANKED_SOLO_5x5` / `RANKED_FLEX_SR`), tier,
  rank (division), league_points, wins, losses, captured_at
  - simpan snapshot baru hanya jika ada perubahan (LP/wins/losses) dari snapshot terakhir
- `matches`: id, match_id (unique, mis. `SG2_123...`), queue_id, game_version, game_mode,
  game_duration, game_start_at, timestamps
- `match_participants`: id, match_id (FK), player_id (nullable, terisi jika pemain grup),
  puuid, riot_id_name, riot_id_tag, champion_id, champion_name, team_id, team_position, win,
  kills, deaths, assists, total_minions_killed, neutral_minions_killed, gold_earned,
  total_damage_dealt_to_champions, damage_taken, vision_score, wards_placed,
  items (json), summoner_spells (json), perks (json)
  - simpan **semua 10 peserta** supaya head-to-head dan "main bareng" bisa dihitung

Gunakan migration, model Eloquent dengan relasi, dan index pada `puuid`, `match_id`,
`player_id`, `captured_at`, `game_start_at`.

---

## Background Jobs & Scheduler

- `php artisan players:add {gameName} {tagLine} {--name=}`: tambah pemain (resolve PUUID)
- `php artisan riot:sync-ranks`: ambil rank semua pemain aktif, simpan snapshot jika berubah
- `php artisan riot:sync-matches {--count=20}`: ambil match ID baru per pemain, fetch detail
  hanya untuk match yang belum ada di DB
- Scheduler (`routes/console.php`):
  - `riot:sync-ranks` tiap 15 menit
  - `riot:sync-matches` tiap 30 menit
- Gunakan `withoutOverlapping()` agar sync tidak bertabrakan.
- Di Laragon (Windows), jalankan scheduler secara lokal dengan `php artisan schedule:work`.

---

## Fitur per Tahap

### Tahap 1 (kerjakan pertama)
1. Setup proyek, config Riot, `RiotClient`, migration, model.
2. Command tambah pemain + sync rank + sync match.
3. **Halaman Leaderboard** (`/`): tabel pemain diurutkan berdasarkan rank (tier → division → LP),
   dengan icon, rank, LP, winrate, jumlah game. Toggle Solo/Duo vs Flex.
4. **Halaman Pemain** (`/players/{player}`): grafik LP dari waktu ke waktu (Chart.js line),
   statistik rata-rata (KDA, CS/menit, damage/menit, vision), top champion, 20 match terakhir.
5. **Halaman Compare** (`/compare?players=1,2,3`): pilih 2–5 pemain, tampilkan:
   - grafik LP beberapa pemain dalam satu chart,
   - radar chart (KDA, CS/min, damage/min, vision/min, gold/min, winrate),
   - bar chart perbandingan per metrik.

### Tahap 2
- Halaman detail match (scoreboard 10 pemain).
- Head-to-head: rekor menang/kalah saat dua pemain grup berada di tim berlawanan.
- Statistik duo: winrate saat main satu tim.
- "Climber of the week": LP gain terbesar dalam 7 hari terakhir.

### Tahap 3
- Notifikasi ke Discord lewat **webhook** (bukan bot penuh): match selesai, promosi/demosi,
  win streak. URL webhook disimpan di `.env` (`DISCORD_WEBHOOK_URL`).

### Di luar cakupan (jangan dikerjakan kecuali diminta)
- Tier list champion, build/rune global, betting, login user, multi-guild.

---

## Konvensi Kode

- Logika Riot di `app/Services/Riot/` (`RiotClient`, `AccountService`, `LeagueService`,
  `MatchService`). Controller tetap tipis.
- Perhitungan statistik (rata-rata, KDA, per menit, H2H) di `app/Services/Stats/`.
- Data untuk Chart.js disiapkan di backend sebagai array/JSON, lalu dioper ke Blade
  (`@json`). Jangan melakukan perhitungan berat di JavaScript.
- KDA = (kills + assists) / max(deaths, 1). CS = total_minions_killed + neutral_minions_killed.
  Nilai per menit memakai `game_duration` dalam detik / 60.
- Abaikan match "remake" (durasi < 300 detik) dari statistik.
- Tulis Feature test untuk command sync dengan `Http::fake()`. Jangan memanggil Riot API
  sungguhan di test.
- UI: tema gelap, responsif (enak dilihat di HP), konsisten satu palet warna per pemain
  di semua grafik.

---

## Cara Kerja dengan Saya

- Kerjakan per langkah kecil, jelaskan singkat apa yang diubah.
- Sebelum menambah package Composer/NPM baru, sebutkan alasannya.
- Setelah perubahan, beri perintah yang perlu saya jalankan di terminal Laragon
  (mis. `php artisan migrate`, `npm run dev`).
- Jika ada keputusan desain yang ragu, tanya dulu.
