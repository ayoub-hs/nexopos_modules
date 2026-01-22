@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Create BOM</h1>
    <form method="POST" action="{{ route('nsmanufacturing.boms.store') }}">
        @csrf
        <div class="form-group">
            <label>Finished Product (product_id)</label>
            <input type="text" name="product_id" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Name</label>
            <input type="text" name="name" class="form-control">
        </div>
        <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" class="form-control"></textarea>
        </div>
        <button class="btn btn-primary">Save</button>
    </form>
</div>
@endsection