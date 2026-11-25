# ABATA AI UMKM — Panduan deploy Railway dari GitHub (Windows)

Paket ini berasal dari `ABATA_AI_FULLSTACK(3).zip`, bukan proyek baru yang ditulis ulang. Frontend UMKM oranye, backend Laravel, model LightGBM, Newsvendor, dataset, dan logika operasional dipertahankan. Railway menjalankan Apache/PHP 8.4 dan Python dalam satu container, serta MySQL dalam service terpisah. Model menggunakan inference saja.

**Batasan:** Ini deployment demonstrasi online dengan dataset sintetis; model tetap `experimental_not_approved`. Paket tidak berisi database lokal (tidak ditemukan dump SQL dalam ZIP sumber), `.env`, password, virtual environment Windows, vendor, atau laporan lama berisi kredensial. Deployment Docker/Railway dan MySQL belum diuji langsung oleh pembuat paket. Simpan proyek lokal sebagai backup.

## 1. Ekstrak dan push ke GitHub

1. Ekstrak `ABATA_AI_RAILWAY.zip` ke folder baru (mis. `D:\ABATA_AI_RAILWAY`). Buka folder yang berisi `artisan`, `Dockerfile`, `railway.json`, `composer.json`, dan `ml_models/`. Jangan push ZIP-nya saja.
2. Buat repository **Private** di GitHub, mis. `abata-ai-railway`. Repository deployment baru lebih aman dibanding memakai riwayat repository lama yang mungkin pernah menyimpan rahasia. Ini hanya salinan deployment, tidak menghapus proyek lokal.
3. Jika repository GitHub sudah dibuat dan kosong, jalankan di PowerShell atau Git Bash dari folder hasil ekstrak:

```powershell
git init -b main
git add .
git status
git diff --cached --name-only
# pastikan file .env, dump SQL, vendor, .venv, password tidak tercantum
# .env.example hanya template kosong dan aman

git commit -m "Prepare ABATA AI for Railway deployment"
git remote add origin https://github.com/USERNAME/abata-ai-railway.git
git push -u origin main
```

Ganti `USERNAME` dengan username GitHub Anda. Jika menggunakan GitHub Desktop, pilih Add Local Repository / Create Repository dari folder ekstrak, commit dan Publish Repository dengan pilihan private. Jika repo sudah memiliki README/commit, clone repo itu ke folder bersih dan salin isi paket sebelum commit; jangan force-push. Pastikan `.gitattributes` ikut dipush supaya Git Windows tidak mengubah byte CSV/model/pipeline yang diverifikasi SHA-256. Pastikan `composer.lock`, semua 40 file model, serta `public/app.css` dan `public/app.js` ikut terunggah. Tidak perlu instal PHP/Python/NPM untuk push.

Jika `.env` atau password lama pernah ter-commit atau dibagikan, anggap sudah terekspos: ganti kredensial terkait. `.gitignore` tidak menghapus sejarah Git atau file yang sudah tracked. Jangan copy `.git` lama ke folder ini.

## 2. Buat proyek dan MySQL di Railway

1. Buka https://railway.com/ dan login menggunakan GitHub yang sudah Anda hubungkan. Izinkan akses ke repository private yang dipilih.
2. Dashboard > New Project > Empty Project (atau pilihan proyek kosong). Beri nama ABATA AI.
3. Di canvas pilih **+ New > Database > Add MySQL** (nama menu dapat sedikit berbeda). Tunggu sampai service MySQL aktif. Namai service tepat `MySQL` atau sesuaikan reference variables nanti.
4. Pastikan MySQL memiliki volume persisten yang terpasang (template normal memasang volume pada `/var/lib/mysql`). Jangan hapus volume. Tidak perlu membuat domain HTTP untuk MySQL; gunakan private networking.
5. Pilih salah satu jalur database di bawah **sebelum deploy aplikasi**.

### A. Database baru dari dataset (paling mudah untuk presentasi)

Tidak perlu import SQL manual. Biarkan database Railway baru kosong. Saat aplikasi pertama kali start dengan `ABATA_INIT_MODE=seed`, entrypoint menjalankan migration dan seeder. Seeder membuat 2.920 histori produk-hari, 4.380 catatan menu-hari, 4 bahan, 6 menu, kalender, stok awal, model registry dan 3 akun. Hari demo awal: 25 November 2026. Transaksi percobaan yang dibuat di localhost setelah seed tidak ikut.

