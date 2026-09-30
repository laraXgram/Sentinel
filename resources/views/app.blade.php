<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ rescue(fn () => app('session')->token(), '', false) }}">
    <meta name="robots" content="noindex, nofollow">
    <title>Sentinel · {{ config('app.name', 'LaraGram') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ $base }}/assets/img/favicon.svg?v={{ $version }}">
    <link rel="stylesheet" href="{{ $base }}/assets/css/sentinel.css?v={{ $version }}">
    <script>
        (function () {
            try {
                var theme = localStorage.getItem('sentinel.theme');
                if (theme === 'dark' || (! theme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark');
                }
                if (localStorage.getItem('sentinel.sidebar') === 'collapsed') {
                    document.documentElement.classList.add('sidebar-collapsed');
                }
            } catch (e) {}
        })();
    </script>
</head>
<body>
    <div id="sentinel">
        <div class="boot">
            <img class="boot-logo" src="{{ $base }}/assets/img/favicon.svg?v={{ $version }}" alt="">
        </div>
    </div>

    <script>
        window.Sentinel = {!! json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) !!};
        window.Sentinel.assets = @json($base.'/assets');
        window.Sentinel.assetsVersion = @json($version);
    </script>
    <script type="module" src="{{ $base }}/assets/js/app.js?v={{ $version }}"></script>
</body>
</html>
