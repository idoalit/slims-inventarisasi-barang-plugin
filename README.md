# Inventaris Barang Perpustakaan

Plugin SLiMS 9 untuk mencatat barang inventaris berdasarkan lokasi/ruangan dan mencetak **Kartu Inventaris Ruangan** dalam PDF.

## Data yang dicatat

- Lokasi: referensi master lokasi SLiMS (`mst_location`), kode kartu, ruangan, provinsi, kabupaten/kota, unit, satuan kerja, kota penandatanganan, serta identitas penandatangan.
- Barang: nama barang, merk/model, nomor seri pabrik, ukuran, bahan, tahun pembuatan/pembelian, kode barang, jumlah/register, harga perolehan, kondisi (B/KB/RB), dan keterangan.

## Instalasi

1. Pastikan folder ini berada di `plugins/inventaris-barang` pada instalasi SLiMS.
2. Jalankan `composer install --no-dev` dari direktori plugin untuk memasang mPDF.
3. Masuk ke panel admin SLiMS sebagai administrator.
4. Buka **System → Plugins**, lalu aktifkan **Inventaris Barang Perpustakaan**. Saat diaktifkan, migrasi akan membuat tabel `inventory_locations` dan `inventory_items`.
5. Buka **Stock Take → Inventaris Barang**.

Jika memperbarui dari versi sebelumnya, buka kembali **System → Plugins** lalu aktifkan plugin. SLiMS akan menjalankan migrasi versi 2 untuk menambahkan referensi `mst_location`; data lama tetap dipertahankan dan dapat dihubungkan melalui menu **Ubah Lokasi**.

## Penggunaan

1. Tambahkan lokasi/ruangan beserta identitas pada kepala dan tanda tangan kartu.
2. Hubungkan ruangan dengan **Lokasi Master SLiMS** bila diperlukan.
3. Tambahkan barang ke lokasi tersebut.
4. Gunakan filter lokasi master SLiMS atau ruangan inventaris pada daftar barang.
5. Pada daftar lokasi, klik **Cetak PDF**. Hanya barang dari lokasi itu yang akan masuk ke kartu.

PDF menggunakan ukuran 330 × 216 mm (lanskap) dan mengikuti struktur template Kartu Inventaris Ruangan. Jika data kurang dari 13 barang, PDF tetap menyediakan 13 baris seperti template.

Hak baca/tulis mengikuti privilege modul **Stock Take**. Endpoint PDF juga memerlukan sesi admin SLiMS yang aktif dan pembatasan 10 permintaan per menit per sesi.

Seluruh formulir pengelolaan dan filter menggunakan handler AJAX resmi SLiMS (`submitViaAJAX`), sehingga konten diperbarui tanpa memuat ulang halaman admin.

PDF hanya memuat maksimal 500 barang per lokasi dan dikirim dengan kebijakan cache `private, no-store`. Aktivitas create, update, delete, penolakan keamanan, serta pencetakan dicatat melalui system log SLiMS.

Plugin menggunakan mPDF 8.3.1 atau lebih baru yang dikelola Composer dari direktori plugin. Folder `vendor` tidak disimpan dalam Git.

Untuk deployment HTTPS, pastikan cookie sesi SLiMS dikonfigurasi dengan atribut `Secure`, `HttpOnly`, dan `SameSite=Lax` pada konfigurasi instalasi utama.

## Pemeriksaan cepat

```bash
php tests/pdf_template_test.php
php tests/ajax_forms_test.php
php tests/security_controls_test.php
php tests/master_location_integration_test.php
php tests/mpdf_runtime_test.php
```
