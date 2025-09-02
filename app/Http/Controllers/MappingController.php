<?php

namespace App\Http\Controllers;

use App\Models\Mapping;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MappingController extends Controller
{
    public function index(Request $request)
    {
        $q = $request->query('q');

        $data = Mapping::when($q, function ($query) use ($q) {
            $query->where('IV_WERKS', 'like', "%$q%")
                ->orWhere('IV_AUART', 'like', "%$q%")
                ->orWhere('Deskription', 'like', "%$q%");
        })
            ->orderBy('Id', 'asc')
            ->paginate(10)
            ->withQueryString();

        return view('mapping.index', compact('data', 'q'));
    }

    public function create()
    {
        return view('mapping.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'IV_WERKS'     => ['required', 'max:10'],
            'IV_AUART'     => [
                'required',
                'max:10',
                Rule::unique('maping')->where(
                    fn($q) =>
                    $q->where('IV_WERKS', $request->IV_WERKS)
                )
            ],
            'Deskription'  => ['required', 'max:255'],
        ], [
            'IV_AUART.unique' => 'Kombinasi IV_WERKS + IV_AUART sudah ada.'
        ]);

        Mapping::create($validated);
        return redirect()->route('mapping.index')->with('ok', 'Data berhasil ditambahkan.');
    }
    
    public function edit(\App\Models\Mapping $mapping)
    {
        return view('mapping.edit-mapping', compact('mapping'));
    }



    public function update(Request $request, Mapping $mapping)
    {
        $validated = $request->validate([
            'IV_WERKS'     => ['required', 'max:10'],
            'IV_AUART'     => [
                'required',
                'max:10',
                Rule::unique('maping')->ignore($mapping->Id, 'Id')
                    ->where(fn($q) => $q->where('IV_WERKS', $request->IV_WERKS))
            ],
            'Deskription'  => ['required', 'max:255'],
        ], [
            'IV_AUART.unique' => 'Kombinasi IV_WERKS + IV_AUART sudah ada.'
        ]);

        $mapping->update($validated);
        return redirect()->route('mapping.index')->with('ok', 'Data berhasil diubah.');
    }

    public function destroy(Mapping $mapping)
    {
        $mapping->delete();
        return redirect()->route('mapping.index')->with('ok', 'Data berhasil dihapus.');
    }
}
