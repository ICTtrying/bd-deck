type ThemePreference = 'system' | 'light' | 'dark';

interface AlpineMagicContext {
    $el: HTMLElement;
}

interface AlpineInstance {
    magic(name: string, callback: (el: HTMLElement) => unknown): void;
    data(name: string, callback: (...args: never[]) => Record<string, unknown>): void;
}

declare global {
    interface Window {
        Alpine?: AlpineInstance;
        bdDeck: {
            applyTheme: (preference?: ThemePreference) => void;
            copy: (text: string) => Promise<boolean>;
        };
    }
}

const darkQuery = window.matchMedia('(prefers-color-scheme: dark)');

function currentPreference(): ThemePreference {
    const value = document.documentElement.dataset.theme;

    return value === 'light' || value === 'dark' ? value : 'system';
}

function applyTheme(preference: ThemePreference = currentPreference()): void {
    document.documentElement.dataset.theme = preference;
    const dark = preference === 'dark' || (preference === 'system' && darkQuery.matches);
    document.documentElement.classList.toggle('dark', dark);
}

async function copy(text: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(text);

        return true;
    } catch {
        // oudere Electron-builds zonder clipboard-permissie
        const area = document.createElement('textarea');
        area.value = text;
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.append(area);
        area.select();
        const copied = document.execCommand('copy');
        area.remove();

        return copied;
    }
}

window.bdDeck = { applyTheme, copy };

darkQuery.addEventListener('change', () => applyTheme());
applyTheme();

// na wire:navigate krijgt <html> de server-instelling opnieuw mee
document.addEventListener('livewire:navigated', () => applyTheme());

document.addEventListener('alpine:init', () => {
    window.Alpine?.data('copyButton', (text: string) => ({
        copied: false,
        async copy(): Promise<void> {
            this.copied = await copy(text);
            window.setTimeout(() => (this.copied = false), 1600);
        },
    }));
});

// Ctrl/Cmd+K opent de snelzoeker, ook als de focus in een veld staat
document.addEventListener('keydown', (event: KeyboardEvent) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        window.dispatchEvent(new CustomEvent('open-palette'));
    }
});

/**
 * Vergrendelt de app na een periode zonder muis of toetsenbord.
 * Livewire-polling telt bewust niet mee: een lopende sync is geen aanwezigheid.
 */
function startIdleLock(): void {
    const minutes = Number(document.querySelector<HTMLMetaElement>('meta[name="auto-lock-minutes"]')?.content ?? 0);

    if (!minutes) {
        return;
    }

    let last = Date.now();
    const touch = (): void => {
        last = Date.now();
    };

    ['mousemove', 'keydown', 'mousedown', 'wheel', 'touchstart'].forEach((name) => window.addEventListener(name, touch, { passive: true }));

    window.setInterval(() => {
        if (Date.now() - last > minutes * 60_000) {
            document.querySelector<HTMLFormElement>('#lock-form')?.submit();
        }
    }, 15_000);
}

startIdleLock();

export {};
