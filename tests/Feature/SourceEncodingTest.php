<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the encoding of source files and rendered responses.
 *
 * Commit 1676aa6 (the attendance removal) rewrote
 * resources/views/employee/home.blade.php through an editor that saved a UTF-8
 * BOM. The employee portal then served "\xEF\xBB\xBF\n\n\n\n<!DOCTYPE html>".
 * `vendor/bin/pint --test` catches it via its `encoding` fixer, but CI did not
 * run Pint, and no test looked at the first bytes of a response - so it shipped.
 *
 * These tests are the belt to that braces. The CI workflow now runs
 * `pint --test` too (see .github/workflows/ci.yml), but a failure here points at
 * the exact file instead of a CI log line.
 */
class SourceEncodingTest extends TestCase
{
    use RefreshDatabase;

    /** Directories whose files must not carry a byte-order mark. */
    private const SCANNED = ['app', 'resources', 'routes', 'config', 'database', 'public', 'tests'];

    private const EXTENSIONS = ['php', 'js', 'cjs', 'css', 'json', 'md', 'yml'];

    public function test_no_source_file_starts_with_a_utf8_byte_order_mark(): void
    {
        $offenders = [];

        foreach (self::SCANNED as $directory) {
            $path = base_path($directory);
            if (! is_dir($path)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), self::EXTENSIONS, true)) {
                    continue;
                }

                $handle = fopen($file->getPathname(), 'rb');
                $head = fread($handle, 3);
                fclose($handle);

                if ($head === "\xEF\xBB\xBF") {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'UTF-8 BOM found in: '.implode(', ', $offenders).'. Re-save as UTF-8 without BOM.'
        );
    }

    public function test_the_cashier_portal_response_starts_with_the_doctype(): void
    {
        $this->seed();
        $user = User::where('role', 'cashier')->firstOrFail();

        $body = $this->actingAs($user)->get('/cashier')->assertOk()->getContent();

        $this->assertStringStartsWith(
            '<!DOCTYPE html>',
            ltrim($body, "\xEF\xBB\xBF"),
            'The cashier portal served bytes ahead of its DOCTYPE.'
        );
    }
}
