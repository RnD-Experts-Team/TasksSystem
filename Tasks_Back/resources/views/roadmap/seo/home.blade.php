@extends('roadmap.seo.layout')

@section('content')
    <p>{{ $page['description'] }}</p>
    <h2>Boards</h2>
    <ul>
@forelse ($page['boards'] as $board)
        <li>
            <a href="{{ $page['canonical'] }}/{{ $board->slug }}">{{ $board->name }}</a>
            <span class="meta">({{ $board->posts_count }} ideas)</span>
@if ($board->description)
            <div class="meta">{{ $board->description }}</div>
@endif
        </li>
@empty
        <li>No boards yet.</li>
@endforelse
    </ul>
    <p><a href="{{ $page['changelogUrl'] }}">Changelog</a></p>
@endsection
