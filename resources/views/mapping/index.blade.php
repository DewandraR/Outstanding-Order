@extends('layouts.app')

@section('content')
    <div class="container">
        {{-- Header judul & tombol tambah --}}
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">Daftar Mapping</h4>
            <a href="{{ route('mapping.create') }}" class="btn btn-primary">+ Tambah</a>
        </div>

        {{-- Flash message sukses --}}
        @if (session('ok'))
            <div class="alert alert-success">{{ session('ok') }}</div>
        @endif

        {{-- Form pencarian --}}
        <form method="get" class="row g-2 mb-3">
            <div class="col-md-4">
                <input type="text" name="q" value="{{ $q ?? '' }}" class="form-control"
                    placeholder="Cari WERKS/AUART/Deskripsi">
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-secondary w-100">Cari</button>
            </div>
        </form>

        {{-- Tabel data --}}
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width: 80px;">Id</th>
                        <th>IV_WERKS</th>
                        <th>IV_AUART</th>
                        <th>Deskription</th>
                        <th style="width: 160px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($data as $row)
                        <tr>
                            <td>{{ $row->id }}</td>
                            <td>{{ $row->IV_WERKS }}</td>
                            <td>{{ $row->IV_AUART }}</td>
                            <td>{{ $row->Deskription }}</td>
                            <td>
                                <a href="{{ route('mapping.edit', $row->id) }}" class="btn btn-sm btn-primary">Edit</a>
                                <form action="{{ route('mapping.destroy', $row->id) }}" method="POST" class="d-inline"
                                    onsubmit="return confirm('Yakin hapus data ini?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-danger">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted">Belum ada data</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div class="d-flex justify-content-between align-items-center mt-3">
            {{-- Pagination bawaan --}}
            <div>
                {{-- Ini akan otomatis menyertakan parameter query 'q' jika ada --}}
                {{ $data->links('pagination::bootstrap-5') }}
            </div>

            {{-- Input lompat halaman --}}
            <form method="GET" action="{{ url()->current() }}" class="d-flex align-items-center ms-3">
                {{-- [PERBAIKAN] Tambahkan input hidden untuk membawa query pencarian saat ganti halaman --}}
                @if (request('q'))
                    <input type="hidden" name="q" value="{{ request('q') }}">
                @endif
                <label for="page" class="me-2">Go to page:</label>
                <input type="number" name="page" id="page" min="1" max="{{ $data->lastPage() }}"
                    class="form-control form-control-sm" style="width: 80px" value="{{ request('page', 1) }}">
                <button type="submit" class="btn btn-sm btn-success ms-2">Go</button>
            </form>
        </div>

    </div>
@endsection
