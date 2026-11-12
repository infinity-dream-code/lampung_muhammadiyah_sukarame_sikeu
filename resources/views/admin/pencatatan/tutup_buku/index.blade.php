@extends('layouts.admin_new')
@section('content')
    <h3 class="page-heading d-flex text-gray-900 fw-bold flex-column justify-content-center my-0">{{ $dataTitle ?? 'Simpan Jurnal Per Bulan' }}</h3>
    <ul class="breadcrumb breadcrumb-style2">
        <li class="breadcrumb-item"><a href="{{ route('admin.index') }}" class="text-hover-primary">Beranda</a></li>
        <li class="breadcrumb-item">{{ $title ?? 'Pencatatan Sederhana' }}</li>
        <li class="breadcrumb-item active">{{ $mainTitle ?? 'Simpan Jurnal Bulanan' }}</li>
    </ul>

    <div class="alert alert-info py-2">
        Simpan jurnal per bulan mengunci <strong>tahun + bulan</strong> di pencatatan sederhana.
        Setelah disimpan, Kas Masuk/Kas Keluar periode itu tidak bisa ditambah atau dihapus. Cek/Rekap tetap bisa dibaca.
    </div>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Simpan / Buka Periode</h5></div>
                <div class="card-body">
                    <form id="form-tutup-buku">
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
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="button" class="btn btn-primary" id="btn-tutup">
                                <i class="ri-save-line me-1"></i> Simpan Jurnal Bulan Ini
                            </button>
                            <button type="button" class="btn btn-outline-warning" id="btn-buka">
                                <i class="ri-lock-unlock-line me-1"></i> Buka Kembali
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Periode Sudah Disimpan</h5></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0" id="table-locked">
                            <thead class="table-light">
                            <tr>
                                <th>Periode</th>
                                <th>Disimpan</th>
                                <th>Oleh</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse(($lockedPeriods ?? []) as $row)
                                <tr>
                                    <td>{{ $row->label }}</td>
                                    <td>{{ $row->locked_at ?? '-' }}</td>
                                    <td>{{ $row->locked_by ?? '-' }}</td>
                                    <td class="text-center">
                                        <button type="button"
                                                class="btn btn-sm btn-outline-warning btn-buka-row"
                                                data-tahun="{{ $row->tahun }}"
                                                data-periode="{{ $row->periode }}">
                                            Buka
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr class="js-empty-row">
                                    <td colspan="4" class="text-center text-muted py-4">Belum ada periode yang disimpan.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
    <script>
        const tutupUrl = @json($tutupUrl);
        const bukaUrl = @json($bukaUrl);

        function payloadFromForm() {
            return {
                tahun: document.getElementById('tahun').value,
                periode: document.getElementById('periode').value,
            };
        }

        function renderLockedRows(rows) {
            const tbody = document.querySelector('#table-locked tbody');
            if (!tbody) return;
            if (!Array.isArray(rows) || rows.length === 0) {
                tbody.innerHTML = '<tr class="js-empty-row"><td colspan="4" class="text-center text-muted py-4">Belum ada periode yang disimpan.</td></tr>';
                return;
            }
            tbody.innerHTML = rows.map(row => `
                <tr>
                    <td>${row.label || '-'}</td>
                    <td>${row.locked_at || '-'}</td>
                    <td>${row.locked_by || '-'}</td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-warning btn-buka-row"
                            data-tahun="${row.tahun}" data-periode="${row.periode}">Buka</button>
                    </td>
                </tr>
            `).join('');
        }

        async function postLock(url, body, confirmTitle) {
            const confirm = await Swal.fire({
                icon: 'warning',
                title: confirmTitle,
                showCancelButton: true,
                confirmButtonText: 'Lanjutkan',
                cancelButtonText: 'Batal',
            });
            if (!confirm.isConfirmed) return;

            loadingAlert();
            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            const fd = new FormData();
            fd.append('_token', csrf);
            fd.append('tahun', body.tahun);
            fd.append('periode', body.periode);

            fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: fd,
            })
                .then(r => r.json().then(payload => ({ ok: r.ok, payload })).catch(() => ({ ok: false, payload: {} })))
                .then(({ ok, payload }) => {
                    if (!ok) throw payload;
                    successAlert(payload.message || 'Berhasil');
                    if (payload.lockedPeriods) {
                        renderLockedRows(payload.lockedPeriods);
                    }
                })
                .catch(err => errorAlert(err.message || err.error || 'Gagal memproses'));
        }

        document.getElementById('btn-tutup')?.addEventListener('click', function () {
            const body = payloadFromForm();
            postLock(tutupUrl, body, 'Simpan jurnal periode ini?');
        });

        document.getElementById('btn-buka')?.addEventListener('click', function () {
            const body = payloadFromForm();
            postLock(bukaUrl, body, 'Buka kembali periode ini?');
        });

        document.querySelector('#table-locked')?.addEventListener('click', function (e) {
            const btn = e.target.closest('.btn-buka-row');
            if (!btn) return;
            postLock(bukaUrl, {
                tahun: btn.dataset.tahun,
                periode: btn.dataset.periode,
            }, 'Buka kembali periode ini?');
        });
    </script>
@endsection
