<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SecX — Secure your business. Pass your audit.</title>
    <meta name="description" content="SecX is the all-in-one security platform for teams without a security expert. Endpoint protection, threat detection, vulnerability management, incident response and compliance in one place.">
    @livewireStyles
</head>
<body style="margin:0; background:#f7f9fc;">
    {{ $slot }}
    @livewireScripts
</body>
</html>
