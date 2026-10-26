@extends('errors::minimal')

@section('title', __('Memuat ulang'))
@section('code', '419')
@section('message', __('Memuat ulang halaman'))

@section('script')
    <script>
        (function () {
            const key = 'mit_muhammadiyah_sukarame_419_reloaded';
            if (!sessionStorage.getItem(key)) {
                sessionStorage.setItem(key, '1');
                // Reload halaman yang sama — jangan ke login / jangan alert sesi habis.
                window.location.reload();
                return;
            }
            sessionStorage.removeItem(key);
            window.location.replace(window.location.pathname + window.location.search + window.location.hash);
        })();
    </script>
@endsection
