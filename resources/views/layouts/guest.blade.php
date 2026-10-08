<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $theme }}" class="h-full">
    <head>
        @include('layouts.head')
    </head>
    <body class="grid h-full place-items-center px-4">
        <main class="w-full max-w-sm">
            {{ $slot }}
        </main>
        <x-toasts />
    </body>
</html>
