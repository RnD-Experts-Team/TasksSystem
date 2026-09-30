@extends('roadmap.seo.layout')

@section('content')
    <p class="meta">
        {{ ucfirst($page['entry']->label) }} -
        <time datetime="{{ $page['entry']->published_at->toIso8601ZuluString() }}">{{ $page['entry']->published_at->format('F j, Y') }}</time>
@if ($page['entry']->board)
        - {{ $page['entry']->board->name }}
@endif
    </p>
@if ($page['entry']->summary)
    <p>{{ $page['entry']->summary }}</p>
@endif
    {{-- Server-sanitised markdown (MarkdownRenderer): raw HTML stripped, unsafe links removed. --}}
    <div>{!! $page['entry']->body_html !!}</div>
@endsection
