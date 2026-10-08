{{-- Meldingen na een actie: via session('toast') of het Livewire-event "toast" --}}
<div
    x-data="{
        toasts: [],
        add(toast) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, ...toast });
            setTimeout(() => this.toasts = this.toasts.filter(t => t.id !== id), toast.tone === 'danger' ? 8000 : 4500);
        },
    }"
    x-init="@if (session('toast')) add(@js(session('toast'))) @endif"
    x-on:toast.window="add($event.detail)"
    class="pointer-events-none fixed right-4 bottom-4 z-[60] grid w-[min(24rem,calc(100vw-2rem))] gap-2"
    aria-live="polite"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            x-transition:enter="transition duration-200 ease-out"
            x-transition:enter-start="opacity-0 translate-y-2"
            class="pointer-events-auto flex items-start gap-3 rounded-xl border border-line bg-surface px-4 py-3 shadow-[0_16px_40px_-16px_rgb(7_19_31/0.4)]"
        >
            <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="{ 'bg-success': toast.tone === 'success', 'bg-danger': toast.tone === 'danger', 'bg-live': !['success','danger'].includes(toast.tone) }"></span>
            <div class="grid gap-0.5">
                <p class="text-sm font-medium" x-text="toast.title"></p>
                <p class="text-[0.8125rem] text-muted" x-show="toast.message" x-text="toast.message"></p>
            </div>
        </div>
    </template>
</div>
