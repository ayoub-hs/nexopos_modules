@extends('layouts.app')

@section('content')
<div class="container">
    <h1>BOM #{{ $bom->id }}</h1>
    <p><strong>Product:</strong> {{ optional($bom->product)->name }}</p>
    <p><strong>Name:</strong> {{ $bom->name }}</p>
    <p><strong>Notes:</strong> {{ $bom->notes }}</p>
    <h3>Lines</h3>
    <ul>
        @foreach($bom->lines as $line)
            <li>{{ optional($line->product)->name }} — {{ $line->quantity }}</li>
        @endforeach
    </ul>
</div>
@endsection