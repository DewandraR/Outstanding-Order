@extends('layouts.app')

@section('content')
<div class="container" style="max-width: 720px;">
    <h4 class="mb-3">Tambah Mapping</h4>

    <form method="post" action="{{ route('mapping.store') }}">
        @csrf

        <div class="mb-3">
            <label class="form-label">IV_WERKS</label>
            <input type="text" name="IV_WERKS" value="{{ old('IV_WERKS') }}" class="form-control" required>
            @error('IV_WERKS') <div class="text-danger small">{{ $message }}</div> @enderror
        </div>

        <div class="mb-3">
            <label class="form-label">IV_AUART</label>
            <input type="text" name="IV_AUART" value="{{ old('IV_AUART') }}" class="form-control" required>
            @error('IV_AUART') <div class="text-danger small">{{ $message }}</div> @enderror
        </div>

        <div class="mb-3">
            <label class="form-label">Deskription</label>
            <input type="text" name="Deskription" value="{{ old('Deskription') }}" class="form-control" required>
            @error('Deskription') <div class="text-danger small">{{ $message }}</div> @enderror
        </div>

        <a href="{{ route('mapping.index') }}" class="btn btn-light">Batal</a>
        <button type="submit" class="btn btn-primary">Simpan</button>
    </form>
</div>
@endsection
