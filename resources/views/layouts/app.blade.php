<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $theme }}" class="h-full">
    <head>
        @include('layouts.head')
    </head>
    <body class="h-full overflow-hidden">
        <div class="flex h-full">
            <livewire:sidebar />

            <main class="min-w-0 flex-1 overflow-y-auto" id="main">
                <div class="mx-auto w-full max-w-[72rem] px-6 py-7 lg:px-10">
                    {{ $slot }}
                </div>
            </main>
        </div>

        <livewire:command-palette />
        <x-toasts />

        <form id="lock-form" method="POST" action="{{ route('lock') }}" class="hidden">@csrf</form>
    </body>
</html>
