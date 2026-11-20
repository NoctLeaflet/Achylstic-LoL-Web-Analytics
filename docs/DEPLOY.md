# Deploy Achylstic ke server (VPS)

Panduan ini untuk satu VPS Ubuntu 24.04 dengan Nginx, PHP 8.3 dan MySQL. Dengan ini situs hidup 24 jam,
sinkron Riot jalan sendiri, dan teman-temanmu cukup membuka alamat domain. Perkiraan waktu: 1 sampai 2 jam
untuk pertama kali.

## 0. Yang perlu disiapkan

- **VPS** kecil sudah cukup (1 vCPU, 1 GB RAM, Ubuntu 24.04) dan akses SSH.
- **Domain atau subdomain** yang diarahkan (record A) ke IP VPS. Tanpa domain, HTTPS dan pratinjau link
  Discord tidak bisa dipakai.
- **Riot API key.** Key development hangus tiap 24 jam, jadi di server daftarkan produkmu di
  <https://developer.riotgames.com> untuk mendapat key yang masa berlakunya lebih panjang (perlu persetujuan
  Riot, bisa makan beberapa hari). Sambil menunggu, key development tetap bisa dipakai dan diganti harian.
  Situs akan memberi peringatan di halaman admin dan Discord saat key ditolak.

## 1. Pasang paket di server

```bash
sudo apt update && sudo apt install -y nginx mysql-server git unzip \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl
# Composer
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
# Node.js 20+ (hanya untuk membangun aset)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs
```

## 2. Database

```bash
sudo mysql
```

```sql
CREATE DATABASE achylstic CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'achylstic'@'localhost' IDENTIFIED BY 'GANTI-DENGAN-PASSWORD-KUAT';
GRANT ALL PRIVILEGES ON achylstic.* TO 'achylstic'@'localhost';
EXIT;
```

## 3. Ambil kode

```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone https://github.com/NoctLeaflet/Achylstic-LoL-Web-Analytics.git achylstic
sudo chown -R $USER:www-data achylstic && cd achylstic
```

## 4. Konfigurasi

```bash
cp .env.production.example .env
nano .env          # isi APP_URL, DB_PASSWORD, RIOT_API_KEY, (opsional) DISCORD_WEBHOOK_URL
composer install --no-dev --optimize-autoloader
php artisan key:generate
npm ci && npm run build
php artisan migrate --force
```

Beri izin tulis ke folder yang dibutuhkan Laravel:

```bash
sudo chgrp -R www-data storage bootstrap/cache
sudo chmod -R ug+rwx storage bootstrap/cache
```

## 5. Bawa data dari laptopmu (agar riwayat LP tidak hilang)

Riwayat LP hanya ada di databasemu, Riot tidak menyimpannya. Di laptop (Laragon), ekspor:

```bash
C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysqldump.exe -u root achylstic > achylstic.sql
```

Kirim `achylstic.sql` ke server (`scp`), lalu impor SETELAH langkah migrate di atas:

```bash
mysql -u achylstic -p achylstic < achylstic.sql
php artisan migrate --force      # jaga-jaga kalau ada migration yang lebih baru
```

Mulai dari database kosong? Lewati langkah ini lalu tambahkan teman lewat halaman admin.

## 6. Nginx dan HTTPS

```bash
sudo cp deploy/nginx.conf.example /etc/nginx/sites-available/achylstic
sudo nano /etc/nginx/sites-available/achylstic     # ganti server_name dan path bila perlu
sudo ln -s /etc/nginx/sites-available/achylstic /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d achylstic.example.com
```

## 7. Scheduler (sinkron otomatis)

Cukup satu baris cron untuk user web (lihat `deploy/crontab.example`):

```bash
sudo crontab -u www-data -e
# tempel:
* * * * * cd /var/www/achylstic && php artisan schedule:run >> /dev/null 2>&1
```

Scheduler lalu menjalankan sinkron rank, match, timeline, dan ringkasan mingguan Discord. Tidak perlu
`schedule:work` atau worker antrean lagi.

## 8. Akun admin dan cek akhir

```bash
php artisan admin:set-password nama-admin      # password diminta lewat input tersembunyi
php artisan optimize                           # cache konfigurasi, route, dan view
```

Buka `https://domainmu`, login admin, tekan **Sinkron sekarang**, dan pastikan banner API key tidak muncul.
Alamat `/up` menjawab 200 selama aplikasi sehat (berguna untuk pemantau uptime gratis seperti UptimeRobot).

## Memperbarui ke versi terbaru

Setelah kamu `git push` dari laptop, di server cukup:

```bash
cd /var/www/achylstic && bash deploy/deploy.sh
```

Skrip itu mengaktifkan mode perawatan, menarik kode, memasang dependensi, membangun aset, menjalankan migration,
membangun ulang cache, lalu membuka situs lagi.

## Keamanan singkat

- `APP_DEBUG=false` di produksi, supaya pesan error tidak membocorkan isi `.env`.
- Jangan pernah commit `.env`. Nginx di atas menolak semua file berawalan titik.
- Pakai password admin yang kuat dan unik, dan user MySQL khusus (bukan root).
- Aktifkan firewall: `sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable`.
- Pasang update keamanan otomatis: `sudo apt install unattended-upgrades`.

## Alternatif tanpa VPS

Untuk sekadar memamerkan sementara, Port Forwarding VS Code atau Cloudflare Tunnel dari laptop sudah cukup (lihat
bagian "Share it with friends" di README). Tapi situs mati saat laptop mati, dan data berhenti terbarui.

## Backup dan pemulihan

Scheduler membuat backup database **tiap malam pukul 03:30** ke `storage/app/backups/` (berkas
`achylstic-TANGGAL-JAM.sql.gz`), dan hanya menyimpan 14 yang terbaru (`BACKUP_KEEP` di `.env`). Halaman admin
menampilkan kapan backup terakhir dibuat, dan Discord memberi tahu kalau backup gagal. Coba sekali dengan:

```bash
php artisan db:backup
```

Backup yang tersimpan di server yang sama ikut hilang kalau servernya hilang, jadi **salin ke tempat lain**
secara berkala, misalnya dari laptopmu:

```bash
scp user@server:/var/www/achylstic/storage/app/backups/achylstic-*.sql.gz ./backup/
```

Memulihkan ke database kosong (sudah dites: jumlah pemain, match, dan snapshot cocok dengan aslinya):

```bash
gunzip -c achylstic-20261120-033000.sql.gz | mysql -u achylstic -p achylstic
```

Di Windows/Laragon, isi `MYSQLDUMP_PATH` di `.env` dengan alamat lengkap `mysqldump.exe`
(contoh ada di `.env.example`), lalu jalankan `php artisan db:backup`.
