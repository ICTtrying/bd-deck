<?php

use App\Support\HostEnvironment;

it('laat de omgeving met rust buiten een AppImage', function (): void {
    expect(HostEnvironment::overrides(['PATH' => '/usr/bin', 'LD_LIBRARY_PATH' => '/opt/lib']))->toBe([]);
});

it('haalt de paden van de AppImage weg zodat browser en editor normaal starten', function (): void {
    $overrides = HostEnvironment::overrides([
        'APPDIR' => '/tmp/.mount_BD-Deck',
        'APPIMAGE' => '/home/jij/Applications/BD-Deck.AppImage',
        'GSETTINGS_SCHEMA_DIR' => '/tmp/.mount_BD-Deck/usr/share/glib-2.0/schemas:',
        'LD_LIBRARY_PATH' => '/tmp/.mount_BD-Deck/usr/lib:',
        'PATH' => '/tmp/.mount_BD-Deck:/tmp/.mount_BD-Deck/usr/sbin:/home/jij/.local/bin:/usr/bin',
        'XDG_DATA_DIRS' => '/tmp/.mount_BD-Deck/usr/share/:./share/:/usr/local/share/:/usr/share/',
    ]);

    expect($overrides)->toMatchArray([
        'APPDIR' => false,
        'APPIMAGE' => false,
        'GSETTINGS_SCHEMA_DIR' => false,
        'LD_LIBRARY_PATH' => false,
        'PATH' => '/home/jij/.local/bin:/usr/bin',
        'XDG_DATA_DIRS' => '/usr/local/share/:/usr/share/',
    ]);
});
