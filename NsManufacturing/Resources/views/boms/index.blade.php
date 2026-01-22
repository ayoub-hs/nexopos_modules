@extends('layouts.app')

@section('content')
<div class="container">
    <h1>BOMs</h1>
    <a href="{{ route('nsmanufacturing.boms.create') }}" class="btn btn-primary">Create BOM</a>
    <table class="table mt-3">
        <thead>
            <tr><th>ID</th><th>Name</th><th>Product</th><th>Actions</th></tr>
        </thead>
        <tbody>
            @foreach($boms as $bom)
                <tr>
                    <td>{{ $bom->id }}</td>
                    <td>{{ $bom->name }}</td>
                    <td>{{ optional($bom->product)->name }}</td>
                    <td>
                        <a href="{{ route('nsmanufacturing.boms.show', $bom->id) }}" class="btn btn-sm btn-info">View</a>
                        <a href="{{ route('nsmanufacturing.boms.edit', $bom->id) }}" class="btn btn-sm btn-secondary">Edit</a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    {{ $boms->links() }}
</div>
@endsection