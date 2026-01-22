@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Work Order #{{ $wo->id }} ({{ $wo->reference }})</h1>
    <p><strong>Product:</strong> {{ optional($wo->product)->name }}</p>
    <p><strong>Quantity:</strong> {{ $wo->quantity }}</p>
    <p><strong>Status:</strong> {{ $wo->status }}</p>

    @if($wo->status === 'planned')
        <form method="POST" action="{{ route('nsmanufacturing.work_orders.start', $wo->id) }}">
            @csrf
            <button class="btn btn-success">Start</button>
        </form>
    @endif

    @if($wo->status === 'started')
        <form method="POST" action="{{ route('nsmanufacturing.work_orders.complete', $wo->id) }}">
            @csrf
            <button class="btn btn-primary">Complete</button>
        </form>
    @endif

    <h3>Lines</h3>
    <ul>
        @foreach($wo->lines as $line)
            <li>{{ optional($line->product)->name }} — {{ $line->quantity }} ({{ $line->status }})</li>
        @endforeach
    </ul>
</div>
@endsection