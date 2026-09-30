@extends('roadmap.seo.layout')

@section('content')
    <p>{{ $page['description'] }}</p>
    <ul>
@forelse ($page['entries'] as $entry)
        <li>
            <a href="{{ ($page['entryUrl'])($entry) }}">{{ $entry->title }}</a>
            <span class="meta">{{ ucfirst($entry->label) }} - <time datetime="{{ $entry->published_at->toIso8601ZuluString() }}">{{ $entry->published_at->format('F j, Y') }}</time></span>
@if ($entry->summary)
            <div>{{ $entry->summary }}</div>
@endif
        </li>
@empty
        <li>No updates yet.</li>
@endforelse
    </ul>
@if (! empty($page['feedUrl']))
    <p><a href="{{ $page['feedUrl'] }}">RSS feed</a></p>
@endif
@endsection
