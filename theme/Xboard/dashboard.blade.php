<!doctype html>
<html lang="zh-CN">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no" />
  <title>{{$title}}</title>
  <script type="module" crossorigin src="/theme/{{$theme}}/assets/umi.js"></script>
  <style>
    html,
    body,
    #app {
      min-height: 100%;
      background: linear-gradient(180deg, #eef8ff 0%, #f6fbff 45%, #ffffff 100%);
    }

    body {
      margin: 0;
    }

    #app :where(.n-layout, .ant-layout, .ant-layout-content) {
      background-color: transparent;
    }
  </style>
</head>

<body>

  @php
    $themeColor = $theme_config['theme_color'] ?? 'blue';
    $themeColor = $themeColor === 'default' ? 'blue' : $themeColor;
  @endphp
  <script>
    window.routerBase = "/";
    window.settings = {
      title: '{{$title}}',
      assets_path: '/theme/{{$theme}}/assets',
      theme: {
        color: '{{ $themeColor }}',
      },
      version: '{{$version}}',
      background_url: '{{$theme_config['background_url']}}',
      description: '{{$description}}',
      i18n: [
        'zh-CN',
        'en-US',
        'ja-JP',
        'vi-VN',
        'ko-KR',
        'zh-TW',
        'fa-IR'
      ],
      logo: '{{$logo}}'
    }
  </script>
  <div id="app"></div>
  {!! $theme_config['custom_html'] !!}
</body>

</html>