Anda menentukan 3 password baru dan unik pada Railway Variables. Setelah pertama kali sukses, ubah `ABATA_INIT_MODE=existing` sehingga tidak ada reseed otomatis jika database kemudian hilang. Password seed boleh dihapus dari Railway Variables setelah inisialisasi (login tetap memakai hash di MySQL). Lakukan perubahan mode dan APP_URL sekaligus jika memungkinkan.

### B. Import database lokal (jika ingin mempertahankan seluruh transaksi sebelumnya)

Pilih ini jika database lokal sudah diverifikasi benar dan ingin melanjutkan tanggal operasional yang sama. Tidak ada sinkronisasi otomatis antara lokal dan Railway. Ekspor database lokal sebelum deploy pertama; gunakan database Railway kosong. Hentikan input transaksi sementara selama backup. Jangan memasukkan dump SQL ke GitHub.

Di **Command Prompt** Windows, menggunakan MySQL 8.x client yang tersedia di komputer Anda (tambahkan folder `bin` MySQL ke PATH atau gunakan path absolut):

```bat
mysqldump --host=127.0.0.1 --port=3306 --user=USER_LOKAL -p --single-transaction --no-tablespaces --set-gtid-purged=OFF --column-statistics=0 --skip-add-drop-table --result-file="D:\abata-backup.sql" abata_reference_demo
```

Ganti user, port dan nama database dengan nilai dari `.env` lokal Anda; masukkan password saat diminta. Periksa bahwa ekspor berhasil dan tidak kosong. Dump harus mencakup struktur + data, termasuk `migrations`, `demo_state`, `model_registries`, `users` dan semua tabel bisnis. Jangan hanya mengekspor `daily_history`. Jika menggunakan GUI (MySQL Workbench), gunakan Data Export > struktur dan data; jangan sertakan CREATE DATABASE/USE database lokal.

Cara import melalui Railway CLI (memerlukan Node.js/npm hanya pada komputer untuk CLI, bukan untuk menjalankan website):

```powershell
npm install -g @railway/cli
railway login
railway link
railway connect MySQL --tunnel-only --port 13306
```

Saat `railway link`, pilih project dan environment yang benar. Biarkan terminal tunnel terbuka dan baca host, port, username, database, password yang ditampilkan **secara privat**. Pada Command Prompt kedua, gunakan nilai tunnel tadi:

```bat
mysql --host=127.0.0.1 --port=13306 --user=USER_RAILWAY -p --database=NAMA_DB_RAILWAY -e "SHOW TABLES;"
mysql --host=127.0.0.1 --port=13306 --user=USER_RAILWAY -p --database=NAMA_DB_RAILWAY < "D:\abata-backup.sql"
mysql --host=127.0.0.1 --port=13306 --user=USER_RAILWAY -p --database=NAMA_DB_RAILWAY -e "SELECT COUNT(*) FROM daily_history; SELECT open_date,closed_through FROM demo_state;"
```

Jalankan perintah import hanya jika `SHOW TABLES` menunjukkan database target kosong. Ganti placeholder dengan nilai yang muncul pada tunnel, bukan nilai database lokal. Jika tunnel tidak tersedia pada akun/CLI Anda, gunakan MySQL Workbench dengan TCP Proxy sementara dari MySQL > Settings > Networking > Public Access; gunakan host/port proxy yang diberikan Railway, koneksi TLS bila tersedia/diwajibkan, lalu nonaktifkan proxy setelah selesai. Jangan gunakan endpoint publik untuk koneksi Laravel. Tutup tunnel dengan Ctrl+C setelah import.

Gunakan `ABATA_INIT_MODE=existing` sejak deploy pertama. Jangan seed ulang. App key Railway baru boleh dibuat karena sesi lokal tidak perlu dipindah, hash password pengguna tetap berlaku. Sebelum Generate Domain, ganti password akun yang pernah dibagikan: SSH ke service ABATA (lihat langkah 7), jalankan `php artisan abata:reset-password admin`, lalu manager dan cashier. Masukkan password baru secara interaktif. Password seed tidak digunakan untuk database impor. Jika model registry tidak cocok dengan manifest paket, proses start akan menolak; jangan bypass checksum atau edit SQL sembarangan. Bandingkan versi sumber/manifest dari lokal dan paket dan lakukan sinkronisasi terverifikasi. Jika database sebelumnya sudah memuat perubahan model atau data yang tidak kompatibel, gunakan jalur A atau selesaikan inkonsistensi terlebih dahulu.

