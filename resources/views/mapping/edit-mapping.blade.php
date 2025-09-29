@extends('layouts.app')

@section('content')
    <div class="container" style="max-width: 720px;">
        <h4 class="mb-3">Edit Mapping #{{ $mapping->id }}</h4>

        {{-- boleh pakai model langsung --}}
        <form method="post" action="{{ route('mapping.update', $mapping) }}">
            @csrf
            @method('PUT')

            <div class="mb-3">
                <label class="form-label">IV_WERKS</label>
                <input type="text" name="IV_WERKS" value="{{ old('IV_WERKS', $mapping->IV_WERKS) }}" class="form-control"
                    required>
                @error('IV_WERKS')
                    <div class="text-danger small">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label class="form-label">IV_AUART</label>
                <input type="text" name="IV_AUART" value="{{ old('IV_AUART', $mapping->IV_AUART) }}" class="form-control"
                    required>
                @error('IV_AUART')
                    <div class="text-danger small">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Deskription</label>
                <input type="text" name="Deskription" value="{{ old('Deskription', $mapping->Deskription) }}"
                    class="form-control" required>
                @error('Deskription')
                    <div class="text-danger small">{{ $message }}</div>
                @enderror
            </div>

            <a href="{{ route('mapping.index') }}" class="btn btn-light">Batal</a>
            <button type="submit" class="btn btn-primary">Update</button>
        </form>
    </div>
@endsection
