<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>@yield('title') - {{ option_localized('site_name') }}</title>
    @include('common.favicon')
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('common.theme-color')
    @include('common.seo-meta-tags')
    <!-- App Styles -->
    @include('common.dependencies.style')
</head>

<body class="hold-transition login-page">

    @yield('content')

    <!-- YOU CAN NOT MODIFIY THE COPYRIGHT TEXT W/O PERMISSION -->
    <div id="copyright-text" class="hide">
        @include('common.copyright')
    </div>

    <!-- App Scripts -->
    @include('common.dependencies.script')

    @yield('script')
</body>
</html>
