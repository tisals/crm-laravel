<?php

namespace Modules\Administrativo\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AdministrativoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('administrativo::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('administrativo::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request) {}

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('administrativo::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('administrativo::edit');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id) {}

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id) {}
}
