@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Work Orders</h1>
    <a href="{{ route('nsmanufacturing.work_orders.create') }}" class="btn btn-primary">Create Work Order</a>
    <table class="table mt-3">
        <thead><tr><th>ID</th><th>Reference</th><th>Product</th><th>Qty</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
            @foreach($workOrders as $wo)
                <tr>
                    <td>{{ $wo->id }}</td>
                    <td>{{ $wo->reference }}</td>
                    <td>{{ optional($wo->product)->name }}</td>
                    <td>{{ $wo->quantity }}</td>
                    <td>{{ $wo->status }}</td>
                    <td>
                        <a href="{{ route('nsmanufacturing.work_orders.show', $wo->id) }}" class="btn btn-sm btn-info">View</a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    {{ $workOrders->links() }}
</div>
@endsection