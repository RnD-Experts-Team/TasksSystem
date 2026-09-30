@extends('roadmap.seo.layout')

@section('content')
@foreach ($page['columns'] as $column)
    <section>
        <h2>{{ $column['status']->name }}</h2>
        <ul>
@forelse ($column['posts'] as $post)
            <li>
                <a href="{{ ($page['postUrl'])($post) }}">{{ $post->title }}</a>
                <span class="meta">{{ $post->votes_count }} votes</span>
            </li>
@empty
            <li>Nothing here yet.</li>
@endforelse
        </ul>
    </section>
@endforeach
@endsection
