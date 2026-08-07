<?php

namespace Tests\Feature;

use App\Models\Link;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * /import-data restores a page from a user-supplied JSON file, which makes
 * it a second write path into every field the block editor writes. It used
 * to skip the sanitizers the editor applies:
 *
 *   - the HTML purifier was gated on button_id, but the template that
 *     renders a block (and echoes its title unescaped) is chosen by `type`
 *   - custom_css went in raw, so it could break out of the generated rule
 *   - the avatar's extension was allowlisted but its bytes never were, so
 *     any content could be written under the web-served assets/img/
 *
 * These pin each of those closed.
 */
class ImportDataTest extends TestCase
{
    use RefreshDatabase;

    private function import(array $payload)
    {
        return UploadedFile::fake()->createWithContent(
            'export.json',
            json_encode($payload)
        );
    }

    private function link(array $overrides = []): array
    {
        return array_merge([
            'button_id'   => 1,
            'link'        => 'https://example.com',
            'title'       => 'A link',
            'order'       => 1,
            'up_link'     => 0,
            'custom_css'  => '',
            'custom_icon' => '',
            'type'        => 'link',
            'type_params' => '{}',
        ], $overrides);
    }

    public function test_text_block_title_is_purified_regardless_of_button_id(): void
    {
        $user = $this->makeUser();

        // The original bug: button_id != 93 skipped the purifier, while
        // type "text" still routed to the template that echoes it raw.
        $this->actingAs($user)->post('/import-data', [
            'import' => $this->import(['links' => [$this->link([
                'button_id' => 1,
                'type'      => 'text',
                'title'     => '<script>alert(1)</script><b>keep</b>',
            ])]]),
        ]);

        $stored = Link::where('user_id', $user->id)->first();
        $this->assertNotNull($stored, 'the block should still import');
        $this->assertStringNotContainsString('<script', $stored->title);
        $this->assertStringContainsString('keep', $stored->title, 'safe markup survives');
    }

    public function test_import_cannot_declare_render_control_flags(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post('/import-data', [
            'import' => $this->import(['links' => [$this->link([
                'type'        => 'link',
                'type_params' => json_encode(['custom_html' => true, 'ignore_container' => true]),
            ])]]),
        ]);

        $stored = Link::where('user_id', $user->id)->first();
        $params = json_decode($stored->type_params, true);
        // Whatever these end up as, they must come from the block type
        // definition and not from the uploaded file.
        $linkType = \App\Models\LinkType::findByTypename('link');
        $this->assertSame($linkType->custom_html, $params['custom_html'] ?? null);
    }

    public function test_unknown_block_type_is_rejected(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post('/import-data', [
            'import' => $this->import(['links' => [$this->link(['type' => '../../evil'])]]),
        ]);

        $this->assertSame(0, Link::where('user_id', $user->id)->count());
    }

    public function test_custom_css_is_sanitized_on_import(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post('/import-data', [
            'import' => $this->import(['links' => [$this->link([
                'custom_css' => "color:red} @import url('//attacker.example/x.css'); .a{",
            ])]]),
        ]);

        $stored = Link::where('user_id', $user->id)->first();
        $this->assertStringNotContainsStringIgnoringCase('@import', $stored->custom_css);
    }

    public function test_avatar_bytes_must_really_be_an_image(): void
    {
        $user = $this->makeUser();
        $target = base_path('assets/img/' . $user->id . '.png');
        @unlink($target);

        $this->actingAs($user)->post('/import-data', [
            'import' => $this->import([
                'image_extension' => 'png',
                'image_data'      => base64_encode('<script>alert(1)</script>'),
                'links'           => [],
            ]),
        ]);

        $this->assertFileDoesNotExist($target, 'non-image bytes must never be written to a web-served path');
    }

    public function test_a_failed_import_does_not_destroy_existing_links(): void
    {
        $user = $this->makeUser();
        $keep = new Link();
        $keep->user_id = $user->id;
        $keep->title = 'Existing block';
        $keep->link = 'https://example.com';
        $keep->button_id = 1;
        $keep->type = 'link';
        $keep->order = 1;
        $keep->save();

        // Second entry is invalid, so the whole import must roll back.
        $this->actingAs($user)->post('/import-data', [
            'import' => $this->import(['links' => [
                $this->link(['title' => 'New one']),
                $this->link(['type' => 'nope_not_a_block']),
            ]]),
        ]);

        $remaining = Link::where('user_id', $user->id)->pluck('title')->all();
        $this->assertSame(['Existing block'], $remaining);
    }

    public function test_a_valid_import_still_works(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post('/import-data', [
            'import' => $this->import([
                'name'  => 'Imported Name',
                'links' => [
                    $this->link(['title' => 'First', 'order' => 1]),
                    $this->link(['title' => 'Second', 'order' => 2, 'type' => 'heading']),
                ],
            ]),
        ]);

        $this->assertSame(2, Link::where('user_id', $user->id)->count());
        $this->assertSame('Imported Name', $user->fresh()->name);
    }
}
