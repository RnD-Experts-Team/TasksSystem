@extends('roadmap.seo.layout')

@section('content')
    <p>{{ $page['description'] }}</p>
    <p><a href="{{ $page['canonical'] }}">Browse the roadmap</a></p>
@endsection
