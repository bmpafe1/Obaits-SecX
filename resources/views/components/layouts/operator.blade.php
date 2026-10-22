<!DOCTYPE html>
<html lang="{{ str_replace('_','-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Obaits — Operator</title>
    @livewireStyles
</head>
<body style="margin:0; font-family:system-ui,sans-serif; background:#f4f7fb;">
    {{ $slot }}
    @livewireScripts
</body>
</html>
