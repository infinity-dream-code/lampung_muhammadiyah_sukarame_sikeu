# Ringkasan Perubahan — MIT Muhammadiyah Sukarame

Dokumen ini agar bisa dilanjutkan manual kalau AI kena limit.

**Tanggal:** 20 Okt 2026  
**Client:** MIT Muhammadiyah Sukarame  
**Lokasi:** Kota Bandar Lampung, Prov. Lampung  
**Icon:** `public/mit.png`  
**Prefix VA (Open Payment):** `777726`

---

## A. Branding client

| File | Apa yang diubah |
|------|-----------------|
| `.env` | `APP_NAME`, `APP_NAMA_INSTANSI` → `MIT Muhammadiyah Sukarame`; `APP_ALAMAT` → `Kota Bandar Lampung, Prov. Lampung`; `APP_DOMISILI` → `Kota Bandar Lampung`; `APP_LOGO` → `mit.png`; `APP_NOVA` → `777726`; `SESSION_COOKIE` → `sikeu_mit_sukarame_session` |
| `.env.example` | Sama seperti `.env` (template) |
| `config/app.php` | Default name/instansi/alamat/domisili/logo/nova; tambah `logo_mime` (otomatis dari ekstensi logo) |
| `config/session.php` | Default cookie session → `sikeu_mit_sukarame_session` |
| `app/Models/scctcust.php` | Fallback prefix VA → `777726` |
| `app/Support/PersistentLogin.php` | Nama cookie persistent → `mit_muhammadiyah_sukarame` (bukan `assyafiyah-sidoarjo`) |
| `resources/views/layouts/admin_new.blade.php` | Favicon `type="image/png"` |
| `resources/views/layouts/login_new.blade.php` | Favicon `type="image/png"` |
| `resources/views/layouts/login_layout.blade.php` | Favicon `type="image/png"` |
| `resources/views/layouts/export/kop_file.blade.php` | MIME logo dinamis (png/jpg/…) |
| `routes/web.php` | `get-logo` pakai `config('app.logo_mime')` |
| Blade VA fallback (`751095` → `777726`) | `data_penerimaan.blade.php`, `data_tagihan.blade.php`, `manual_pembayaran.blade.php`, `manual_pembayaran_nis.blade.php`, `manual_pembayaran_no_daftar.blade.php` |
| Blade logo data-URI | Beberapa export/cetak JS diganti pakai `config("app.logo_mime")` agar PNG tidak dipaksa JPEG |

**Catatan:** File logo harus ada di `public/mit.png` (sudah ada). Setelah ubah `.env`, jalankan:

```bash
php artisan config:clear
```

---

## B. Session / auth (WAJIB dipertahankan)

Aturan yang **tidak boleh diubah balik**:

1. **Jangan** notifikasi “Sesi Berakhir / Sesi habis / session expired”.
2. **Jangan** auto-logout saat user masih memakai sistem. Logout **hanya** jika user klik Logout.
3. `SESSION_LIFETIME=5256000`, `expire_on_close = false`.
4. CSRF / 419 → recover diam-diam (keep-alive refresh token + retry). **Jangan** redirect ke login.
5. Cookie persistent login `mit_muhammadiyah_sukarame` (`PersistentLogin` + middleware `RestorePersistentLogin`). Set saat login & saat authenticated; hapus **hanya** saat logout.
6. `RouteServiceProvider::HOME = '/admin'`. Route `GET /home` → redirect `admin.index`.
7. Error 500 transient → retry GET sekali; `errors/500.blade.php` auto-reload sekali.
8. `TrustProxies` `$proxies = '*'`.
9. Keep-alive di layout admin, ping berkala, **jangan** concurrent overlap (`keepAliveInFlight`).

### File session yang disentuh / diperkuat di putaran ini

| File | Apa |
|------|-----|
| `app/Support/PersistentLogin.php` | Cookie rename; tambah `recreateSession()`, `isSessionCorrupt()`; `unauthenticatedResponse()` recreate + redirect ke URL yang sama (bukan langsung login) |
| `app/Exceptions/Handler.php` | 419 → regenerate token + restore cookie, redirect ke URL sama; session corrupt → recreate + `_sess_retry`; tetap tanpa alert sesi |
| `app/Http/Middleware/RestorePersistentLogin.php` | Jika session corrupt di GET → recreate + redirect ke halaman yang sama |
| `app/Http/Middleware/RetryTransientGet.php` | Retry juga untuk `isSessionCorrupt` |
| `resources/views/errors/419.blade.php` | Key storage rename; reload halaman sama (bukan login) |
| `resources/views/errors/500.blade.php` | Key storage rename |

### File session penting yang **sudah ada** (jangan dihapus)

- `public/js/session-keepalive.js` — patch fetch/jQuery 419/401, suppress alert sesi
- `app/Http/Controllers/Admin/KeepAliveController.php`
- `app/Http/Middleware/Authenticate.php`, `CheckSession.php`, `EncryptCookies.php` (kecualikan cookie persistent)
- `app/Http/Kernel.php` — urutan: `RetryTransientGet` → `StartSession` → `RestorePersistentLogin` → …
- `config/session.php` — `lifetime`, `expire_on_close => false`

---

## C. Perilaku session corrupt / gagal dibaca

**Sebelum (yang dihindari):** session gagal dibaca → langsung anggap unauthenticated → redirect login / alert sesi habis.

**Sekarang:**

1. Deteksi error session corrupt / transient (`isSessionCorrupt` / `isTransient`).
2. `recreateSession()` → `session->migrate(true)` + `regenerateToken()` (hanya untuk kasus corrupt, **bukan** setiap 419 biasa).
3. Restore user dari cookie persistent (`bind` + `queue`, **bukan** `Auth::login()` yang migrate CSRF tiba-tiba).
4. Redirect ke **URL yang sama** (`_sess_retry=1` / `_auth_retry=1`) supaya user tetap di halaman sebelumnya.
5. AJAX 419/401 → JSON `{ ok, retry, token }` + JS keep-alive retry diam-diam.

---

## D. Checklist verifikasi cepat

- [ ] Login → nama/logo MIT muncul di title & favicon
- [ ] VA siswa = `777726` + nomor (panjang total 16)
- [ ] Kop surat / cetak alamat = Kota Bandar Lampung, Prov. Lampung
- [ ] Buka admin lama, biarkan idle, navigasi menu → **tidak** kena logout / alert sesi
- [ ] Simulasi 419 (token usang) → request retry / reload, **tetap di halaman**, tidak ke `/login`
- [ ] Klik Logout → baru keluar + cookie persistent terhapus
- [ ] `php artisan config:clear` setelah deploy `.env`

---

## E. Kalau “ada 1 lagi” belum sempat disebut

Item branding yang sudah diterapkan: **nama, icon, lokasi, prefix VA Open Payment**.  
Kalau item ke-5 (mis. email, telepon, subdomain, DB name, dll.) belum dikirim — sebutkan saja nanti supaya dilanjutkan.
