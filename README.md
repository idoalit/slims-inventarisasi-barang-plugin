# Inventaris Barang Perpustakaan

Plugin SLiMS 9 untuk mencatat inventaris barang per lokasi/ruangan dan mencetak **Kartu Inventaris Ruangan** dalam PDF. Halaman awal menampilkan daftar ruangan; daftar barang baru muncul setelah ruangan dipilih.

## Instalasi

1. Tempatkan plugin di `plugins/inventaris-barang` pada instalasi SLiMS.
2. Pastikan PHP memiliki ekstensi GD dan mbstring, lalu jalankan dari direktori plugin:

   ```bash
   composer install --no-dev
   ```

3. Pastikan direktori cache SLiMS (`files/cache` pada konfigurasi standar) dapat ditulis oleh proses PHP.
4. Masuk sebagai administrator, buka **System → Plugins**, lalu aktifkan **Inventaris Barang Perpustakaan**. Migrasi plugin membuat tabel `inventory_locations` dan `inventory_items` serta menambahkan referensi ke master lokasi perpustakaan (`mst_location`).
5. Buka **Stock Take → Inventaris Barang**.

Pada pembaruan instalasi lama, jalankan migrasi plugin melalui **System → Plugins**. Migrasi versi 2 menambahkan referensi master lokasi tanpa menghapus data lama. Hubungkan ruangan yang sudah ada melalui ikon edit pada daftar lokasi.

Dependensi PDF dideklarasikan sebagai `mpdf/mpdf: ^8.3.1`; `composer install` memasang versi yang tercatat di `composer.lock`. Folder `vendor` tidak disimpan dalam Git. Jika kode dan dependensi disalin ke image container saat build, perubahan memerlukan rebuild image, lalu recreate container.

## Penggunaan

### Daftar lokasi/ruangan

- Halaman awal hanya menampilkan lokasi/ruangan beserta jumlah barangnya. Gunakan **Filter Lokasi** untuk menyaring berdasarkan lokasi master SLiMS.
- Klik **Tambah Lokasi** untuk mencatat ruangan dan identitas yang akan tampil pada kartu inventaris.
- Klik **Lihat Barang** pada ruangan untuk membuka daftar barang di dalamnya.

### Barang dalam ruangan

Bagian atas halaman menampilkan ringkasan nama ruangan, kode, lokasi master, dan jumlah barang. Klik **Detail lokasi** untuk membuka informasi wilayah, unit, dan satuan kerja.

- Klik **Tambah Barang** untuk mencatat barang pada ruangan tersebut.
- Gunakan pencarian berdasarkan nama barang, kode, atau merk/model.
- Setelah menyimpan barang, halaman menampilkan ruangan tempat barang disimpan, termasuk jika barang dipindahkan ke ruangan lain. Setelah menghapus barang, halaman tetap menampilkan ruangan yang sedang dibuka.
- Klik **Kembali ke Daftar Lokasi** untuk memilih ruangan lain.

### Mengubah dan menghapus data

Tabel lokasi dan barang menggunakan datagrid bawaan SLiMS, dengan pengurutan kolom dan pagination 20 baris per halaman.

- **Ubah:** klik ikon edit pada baris lokasi atau barang.
- **Hapus:** centang satu atau beberapa baris, lalu gunakan tombol hapus data terpilih. Tombol pilih semua dan batal pilih semua mengikuti kontrol standar SLiMS.
- Penghapusan lokasi juga menghapus seluruh barang di dalamnya. Konfirmasi penghapusan lokasi menyebutkan akibat ini.

Kontrol tambah, edit, dan hapus tersedia bagi pengguna dengan hak tulis modul **Stock Take**. Pengguna dengan hak baca dapat melihat data dan mencetak PDF.

### Cetak PDF

Klik **Cetak PDF** pada daftar lokasi atau halaman ruangan. Dokumen dibuka di tab baru dan memuat barang dari ruangan tersebut.

- Ukuran kertas 330 × 216 mm (lanskap).
- Minimal 13 baris barang; baris kosong ditambahkan jika data kurang dari 13.
- Maksimal 500 barang per ruangan untuk pencetakan. Jika lebih, permintaan ditolak; dokumen tidak dipotong menjadi 500 barang. Batas ini tidak membatasi jumlah data yang dapat ditelusuri melalui pagination.
- Maksimal 10 permintaan PDF per menit per sesi.

## Data yang dicatat

- **Lokasi:** referensi master lokasi perpustakaan, kode kartu, ruangan, provinsi, kabupaten/kota, unit, satuan kerja, kota penandatanganan, serta jabatan, nama, dan identitas penandatangan.
- **Barang:** nama barang, merk/model, nomor seri pabrik, ukuran, bahan, tahun pembuatan/pembelian, kode barang, jumlah/register, harga perolehan, kondisi (B/KB/RB), dan keterangan.

## Integrasi SLiMS

Formulir pengelolaan dan filter memakai handler AJAX `submitViaAJAX`. Edit dan penghapusan melalui datagrid memakai mekanisme standar SLiMS; formulir penghapusan dilengkapi token CSRF plugin dan responsnya memperbarui daftar di panel admin.

Hak akses mengikuti modul `stock_take`. Perubahan data memerlukan hak tulis dan token CSRF. Penghapusan barang melalui daftar ruangan dibatasi ke ruangan yang sedang dibuka. Aktivitas tambah, ubah, hapus, penolakan keamanan, dan pencetakan dicatat melalui system log SLiMS.

PDF diproses melalui `admin/plugin_container.php` dengan aksi `print_pdf`, memerlukan sesi admin aktif, serta mengikuti pemeriksaan IP `smc` dan `smc-stocktake`. Respons PDF memakai kebijakan cache `private, no-store`.

## Pemecahan masalah

- **Kode PHP tampil saat mencetak:** buka ulang menu plugin dan gunakan tombol **Cetak PDF**. Jangan mengakses `plugins/inventaris-barang/print.php` langsung; konfigurasi `plugins/.htaccess` SLiMS menonaktifkan eksekusi PHP langsung pada handler yang terkait.
- **PDF gagal dibuat:** periksa log PHP, pemasangan dependensi Composer, dan izin tulis direktori cache SLiMS.
- **Error kompatibilitas `setLogger(...): void`:** periksa pustaka mPDF dan PSR Log yang dimuat oleh instalasi utama maupun plugin. Jika error menunjuk ke `lib/psr-log-aware-trait`, kedua trait bawaan SLiMS (`MpdfPsrLogAwareTrait.php` dan `PsrLogAwareTrait.php`) perlu deklarasi `setLogger(LoggerInterface $logger): void` yang kompatibel. Perbaikan pustaka utama ini berada di luar repositori plugin; pastikan ikut terpasang pada server atau image container.

## Pemeriksaan cepat

Jalankan dari direktori plugin di dalam instalasi SLiMS:

```bash
php tests/pdf_template_test.php
php tests/ajax_forms_test.php
php tests/security_controls_test.php
php tests/master_location_integration_test.php
```

Setelah dependensi Composer tersedia, uji pembuatan PDF:

```bash
php tests/mpdf_runtime_test.php
php tests/render_pdf_sample.php /tmp/inventory-sample.pdf
```

Tes runtime memeriksa bahwa mPDF berasal dari instalasi Composer dan dapat menghasilkan PDF. Tes ini memerlukan ekstensi GD dan mbstring. Pemeriksaan otomatis tersebut tidak menggantikan uji alur login, edit, hapus, dan cetak melalui browser pada instalasi tujuan.
