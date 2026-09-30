@extends('roadmap.seo.layout')

@section('content')
@if ($page['board']->description)
    <p>{{ $page['board']->description }}</p>
@endif
    <p><a href="{{ $page['canonical'] }}/roadmap">See the roadmap</a></p>
    <h2>Top ideas</h2>
    <ul>
@forelse ($page['posts'] as $post)
        <li>
            <a href="{{ ($page['postUrl'])($post) }}">{{ $post->title }}</a>
            <span class="meta">{{ $post->votes_count }} votes - {{ $post->status?->name }}</span>
        </li>
@empty
        <li>No ideas yet. Be the first to suggest one.</li>
@endforelse
    </ul>
@endsection
