@props(['line'])

@php
    $kind = match (mb_substr(ltrim($line), 0, 1)) {
        '→' => 'step',
        '✓' => 'ok',
        '✗' => 'error',
        '!' => 'warn',
        '·', '=' => 'note',
        default => 'plain',
    };
@endphp
<span class="log-line block whitespace-pre-wrap break-words" data-kind="{{ $kind }}">{{ $line }}</span>
