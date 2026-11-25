# ABATA AI Railway — Hasil pemeriksaan paket

Basis: `ABATA_AI_FULLSTACK(3).zip` (UMKM edition 2.0). Bukan deployment yang sudah berjalan online.

## Perubahan

- `Dockerfile`: PHP 8.4 Apache, PDO MySQL, Composer lock, Python venv dan dependency terpin utama, 13 regression tests saat build.
- `deploy/`: port Railway, document root public, worker terbatas, startup init aman, pengaturan PHP.
- `railway.json`: Dockerfile, healthcheck `/up`, timeout 300 detik, retry terbatas.
- `bootstrap/app.php`: trusted proxy khusus ketika `ABATA_TRUST_PROXY=true` (untuk Railway TLS termination).
- `routes/console.php`: `abata:railway-init` (guard database seed/existing) dan `abata:reset-password` (prompt rahasia).
- `.gitignore`, `.gitattributes`, `.dockerignore`: sanitasi dan preservasi byte artefak model pada Git.
- Panduan/variabel contoh/placeholder direktori runtime.
- Tidak disertakan: `.env` sumber, vendor, .venv, laporan lama, runtime cache, log, patch ZIP lama, checksum daftar file paket lama. Model manifest asli tetap utuh.

## Pengujian aktual (container Linux)

| Pemeriksaan | Hasil |
|---|---|
| Python unit / regression / PHP CSV bridge | 13/13 PASS |
| Python tests setelah simulasi Git clone dengan core.autocrlf=true | 13/13 PASS |
| Hash 40 file model (20 TXT + 20 Joblib), 5 dataset CSV, pipeline | PASS melalui test_artifact_checksums |
| Source asli: 107 file yang dipertahankan sebelum penambahan dokumentasi akhir | Byte-identik; 3 file asli berubah (.gitignore, bootstrap/app.php, routes/console.php) |
| PHP syntax (29 file) | PASS |
| Shell entrypoint syntax | PASS |
| JavaScript app.js syntax | PASS |
| railway.json parse | PASS |
| composer.lock content hash (algoritma JSON compact Composer) | Cocok 0a15c75cb8c36964d94c438cc77e1214 |
| Scan nilai kredensial lama yang panjangnya >=10 karakter pada file hasil | Tidak ditemukan kecocokan |
| Git tracked file check (.env, vendor, .venv, SQL dump) | Tidak ditemukan |

Python lokal 3.13.5, LightGBM 4.6.0, pandas 2.2.3, numpy 2.3.5, scikit-learn 1.8.0, joblib 1.5.3; PHP CLI 8.4.24. Container Docker yang disiapkan menggunakan Debian Bookworm/Python 3.11, sehingga tes yang sama wajib dijalankan lagi dalam Docker build. Native TXT estimator digunakan untuk inference; manifest tetap mencatat versi dependency saat training Colab (NumPy berbeda). Tidak melakukan retraining.

## Belum diverifikasi

Docker daemon/Composer runtime lengkap/PDO MySQL tidak tersedia di lingkungan pemeriksaan ini. Belum dilakukan build image Docker, PHP Composer install pada image, Laravel runtime, PHPUnit/MySQL race tests, import DB, HTTPS browser test, deployment nyata Railway atau pengukuran RAM/kredit. Konfigurasi dan panduan disediakan, bukan jaminan deployment pasti sukses. Periksa log aktual saat deploy dan lakukan smoke test sesuai panduan. Jika trial dibatasi resource, perlu penyesuaian atau paket hosting lain.

Model tetap `experimental_not_approved` dengan dataset sintetis; production runtime environment bukan persetujuan penggunaan operasional nyata.
