<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $page->seo_title ?: $page->title }}</title>
    <meta name="description" content="{{ $page->seo_description ?: $page->description }}">

    {{-- Open Graph --}}
    <meta property="og:title" content="{{ $page->seo_title ?: $page->title }}">
    <meta property="og:description" content="{{ $page->seo_description ?: $page->description }}">
    @if($page->seo_image)
        <meta property="og:image" content="{{ asset('storage/' . $page->seo_image) }}">
    @elseif($page->avatar_path)
        <meta property="og:image" content="{{ asset('storage/' . $page->avatar_path) }}">
    @endif
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $page->public_url }}">

    {{-- Favicon --}}
    <link rel="icon" href="{{ $page->avatar_path ? asset('storage/' . $page->avatar_path) : '/favicon.ico' }}">

    {{-- Google Fonts --}}
    @php $font = $theme['font_family'] ?? 'Inter'; @endphp
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family={{ urlencode($font) }}:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            min-height: 100vh;
            font-family: '{{ $font }}', sans-serif;
            color: {{ $theme['text_color'] ?? '#ffffff' }};
            background-color: {{ $theme['bg_color'] ?? '#0f172a' }};
            @if(!empty($theme['bg_gradient']))
                background-image: {{ $theme['bg_gradient'] }};
            @endif
            @if(!empty($theme['bg_image']))
                background-image: url('{{ $theme['bg_image'] }}');
                background-size: cover;
                background-position: center;
            @endif
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .container {
            width: 100%;
            max-width: 480px;
            text-align: {{ ($theme['layout'] ?? 'center') === 'left' ? 'left' : 'center' }};
        }

        .avatar {
            width: 96px;
            height: 96px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid {{ $theme['button_color'] ?? '#4f46e5' }};
            margin: 0 auto 1rem;
            display: block;
        }

        .block { margin-bottom: 0.75rem; }

        .link-btn {
            display: block;
            width: 100%;
            padding: 14px 20px;
            font-size: 0.95rem;
            font-weight: 500;
            text-decoration: none;
            text-align: center;
            transition: transform 0.15s, opacity 0.15s;
            cursor: pointer;
            background-color: {{ $theme['button_color'] ?? '#4f46e5' }};
            color: {{ $theme['button_text_color'] ?? '#ffffff' }};
            border: 2px solid {{ $theme['button_color'] ?? '#4f46e5' }};
            @php
                $btnStyle = $theme['button_style'] ?? 'rounded';
            @endphp
            @if($btnStyle === 'rounded')
                border-radius: 12px;
            @elseif($btnStyle === 'pill')
                border-radius: 50px;
            @elseif($btnStyle === 'square')
                border-radius: 0;
            @elseif($btnStyle === 'outline')
                background-color: transparent;
                border-radius: 12px;
            @elseif($btnStyle === 'shadow')
                border-radius: 12px;
                box-shadow: 0 4px 14px rgba(0,0,0,0.3);
            @elseif($btnStyle === 'gradient')
                border-radius: 12px;
                background: linear-gradient(135deg, {{ $theme['button_color'] ?? '#4f46e5' }}, {{ $theme['button_color'] ?? '#7c3aed' }}ee);
                border-color: transparent;
            @endif
        }

        .link-btn:hover {
            transform: scale(1.02);
            opacity: 0.9;
        }

        .link-btn.highlight {
            transform: scale(1.03);
            box-shadow: 0 0 20px {{ $theme['button_color'] ?? '#4f46e5' }}44;
        }

        .header-title { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.25rem; }
        .header-subtitle { font-size: 0.9rem; opacity: 0.7; }

        .text-block { font-size: 0.9rem; opacity: 0.8; line-height: 1.6; padding: 0.5rem 0; }

        .divider-block { border: none; border-top: 1px solid rgba(255,255,255,0.15); margin: 1rem 0; }

        .social-icons { display: flex; justify-content: center; gap: 0.75rem; padding: 0.5rem 0; flex-wrap: wrap; }
        .social-icon {
            width: 44px; height: 44px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            background: {{ $theme['button_color'] ?? '#4f46e5' }}33;
            color: {{ $theme['text_color'] ?? '#fff' }};
            text-decoration: none; font-size: 1.1rem; font-weight: 600;
            transition: transform 0.15s, background 0.15s;
        }
        .social-icon:hover { transform: scale(1.1); background: {{ $theme['button_color'] ?? '#4f46e5' }}66; }

        .image-block img { width: 100%; border-radius: 12px; display: block; }

        .video-block iframe { width: 100%; aspect-ratio: 16/9; border-radius: 12px; border: none; }

        .spotify-block iframe { width: 100%; border-radius: 12px; border: none; }

        .powered-by {
            margin-top: 2rem; font-size: 0.7rem; opacity: 0.3;
            text-align: center;
        }
        .powered-by a { color: inherit; text-decoration: none; }
        .powered-by a:hover { opacity: 0.6; }

        @if($page->custom_css)
            {{ $page->custom_css }}
        @endif
    </style>