## 3. Tambahkan service website dari GitHub

1. Pada project yang sama dengan MySQL, + New > GitHub Repo. Pilih repository deployment Anda, branch `main`. Beri nama service `ABATA`.
2. Pilih Add Variables (jika muncul), jangan Deploy Now sebelum variabel lengkap. Bila Railway otomatis memulai build saat repo ditambahkan, tidak masalah; aplikasi akan menolak start tanpa APP_KEY/database yang benar. Terapkan variabel dan redeploy setelah lengkap.
3. Service ABATA > Settings: Root Directory kosong atau `/` jika Dockerfile di root repo. Jika Anda malah push folder bersarang, atur root sesuai folder dan config file path; lebih sederhana push isi folder langsung ke root. Builder Dockerfile (otomatis terdeteksi); Dockerfile Path `Dockerfile`. Jangan isi custom Start Command, Build Command, atau pre-deploy command. `railway.json` sudah mengatur healthcheck `/up`, timeout 300 detik, restart terbatas.
4. Satu replica saja untuk demo. Port 8080. Jika ada pengaturan batas resource, uji RAM service web mendekati batas trial 1 GB, bukan batas terlalu rendah. Container dibatasi 2 Apache worker dan 1 thread per library numerik. Pantau metrics; ini bukan jaminan muat atau biaya tertentu.

## 4. Siapkan APP_KEY dan variables

Di PowerShell Windows, jalankan (tidak perlu Laravel lokal):

```powershell
$b = New-Object byte[] 32
$rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
$rng.GetBytes($b)
'base64:' + [Convert]::ToBase64String($b)
$rng.Dispose()
```

Salin hasil yang dimulai `base64:` ke Railway, jangan ke GitHub. Simpan key secara privat dan pertahankan pada deploy berikutnya.

Di service ABATA > Variables > Raw Editor, paste isi `RAILWAY_VARIABLES.example.txt` (baris komentar bisa dihapus). Ganti seluruh placeholder. Jika belum memiliki domain Railway, pakai `APP_URL=https://example.invalid` sementara; jangan buka publik sampai APP_URL aktual dan akun aman. Isi mode sesuai jalur A atau B. Berikut konfigurasi lengkap:

```dotenv
APP_NAME="ABATA AI"
APP_ENV=production
APP_DEBUG=false
APP_KEY=REPLACE_WITH_GENERATED_BASE64_KEY
APP_URL=https://example.invalid
PORT=8080
ABATA_TRUST_PROXY=true
DB_CONNECTION=mysql
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}
PYTHON_BIN=/opt/abata-venv/bin/python
SESSION_DRIVER=file
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CACHE_STORE=file
QUEUE_CONNECTION=sync
LOG_CHANNEL=stderr
LOG_LEVEL=warning
ABATA_INIT_MODE=seed
ABATA_ADMIN_PASSWORD=REPLACE_WITH_UNIQUE_ADMIN_SECRET
ABATA_MANAGER_PASSWORD=REPLACE_WITH_UNIQUE_MANAGER_SECRET
ABATA_CASHIER_PASSWORD=REPLACE_WITH_UNIQUE_CASHIER_SECRET
```

- Password seed: minimal 16, maksimal 72 karakter, unik dan bukan placeholder. Gunakan password manager; jangan gunakan password akun demo lama. Jika password berisi karakter khusus dan Raw Editor salah parsing, masukkan melalui New Variable satu per satu. Mode `existing`: hapus tiga variabel password seed dari daftar.
- Referensi `MySQL` harus sesuai nama service database. Gunakan Add Reference bila Raw Editor tidak mengenali referensi. Jangan gunakan `127.0.0.1` untuk DB_HOST atau `MYSQL_PUBLIC_URL` untuk aplikasi.
- `APP_ENV=production` berarti konfigurasi runtime Laravel (bukan klaim model production-ready). `APP_DEBUG=false` mencegah stack trace/rahasia muncul di browser. HTTPS secure cookie dan trusted proxy Railway sudah disiapkan. Session/cache file bersifat sementara: login ulang setelah redeploy mungkin diperlukan. Database persisten.
- Tidak perlu file `.env` di Railway, jangan upload `.env` lokal. Jangan memasang APP_KEY atau password di build args. Terapkan staged changes lewat tombol Deploy/Apply.

