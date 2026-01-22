@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Create Work Order</h1>
    <form method="POST" action="{{ route('nsmanufacturing.work_orders.store') }}">
        @csrf
        <div class="form-group">
            <label>Reference</label>
            <input type="text" name="reference" class="form-control" required>
        </div>
        <div class="form-group">
            <label>BOM ID (optional)</label>
            <input type="text" name="bom_id" class="form-control">
        </div>
        <div class="form-group">
            <label>Product (product_id)</label>
            <input type="text" name="product_id" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Quantity</label>
            <input type="number" step="0.0001" name="quantity" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Warehouse ID (optional)</label>
            <input type="text" name="warehouse_id" class="form-control">
        </div>
        <button class="btn btn-primary">Create</button>
    </form>
</div>
@endsection