</head>
<body>
    <div class="container">
        @foreach($blocks as $index => $block)
            <div class="block" data-block-index="{{ $index }}">
                @switch($block['type'])
                    @case('header')
                        @if(!empty($block['config']['show_avatar']) && $page->avatar_path)
                            <img src="{{ asset('storage/' . $page->avatar_path) }}" alt="{{ $page->title }}" class="avatar">
                        @endif
                        <div class="header-title">{{ $block['config']['title'] ?? $page->title }}</div>
                        @if(!empty($block['config']['subtitle']))
                            <div class="header-subtitle">{{ $block['config']['subtitle'] }}</div>
                        @endif
                        @break

                    @case('link')
                        <a href="{{ $block['config']['url'] ?? '#' }}" target="_blank" rel="noopener"
                           class="link-btn {{ !empty($block['config']['highlight']) ? 'highlight' : '' }}"
                           onclick="trackClick({{ $index }}, '{{ addslashes($block['config']['url'] ?? '') }}')">
                            {{ $block['label'] }}
                        </a>
                        @break

                    @case('text')
                        <div class="text-block">{{ $block['config']['content'] ?? '' }}</div>
                        @break

                    @case('divider')
                        <hr class="divider-block">
                        @break

                    @case('social')
                        @if(!empty($block['config']['networks']))
                            <div class="social-icons">
                                @foreach($block['config']['networks'] as $ni => $net)
                                    @if(!empty($net['url']))
                                        <a href="{{ $net['url'] }}" target="_blank" rel="noopener" class="social-icon"
                                           onclick="trackClick({{ $index }}, '{{ addslashes($net['url']) }}')"
                                           title="{{ ucfirst($net['platform'] ?? '') }}">
                                            {{ strtoupper(substr($net['platform'] ?? '?', 0, 1)) }}
                                        </a>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                        @break

                    @case('image')
                        @if(!empty($block['config']['url']))
                            @if(!empty($block['config']['link']))
                                <a href="{{ $block['config']['link'] }}" target="_blank" class="image-block"
                                   onclick="trackClick({{ $index }}, '{{ addslashes($block['config']['link']) }}')">
                                    <img src="{{ $block['config']['url'] }}" alt="{{ $block['config']['alt'] ?? '' }}">
                                </a>
                            @else
                                <div class="image-block">
                                    <img src="{{ $block['config']['url'] }}" alt="{{ $block['config']['alt'] ?? '' }}">
                                </div>
                            @endif
                        @endif
                        @break

                    @case('video')
                        @if(!empty($block['config']['embed_url']))
                            @php
                                $videoUrl = $block['config']['embed_url'];
                                // Convert YouTube URLs to embed
                                if (str_contains($videoUrl, 'youtube.com/watch')) {
                                    preg_match('/[?&]v=([^&]+)/', $videoUrl, $m);
                                    $videoUrl = 'https://www.youtube.com/embed/' . ($m[1] ?? '');
                                } elseif (str_contains($videoUrl, 'youtu.be/')) {
                                    $videoUrl = 'https://www.youtube.com/embed/' . basename(parse_url($videoUrl, PHP_URL_PATH));
                                }
                            @endphp
                            <div class="video-block">
                                <iframe src="{{ $videoUrl }}" allowfullscreen loading="lazy"></iframe>
                            </div>
                        @endif
                        @break

                    @case('email')
                        <a href="mailto:{{ $block['config']['address'] ?? '' }}{{ !empty($block['config']['subject']) ? '?subject=' . urlencode($block['config']['subject']) : '' }}"
                           class="link-btn" onclick="trackClick({{ $index }}, 'mailto:{{ $block['config']['address'] ?? '' }}')">
                            {{ $block['label'] }}
                        </a>
                        @break

                    @case('phone')
                        <a href="tel:{{ $block['config']['number'] ?? '' }}" class="link-btn"
                           onclick="trackClick({{ $index }}, 'tel:{{ $block['config']['number'] ?? '' }}')">
                            {{ $block['label'] }}
                        </a>
                        @break

                    @case('whatsapp')
                        @php
                            $waUrl = 'https://wa.me/' . preg_replace('/\D/', '', $block['config']['number'] ?? '');
                            if (!empty($block['config']['message'])) $waUrl .= '?text=' . urlencode($block['config']['message']);
                        @endphp
                        <a href="{{ $waUrl }}" target="_blank" class="link-btn"
                           onclick="trackClick({{ $index }}, '{{ addslashes($waUrl) }}')">
                            {{ $block['label'] }}
                        </a>
                        @break

                    @case('spotify')
                        @if(!empty($block['config']['embed_url']))
                            @php
                                $spotifyUrl = $block['config']['embed_url'];
                                if (!str_contains($spotifyUrl, '/embed/')) {
                                    $spotifyUrl = str_replace('open.spotify.com/', 'open.spotify.com/embed/', $spotifyUrl);
                                }
                            @endphp
                            <div class="spotify-block">
                                <iframe src="{{ $spotifyUrl }}" height="152" loading="lazy"></iframe>
                            </div>
                        @endif
                        @break

                    @case('map')
                        @if(!empty($block['config']['embed_url']))
                            <div class="video-block">
                                <iframe src="{{ $block['config']['embed_url'] }}" loading="lazy" style="aspect-ratio: 4/3;"></iframe>
                            </div>
                        @endif
                        @break
                @endswitch
            </div>
        @endforeach

        <div class="powered-by">
            <a href="{{ config('app.url') }}">MKT Privus</a>
        </div>
    </div>

    <script>
        function trackClick(blockIndex, url) {
            try {
                fetch('{{ route('links.public.click', $page->slug) }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: JSON.stringify({ block_index: blockIndex, url: url }),
                });
            } catch (e) {}
        }
    </script>
</body>
</html>