## 5. Deploy dan periksa log

Klik Deploy. Pada service ABATA > Deployments, lihat Build Logs dan Deploy Logs.

Build memasang PHP extensions, Composer dependencies berdasarkan `composer.lock`, Python venv berdasarkan requirements, serta menjalankan 13 tes Python (termasuk checksum model, seed, PHP CSV round-trip). Tidak ada training ulang, npm build, atau pengunduhan model eksternal; CSS sudah tersedia.

Startup menjalankan package discovery, pemeriksaan database, migration aman, seed jika dan hanya jika mode `seed` dan belum diinisialisasi, validasi registry, kompilasi Blade, lalu Apache di port 8080. Tidak ada `migrate:fresh`, penghapusan database, atau seed pada database berisi state.

Indikator yang diharapkan (bukan log aktual dari Railway):
- Build logs: `Ran 13 tests` dan `OK`.
- Deploy logs: `ABATA database ready. Existing history preserved.`
- Apache berjalan; healthcheck `/up` mendapatkan HTTP 200.
- Status deployment Active/Success.

Apabila tidak berhasil, buka log pertama yang gagal dan gunakan troubleshooting di bawah. Jangan reset database untuk mencoba lagi.

## 6. Generate domain HTTPS

1. Service **ABATA**, bukan MySQL > Settings > Networking > Public Networking > Generate Domain.
2. Jika diminta target port, isi 8080. Salin domain aktual, misalnya `abata-xxxx.up.railway.app` (hanya contoh).
3. Ubah APP_URL menjadi `https://DOMAIN-AKTUAL` (tanpa slash akhir). Jika seed sudah berhasil, ubah juga ABATA_INIT_MODE=existing, hapus 3 variabel password seed. Apply / Deploy sekali lagi.
4. Buka `https://DOMAIN-AKTUAL/up` dan `https://DOMAIN-AKTUAL/login`. Domain HTTPS dikelola Railway. Jangan gunakan HTTP untuk login.

## 7. Login dan uji fungsi

- `admin@abata.local` — Administrator.
- `manager@abata.local` — Pengelola.
- `cashier@abata.local` — Kasir.

Password sesuai variabel seed pertama atau password hasil rotasi jika database impor. Jika tidak bisa login, jangan reseed. Gunakan Railway SSH (klik kanan service ABATA > Copy SSH Command; ikuti instruksi CLI/key). Contoh: setelah menghubungkan CLI ke project yang benar dan memilih service ABATA, `railway ssh`. Dalam shell remote:

```sh
php artisan migrate:status
php artisan abata:reset-password manager
```

Untuk membuat forecast dari CLI remote (opsional, hanya ketika hari operasional belum dimulai):

```sh
php artisan abata:forecast
```

Jangan menjalankan `railway run php artisan ...` dengan asumsi itu shell remote; `railway run` menjalankan command di komputer lokal dengan environment variables Railway. Gunakan `railway ssh` atau tombol SSH dashboard.

Uji sebagai Manager: Ringkasan Usaha > Saran Belanja > Perbarui prediksi. Periksa 8 prediksi dan 16 rekomendasi dalam detail; model tidak dilatih ulang. Jika database impor sudah memulai aktivitas harian, jangan paksa forecast. Selesaikan atau lanjutkan aktivitas sesuai tanggal operasional dan baru buat forecast setelah closing yang sah. Uji kasir untuk transaksi dan RBAC. Untuk uji transaksi mutatif penuh (PO, receipt, POS, waste, closing), gunakan database yang memang diperuntukkan untuk demo/tes; perubahan nyata tersimpan dan closing tidak bisa di-undo. Jangan uji destruktif pada data yang ingin dipertahankan.

Smoke test checklist: login ketiga role, tampilan oranye/CSS, generate prediksi, saran belanja, pembuatan draft sekaligus, persetujuan, penerimaan, produksi, POS, inventory, laporan harian, closing, prediksi berikutnya. Periksa `/up`, logs dan penggunaan RAM/CPU. Pastikan data tetap tersedia setelah redeploy. Jangan mengklaim production-ready sebelum pengujian aktual dan validasi data nyata.

