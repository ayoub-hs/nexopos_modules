<?php

namespace Modules\NsManufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\NsManufacturing\Models\Bom;
use Modules\NsManufacturing\Models\BomLine;
use Illuminate\Http\Request;

class BomController extends Controller
{
    public function index()
    {
        $boms = Bom::with('product')->paginate(25);
        return view('nsmanufacturing::boms.index', compact('boms'));
    }

    public function create()
    {
        return view('nsmanufacturing::boms.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|integer',
            'name' => 'nullable|string',
            'notes' => 'nullable|string',
            'lines' => 'array',
        ]);

        $bom = Bom::create($data);

        if (!empty($data['lines'])) {
            foreach ($data['lines'] as $line) {
                BomLine::create([
                    'bom_id' => $bom->id,
                    'component_product_id' => $line['component_product_id'],
                    'quantity' => $line['quantity'],
                    'unit_id' => $line['unit_id'] ?? null,
                ]);
            }
        }

        return redirect()->route('nsmanufacturing.boms.index')->with('success', __('nsmanufacturing::messages.bom_created'));
    }

    public function edit($id)
    {
        $bom = Bom::with('lines')->findOrFail($id);
        return view('nsmanufacturing::boms.edit', compact('bom'));
    }

    public function update(Request $request, $id)
    {
        $bom = Bom::findOrFail($id);
        $data = $request->validate([
            'product_id' => 'required|integer',
            'name' => 'nullable|string',
            'notes' => 'nullable|string',
            'lines' => 'array',
        ]);

        $bom->update($data);

        // naive approach: delete & recreate lines
        $bom->lines()->delete();
        if (!empty($data['lines'])) {
            foreach ($data['lines'] as $line) {
                BomLine::create([
                    'bom_id' => $bom->id,
                    'component_product_id' => $line['component_product_id'],
                    'quantity' => $line['quantity'],
                    'unit_id' => $line['unit_id'] ?? null,
                ]);
            }
        }

        return redirect()->route('nsmanufacturing.boms.index')->with('success', __('nsmanufacturing::messages.bom_updated'));
    }

    public function show($id)
    {
        $bom = Bom::with('lines.product')->findOrFail($id);
        return view('nsmanufacturing::boms.show', compact('bom'));
    }

    public function destroy($id)
    {
        $bom = Bom::findOrFail($id);
        $bom->delete();
        return redirect()->route('nsmanufacturing.boms.index')->with('success', __('nsmanufacturing::messages.bom_deleted'));
    }
}