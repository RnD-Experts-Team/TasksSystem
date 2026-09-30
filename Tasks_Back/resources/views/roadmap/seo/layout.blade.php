<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page['title'] }}</title>
    <meta name="description" content="{{ $page['description'] }}">
    <meta name="robots" content="{{ $page['robots'] }}">
    <meta name="theme-color" content="{{ $page['themeColor'] }}">
    <link rel="canonical" href="{{ $page['canonical'] }}">
@if (! empty($page['faviconUrl']))
    <link rel="icon" href="{{ $page['faviconUrl'] }}">
@endif
@if (! empty($page['feedUrl']))
    <link rel="alternate" type="application/rss+xml" title="{{ $page['siteName'] }} changelog" href="{{ $page['feedUrl'] }}">
@endif

    <meta property="og:site_name" content="{{ $page['siteName'] }}">
    <meta property="og:type" content="{{ $page['type'] }}">
    <meta property="og:title" content="{{ $page['title'] }}">
    <meta property="og:description" content="{{ $page['description'] }}">
    <meta property="og:url" content="{{ $page['canonical'] }}">
    <meta property="og:image" content="{{ $page['ogImage'] }}">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $page['title'] }}">
    <meta name="twitter:description" content="{{ $page['description'] }}">
    <meta name="twitter:image" content="{{ $page['ogImage'] }}">
@foreach ($page['jsonld'] as $node)
    <script type="application/ld+json">{!! json_encode($node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endforeach
    <style>
        body { font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; max-width: 46rem; margin: 0 auto; padding: 1.5rem 1rem; color: #111; }
        a { color: {{ $page['themeColor'] }}; }
        nav ol { list-style: none; display: flex; flex-wrap: wrap; gap: .5rem; padding: 0; margin: 0 0 1rem; font-size: .9rem; }
        nav li + li::before { content: "/"; margin-inline-end: .5rem; color: #888; }
        li { margin: .25rem 0; }
        .meta { color: #555; font-size: .9rem; }
        blockquote { margin: 1rem 0; padding: .5rem 1rem; border-inline-start: 3px solid #ccc; }
    </style>
</head>
<body>
@if (! empty($page['breadcrumbLinks']))
    <nav aria-label="Breadcrumb">
        <ol>
@foreach ($page['breadcrumbLinks'] as [$crumbName, $crumbUrl])
            <li><a href="{{ $crumbUrl }}">{{ $crumbName }}</a></li>
@endforeach
        </ol>
    </nav>
@endif
    <main>
        <h1>{{ $page['heading'] }}</h1>
@yield('content')
    </main>
    <noscript><p class="meta">This page is best viewed with JavaScript enabled.</p></noscript>
    <script>location.replace({!! json_encode($page['spaUrl'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!});</script>
</body>
</html>