## 8. Update berikutnya dan backup

Cukup commit dan push perubahan ke branch main. Railway biasanya auto-deploy GitHub jika pengaturan tersebut aktif. Jangan menimpa model/dataset yang dicek checksum; `.gitattributes` wajib dipertahankan. Setelah update, periksa logs/healthcheck, login ulang jika diperlukan. APP_KEY harus tetap sama dan MySQL volume tetap terpasang. Jangan jalankan migrate:fresh, db:wipe, atau hapus volume.

Backup MySQL melalui tunnel seperti langkah 2B, tetapi jalankan `mysqldump` terhadap database Railway dan simpan output di luar repo. Simpan APP_KEY/password secara privat dan cadangkan database sebelum trial berakhir. Volume trial dapat dihapus 30 hari setelah kredit berakhir. Jika berencana mengakhiri demo, hentikan service web dan database setelah backup; jangan menghapus volume sebelum memastikan backup bisa dipulihkan.

## 9. Troubleshooting

| Gejala | Periksa / tindakan |
|---|---|
| Railway memakai Railpack atau gagal mendeteksi aplikasi | Root repo berisi Dockerfile; pilih builder Dockerfile dan jangan isi start command manual. |
| Composer gagal | Periksa log, PHP 8.4, composer.lock, extension yang dilaporkan; jangan `--ignore-platform-reqs`. |
| Python pip / regression tests gagal | Periksa package wheel dan traceback. Jangan menghapus tes atau checksum. Model native TXT tidak memerlukan retraining. |
| Healthcheck timeout / 502 | PORT dan target port 8080; periksa deployment log, database, APP_KEY. Startup menunggu koneksi MySQL. |
| MySQL unavailable | Nama service `MySQL`, reference variables, private network dan status volume. |
| Existing database has no ABATA state | Impor dump lengkap terlebih dahulu atau, untuk database kosong yang memang baru, set mode seed dan password. |
| Partial or non-empty database detected | Jangan paksa seed. Periksa database target, restore backup yang kompatibel atau gunakan database kosong terpisah. |
| Model registry mismatch | Bandingkan hash manifest lokal dengan registry; jangan bypass validasi. Pastikan paket versi sama dengan database. |
| CSS tidak tampil / login 419 | Gunakan HTTPS dan APP_URL aktual, proxy flag true, secure cookie true; clear cookie atau login kembali. Periksa Network/Console. |
| Out of memory / service restart | Pantau metrics, hindari forecast paralel, pertahankan satu replica dan batasi worker. Trial mungkin tidak cukup untuk beban tinggi. |
| Forecast ditolak setelah transaksi | Perilaku desain; jalankan setelah penutupan hari dan sebelum aktivitas berikutnya. |
| Data tiba-tiba kembali ke awal | Periksa apakah database/volume diganti. Gunakan mode existing setelah seed; restore backup, jangan reseed. |

## 10. Biaya dan referensi resmi

Railway trial menyediakan kredit $5 sekali pakai sampai 30 hari (mana yang lebih dulu tercapai). Web + MySQL memakai kredit bersama, kapasitas trial dibatasi, dan tidak dijamin bertahan 30 hari. Free plan setelah trial memiliki kredit $1/bulan serta batas resource lebih kecil; fitur mungkin tidak cukup untuk ABATA. Lihat halaman Usage/Billing secara berkala; jangan upgrade tanpa menyetujui biaya. Tidak ada jaminan harga tetap atau akses akun Full Trial.

Dokumentasi resmi (diperiksa 26 November 2026):
- https://docs.railway.com/quick-start
- https://docs.railway.com/builds/dockerfiles
- https://docs.railway.com/config-as-code/reference
- https://docs.railway.com/databases/mysql
- https://docs.railway.com/variables
- https://docs.railway.com/networking/public-networking
- https://docs.railway.com/deployments/healthchecks
- https://docs.railway.com/cli/connect
- https://docs.railway.com/cli/ssh
- https://docs.railway.com/pricing/free-trial
- https://docs.railway.com/pricing/free-plan

Jangan memasukkan token GitHub/Railway, APP_KEY, password, dump database, atau screenshot Variables yang menampilkan nilai rahasia ke chat publik.
