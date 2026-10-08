<?php

namespace App\Enums;

enum TerminalApp: string
{
    case GnomeTerminal = 'gnome-terminal';
    case Konsole = 'konsole';
    case Xfce = 'xfce4-terminal';
    case Tilix = 'tilix';
    case Kitty = 'kitty';
    case Alacritty = 'alacritty';

    public function label(): string
    {
        return match ($this) {
            self::GnomeTerminal => 'GNOME Terminal',
            self::Konsole => 'Konsole',
            self::Xfce => 'Xfce Terminal',
            self::Tilix => 'Tilix',
            self::Kitty => 'kitty',
            self::Alacritty => 'Alacritty',
        };
    }

    /**
     * @return list<string>
     */
    public function openIn(string $directory): array
    {
        return match ($this) {
            self::GnomeTerminal => ['gnome-terminal', "--working-directory={$directory}"],
            self::Konsole => ['konsole', '--workdir', $directory],
            self::Xfce => ['xfce4-terminal', "--working-directory={$directory}"],
            self::Tilix => ['tilix', '-w', $directory],
            self::Kitty => ['kitty', '--directory', $directory],
            self::Alacritty => ['alacritty', '--working-directory', $directory],
        };
    }

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    public function run(string $directory, array $command, string $title): array
    {
        return match ($this) {
            self::GnomeTerminal => ['gnome-terminal', "--working-directory={$directory}", "--title={$title}", '--', ...$command],
            self::Konsole => ['konsole', '--workdir', $directory, '-p', "tabtitle={$title}", '-e', ...$command],
            self::Xfce => ['xfce4-terminal', "--working-directory={$directory}", '-T', $title, '-x', ...$command],
            // tilix verwacht het commando als één string
            self::Tilix => ['tilix', '-w', $directory, '-t', $title, '-e', implode(' ', array_map('escapeshellarg', $command))],
            self::Kitty => ['kitty', '--directory', $directory, '--title', $title, ...$command],
            self::Alacritty => ['alacritty', '--working-directory', $directory, '-T', $title, '-e', ...$command],
        };
    }
}
