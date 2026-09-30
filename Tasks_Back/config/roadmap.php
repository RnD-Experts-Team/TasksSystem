<?php

/**
 * Public Roadmap & Feedback module configuration.
 *
 * `defaults` is the source of truth for settings a board admin has not customised
 * (SettingsService deep-merges the stored JSON over these values).
 */
return [

    /*
    | Secret used to derive the monthly-rotating HMAC key for visitor IP hashes and to sign
    | form tokens. REQUIRED outside the testing environment (64 hex chars recommended).
    */
    'hash_key' => env('ROADMAP_HASH_KEY'),

    /* Public SPA origin: used for the links inside the changelog RSS feed and to recognise internal links in markdown. */
    'frontend_url' => env('ROADMAP_FRONTEND_URL', 'https://tasks.rdexperts.tech'),

    /*
    | Proxies whose forwarded-for header is trusted. Defaults to the private ranges (a local
    | reverse proxy). Comma separated list in env. Production has NO proxy hop today, so the
    | real client IP is REMOTE_ADDR.
    */
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'ROADMAP_TRUSTED_PROXIES',
        '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.0/8,::1/128,fc00::/7'
    ))))),

    'ip_header' => env('ROADMAP_IP_HEADER', 'X-Forwarded-For'),

    /* IP / UA hashes older than this many days are nulled by `roadmap:prune`. */
    'ip_retention_days' => (int) env('ROADMAP_IP_RETENTION_DAYS', 90),

    /* `like` (default, works everywhere). Reserved: `fulltext` (MySQL only). */
    'search_driver' => env('ROADMAP_SEARCH_DRIVER', 'like'),

    /* Signed form tokens stop being valid after this many seconds. */
    'form_token_max_age' => 21600,

    /* Settings defaults (public + private), merged under whatever is stored in roadmap_settings. */
    'defaults' => [
        'site' => [
            'name' => 'PNE Roadmap',
            'tagline' => 'Tell us what to build next',
            'hero_title' => 'What should we build next?',
            'hero_subtitle' => 'Vote on ideas, follow progress, and see what just shipped.',
            'team_name' => 'PNE Team',
            'footer_text' => null,
            'footer_links' => [],
            'contact_url' => null,
            'default_board_slug' => null,
        ],
        'branding' => [
            'primary' => '#e11d48',
            'radius' => 'lg',
            'font' => 'outfit',
            'default_theme' => 'system',
            'hero_style' => 'gradient',
            'logo' => null,
            'logo_dark' => null,
            'favicon' => null,
        ],
        'features' => [
            'roadmap' => true,
            'changelog' => true,
            'comments' => true,
            'show_vote_counts' => true,
            'rss' => true,
        ],
        'moderation' => [
            'blocklist' => [],
            'max_links_post' => 2,
            'max_links_comment' => 1,
            'min_post_seconds' => 5,
            'min_comment_seconds' => 3,
        ],
        'limits' => [
            'votes_per_visitor_day' => 30,
            'votes_per_ip_day' => 120,
            'new_visitor_votes_day' => 10,
            'posts_per_visitor_day' => 5,
            'posts_per_ip_day' => 15,
            'comments_per_visitor_hour' => 10,
            'tokens_per_ip_day' => 20,
        ],
    ],

    /* Values echoed to clients as `limits_hint` (mirror of the server validation rules). */
    'limits_hint' => [
        'title_min' => 8,
        'title_max' => 140,
        'body_max' => 5000,
        'comment_min' => 2,
        'comment_max' => 2000,
        'author_name_max' => 40,
        'max_tags' => 3,
    ],
];
