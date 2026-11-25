@extends('layouts.admin_new')
@section('style')
    <link rel="stylesheet" href="{{ asset('main/libs/select2/select2.css') }}">
    <style>
        .topup-stat {
            border: 1px solid #d9dee3;
            border-radius: .5rem;
            padding: .75rem 1rem;
            background: #fff;
            height: 100%;
        }
        .topup-stat .label {
            font-size: .8rem;
            color: #8592a3;
            font-weight: 600;
            letter-spacing: .03em;
            text-transform: uppercase;
        }
        .topup-stat .value {
            font-size: 1.25rem;
            font-weight: 700;
            color: #566a7f;
        }
        .badge-status-siap { background: rgba(var(--bs-success-rgb), .12); color: var(--bs-success); }
        .badge-status-sudah { background: rgba(var(--bs-secondary-rgb), .12); color: #697a8d; }
        .badge-status-kosong,
        .badge-status-ganda { background: rgba(var(--bs-danger-rgb), .12); color: var(--bs-danger); }
        #table-preview td, #table-preview th { vertical-align: middle; }
        tr.row-nominal-ganjil { background: rgba(var(--bs-warning-rgb), .12) !important; }
        tr.row-nominal-ganjil td.text-nominal { font-weight: 700; color: #d59a00; }
    </style>
@endsection

@section('content')
    <h3 class="page-heading d-flex text-gray-900 fw-bold flex-column justify-content-center my-0">
        {{ $dataTitle ?? 'Input Kas Top Up ke Jurnal' }}
    </h3>
    <ul class="breadcrumb breadcrumb-style2">
        <li class="breadcrumb-item"><a href="{{ route('admin.index') }}" class="text-hover-primary">Beranda</a></li>
        <li class="breadcrumb-item">{{ $title ?? 'Pencatatan Sederhana' }}</li>
        <li class="breadcrumb-item active">{{ $mainTitle ?? 'Input Kas Top Up' }}</li>
    </ul>

    <div class="alert alert-info py-2">
        Menu ini menyimpan transaksi <strong>scctran Top Up</strong> per periode ke <strong>akt_jurnal</strong> (Kas Masuk).
        Dropdown <strong>Akun Kas Masuk</strong> hanya tujuan jurnal saat simpan — <strong>tidak memfilter</strong> daftar Top Up.
        <strong>Nominal Total</strong> = jumlah semua <code>KREDIT</code> Top Up di periode itu.
        <strong>NOREFF</strong> tidak boleh dobel pada periode yang sama (disimpan di kolom <code>no_ref</code>).
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><h5 class="mb-0">Pilih Periode &amp; Akun</h5></div>
                <div class="card-body">
                    <form id="form-topup">
                        <div class="mb-3">
                            <label class="form-label required" for="tahun">Tahun</label>
                            <select class="form-select" id="tahun" name="tahun" required>
                                @foreach(($tahunList ?? []) as $tahun)
                                    <option value="{{ $tahun }}" @selected(($tahunDefault ?? date('Y')) == $tahun)>{{ $tahun }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label required" for="periode">Bulan</label>
                            <select class="form-select" id="periode" name="periode" required>
                                @foreach(($bulanList ?? []) as $num => $label)
                                    <option value="{{ $num }}" @selected(($bulanDefault ?? date('n')) == $num)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label required" for="kode_akun">Akun Kas Masuk</label>
                            <select class="form-select" id="kode_akun" name="kode_akun" required data-control="select2">
                                <option value="">Pilih akun</option>
                                @foreach(($akunMasuk ?? []) as $akun)
                                    <option value="{{ $akun->KodeAkunMasuk }}">
                                        {{ $akun->KodeAkunMasuk }} — {{ $akun->NamaAkunMasuk }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="button" class="btn btn-outline-primary" id="btn-preview">
                                <i class="ri-search-line me-1"></i> Cek Periode
                            </button>
                            <button type="button" class="btn btn-primary" id="btn-simpan">
                                <i class="ri-save-line me-1"></i> Simpan ke Jurnal
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header"><h5 class="mb-0">Ringkasan Periode <span id="label-periode" class="text-muted"></span></h5></div>
                <div class="card-body">
                    <div class="row g-3" id="summary-cards">
                        <div class="col-6 col-md-4">
                            <div class="topup-stat">
                                <div class="label">Total Top Up</div>
                                <div class="value" id="stat-total">0</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="topup-stat">
                                <div class="label">Siap Disimpan</div>
                                <div class="value text-success" id="stat-siap">0</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="topup-stat">
                                <div class="label">Sudah di Jurnal</div>
                                <div class="value" id="stat-sudah">0</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="topup-stat">
                                <div class="label">NOREFF Kosong/Ganda</div>
                                <div class="value text-danger" id="stat-issue">0</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="topup-stat">
                                <div class="label">Nominal Siap</div>
                                <div class="value" id="stat-nominal-siap">Rp 0</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="topup-stat">
                                <div class="label">Nominal Total</div>
                                <div class="value" id="stat-nominal-total">Rp 0</div>
                            </div>
                        </div>
                    </div>
                    <p class="text-muted small mb-0 mt-3" id="summary-note">Pilih periode lalu klik Cek Periode.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header header-elements">
            <h5 class="mb-0">Preview Top Up Periode</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0" id="table-preview">
                    <thead class="table-light">
                    <tr>
                        <th>Tanggal</th>
                        <th>NIS</th>
                        <th>Nama</th>
                        <th>No Ref</th>
                        <th class="text-end">Nominal</th>
                        <th class="text-center">Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr class="js-empty-row">
                        <td colspan="6" class="text-center text-muted py-4">Belum ada data. Klik Cek Periode.</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@section('script')
    <script src="{{ asset('main/libs/select2/select2.min.js') }}"></script>
    <script>
        const previewUrl = @json($previewUrl);
        const storeUrl = @json($storeUrl);

        function rupiah(n) {
            const num = Number(n || 0);
            return 'Rp ' + num.toLocaleString('id-ID');
        }

        function payloadFromForm() {
            return {
                tahun: document.getElementById('tahun').value,
                periode: document.getElementById('periode').value,
                kode_akun: document.getElementById('kode_akun').value,
            };
        }

        function statusBadge(status) {
            const map = {
                siap: ['Siap', 'badge-status-siap'],
                sudah: ['Sudah', 'badge-status-sudah'],
                kosong: ['NOREFF kosong', 'badge-status-kosong'],
                ganda: ['NOREFF ganda', 'badge-status-ganda'],
            };
            const [label, cls] = map[status] || [status || '-', 'badge-status-sudah'];
            return `<span class="badge ${cls}">${label}</span>`;
        }

        function renderSummary(summary, label) {
            document.getElementById('label-periode').textContent = label ? `(${label})` : '';
            document.getElementById('stat-total').textContent = summary.total ?? 0;
            document.getElementById('stat-siap').textContent = summary.siap ?? 0;
            document.getElementById('stat-sudah').textContent = summary.sudah ?? 0;
            document.getElementById('stat-issue').textContent = (summary.empty_noreff || 0) + (summary.duplicate_in_source || 0);
            document.getElementById('stat-nominal-siap').textContent = rupiah(summary.nominal_siap);
            document.getElementById('stat-nominal-total').textContent = rupiah(summary.nominal_total);

            const note = [];
            if ((summary.siap || 0) > 0) note.push(`${summary.siap} siap disimpan`);
            if ((summary.sudah || 0) > 0) note.push(`${summary.sudah} sudah ada di jurnal`);
            if ((summary.empty_noreff || 0) > 0) note.push(`${summary.empty_noreff} tanpa NOREFF`);
            if ((summary.duplicate_in_source || 0) > 0) note.push(`${summary.duplicate_in_source} NOREFF ganda di sumber`);
            const ganjil = (summary.rows || []).filter(r => Number(r.nominal || 0) % 1000 !== 0);
            if (ganjil.length > 0) {
                note.push(`${ganjil.length} nominal tidak bulat ribuan (baris kuning di preview)`);
            }
            document.getElementById('summary-note').textContent = note.length
                ? note.join(' · ')
                : 'Tidak ada Top Up di periode ini.';
        }

        function renderRows(rows) {
            const tbody = document.querySelector('#table-preview tbody');
            if (!tbody) return;
            if (!Array.isArray(rows) || rows.length === 0) {
                tbody.innerHTML = '<tr class="js-empty-row"><td colspan="6" class="text-center text-muted py-4">Tidak ada Top Up di periode ini.</td></tr>';
                return;
            }
            const sorted = [...rows].sort((a, b) => {
                const aOdd = Number(a.nominal || 0) % 1000 !== 0 ? 0 : 1;
                const bOdd = Number(b.nominal || 0) % 1000 !== 0 ? 0 : 1;
                return aOdd - bOdd;
            });
            tbody.innerHTML = sorted.map(row => {
                const odd = Number(row.nominal || 0) % 1000 !== 0;
                return `
                <tr class="${odd ? 'row-nominal-ganjil' : ''}">
                    <td>${row.tanggal || '-'}</td>
                    <td>${row.nis || '-'}</td>
                    <td>${row.nama || '-'}</td>
                    <td>${row.noreff || '-'}</td>
                    <td class="text-end text-nominal">${rupiah(row.nominal)}</td>
                    <td class="text-center">${statusBadge(row.status)}</td>
                </tr>`;
            }).join('');
        }

        async function fetchPreview() {
            const body = payloadFromForm();
            loadingAlert();
            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            const params = new URLSearchParams({ tahun: body.tahun, periode: body.periode });

            const res = await fetch(`${previewUrl}?${params.toString()}`, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf,
                },
            });
            const payload = await res.json().catch(() => ({}));
            if (!res.ok) throw payload;
            renderSummary(payload.summary || {}, payload.label || '');
            renderRows((payload.summary && payload.summary.rows) || []);
            if (typeof Swal !== 'undefined') Swal.close();
            return payload;
        }

        async function submitStore() {
            const body = payloadFromForm();
            if (!body.kode_akun) {
                errorAlert('Pilih Akun Kas Masuk dulu');
                return;
            }

            const confirm = await Swal.fire({
                icon: 'warning',
                title: 'Simpan Top Up ke jurnal?',
                text: 'Hanya baris berstatus Siap yang akan disimpan. NOREFF tidak boleh dobel di periode yang sama.',
                showCancelButton: true,
                confirmButtonText: 'Simpan',
                cancelButtonText: 'Batal',
            });
            if (!confirm.isConfirmed) return;

            loadingAlert();
            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            const fd = new FormData();
            fd.append('_token', csrf);
            fd.append('tahun', body.tahun);
            fd.append('periode', body.periode);
            fd.append('kode_akun', body.kode_akun);

            const res = await fetch(storeUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: fd,
            });
            const payload = await res.json().catch(() => ({}));
            if (!res.ok) {
                if (payload.summary) {
                    renderSummary(payload.summary, '');
                    renderRows(payload.summary.rows || []);
                }
                throw payload;
            }
            renderSummary(payload.summary || {}, '');
            renderRows((payload.summary && payload.summary.rows) || []);
            successAlert(payload.message || 'Berhasil disimpan');
        }

        document.addEventListener('DOMContentLoaded', function () {
            $('#kode_akun').select2({ width: '100%' });

            document.getElementById('btn-preview')?.addEventListener('click', function () {
                fetchPreview().catch(err => errorAlert(err.message || err.error || 'Gagal cek periode'));
            });

            document.getElementById('btn-simpan')?.addEventListener('click', function () {
                submitStore().catch(err => errorAlert(err.message || err.error || 'Gagal menyimpan'));
            });
        });
    </script>
@endsection
