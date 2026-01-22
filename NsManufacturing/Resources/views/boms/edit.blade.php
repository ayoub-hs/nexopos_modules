@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Edit BOM</h1>
    <form method="POST" action="{{ route('nsmanufacturing.boms.update', $bom->id) }}">
        @csrf
        @method('PUT')
        <div class="form-group">
            <label>Finished Product (product_id)</label>
            <input type="text" name="product_id" value="{{ $bom->product_id }}" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Name</label>
            <input type="text" name="name" value="{{ $bom->name }}" class="form-control">
        </div>
        <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" class="form-control">{{ $bom->notes }}</textarea>
        </div>
        <button class="btn btn-primary">Update</button>
    </form>
</div>
@endsection