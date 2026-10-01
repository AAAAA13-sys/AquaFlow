<?php

use Illuminate\Support\Facades\File;

if (! function_exists('asset_version')) {
    /**
     * Cache-busting query value for a public asset.
     *
     * Uses the file's modification time, so editing a stylesheet or script
     * invalidates every browser cache automatically (no manual version bump).
     */
    function asset_version(string $path): string
    {
        $full = public_path($path);

        return File::isFile($full) ? (string) File::lastModified($full) : '1';
    }
}
