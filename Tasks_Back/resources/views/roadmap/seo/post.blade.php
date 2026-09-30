@extends('roadmap.seo.layout')

@section('content')
    <p class="meta">
        {{ $page['post']->status?->name }} - {{ $page['post']->votes_count }} votes - {{ $page['post']->comments_count }} comments
        - by {{ $page['post']->author_name ?: 'Anonymous' }}
@if ($page['post']->published_at)
        - <time datetime="{{ $page['post']->published_at->toIso8601ZuluString() }}">{{ $page['post']->published_at->format('F j, Y') }}</time>
@endif
    </p>
@if ($page['post']->body)
    <div>{!! nl2br(e($page['post']->body)) !!}</div>
@endif
@if ($page['post']->response_html)
    <blockquote>
        <strong>{{ $page['teamName'] }}</strong>
        {{-- Server-sanitised markdown (MarkdownRenderer): raw HTML stripped, unsafe links removed. --}}
        {!! $page['post']->response_html !!}
    </blockquote>
@endif
@if ($page['post']->tags->isNotEmpty())
    <p class="meta">Tags: {{ $page['post']->tags->pluck('name')->implode(', ') }}</p>
@endif
@if ($page['comments']->isNotEmpty())
    <h2>Comments</h2>
    <ul>
@foreach ($page['comments'] as $comment)
        <li>
            <strong>{{ $comment->is_admin ? $page['teamName'] : ($comment->author_name ?: 'Anonymous') }}</strong>
            <div>{!! nl2br(e($comment->body)) !!}</div>
        </li>
@endforeach
    </ul>
@endif
@endsection
