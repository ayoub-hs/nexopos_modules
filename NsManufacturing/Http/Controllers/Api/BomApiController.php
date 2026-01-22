<?php

namespace Modules\NsManufacturing\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\NsManufacturing\Models\Bom;

class BomApiController extends Controller
{
    public function index()
    {
        return response()->json(Bom::with('lines')->paginate(25));
    }

    public function show($id)
    {
        return response()->json(Bom::with('lines')->findOrFail($id));
    }
}