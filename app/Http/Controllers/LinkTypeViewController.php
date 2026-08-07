<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\LinkType;
use App\Models\Link;
use App\Models\Button;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Auth;

class LinkTypeViewController extends Controller
{
    public function getParamForm($typename, $linkId = 0)
    {
        // $typename picks the Blade template rendered at the bottom of this
        // method, so it has to resolve to a real block rather than to an
        // arbitrary caller-supplied view path.
        if (!LinkType::existsByTypename($typename)) {
            abort(404);
        }

        $data = [
            'title' => '',
            'link' => '',
            'button_id' => 0,
            'buttons' => [],
        ];

        if ($linkId) {
            // type_params holds per-block secrets — the newsletter block
            // keeps its Mailchimp API key there, and form.blade.php renders
            // it straight back into an input. Scope the lookup to the caller
            // the way the link-id middleware does on the sibling routes.
            $link = Link::find($linkId);

            if (!$link) {
                abort(404);
            }
            if ($link->user_id != Auth::id()) {
                abort(403);
            }

            $data['title'] = $link->title;
            $data['link'] = $link->link;
            if (Route::currentRouteName() != 'showButtons') {
                $data['button_id'] = $link->button_id;
            }
    
            if (!empty($link->type_params) && is_string($link->type_params)) {
                $typeParams = json_decode($link->type_params, true);
                if (is_array($typeParams)) {
                    $data = array_merge($data, $typeParams);
                }
            }
        }
        if ($typename === 'predefined') {
            // The `buttons` table ships two rows per brand (one for the
            // "button" variant, one for the "icon" variant), with the
            // same `name` and `alt`. The dropdown was rendering both,
            // which is why every brand appeared twice ("Amazon Amazon").
            // Dedupe by `name`, preferring the row whose id matches the
            // user's already-saved button_id so the dropdown's "selected"
            // option resolves to the correct row on edit.
            $buttons = Button::select()->orderBy('name', 'asc')->get();
            $savedButtonId = ($linkId && isset($link)) ? $link->button_id : null;
            $byName = [];
            foreach ($buttons as $btn) {
                $isSelected = ($savedButtonId !== null && $savedButtonId == $btn->id);
                if (!isset($byName[$btn->name])) {
                    $byName[$btn->name] = [
                        'name'     => $btn->name,
                        'title'    => $btn->alt,
                        'exclude'  => $btn->exclude,
                        'selected' => $isSelected,
                    ];
                } elseif ($isSelected) {
                    // The variant the user actually has stored is the
                    // tie-breaker; replace the dedupe entry with this
                    // one so the dropdown opens with the right pick.
                    $byName[$btn->name]['selected'] = true;
                }
            }
            $data['buttons'] = array_values($byName);
            return view('components.pageitems.predefined-form', $data);
        }
    
        // Set the block asset context before returning the view
        setBlockAssetContext($typename);
    
        return view($typename . '.form', $data);
    }

    public function blockAsset(Request $request, $type)
    {
        $asset = $request->query('asset');
    
        // Prevent directory traversal in $type
        if (preg_match('/\.\.|\/|\\\\/', $type)) {
            abort(403, 'Unauthorized action.');
        }
    
        // Define allowed file extensions
        $allowedExtensions = ['js', 'css', 'img', 'svg', 'gif', 'jpg', 'jpeg', 'png', 'mp4', 'mp3'];
    
        $extension = strtolower(pathinfo($asset, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions)) {
            return response('File type not allowed', Response::HTTP_FORBIDDEN);
        }
    
        $basePath = realpath(base_path("blocks/$type"));
    
        $fullPath = realpath(base_path("blocks/$type/$asset"));
    
        if (!$fullPath || !file_exists($fullPath) || strpos($fullPath, $basePath) !== 0) {
            return response('File not found', Response::HTTP_NOT_FOUND);
        }
    
        // Map file extensions to MIME types
        $mimeTypes = [
            'js' => 'application/javascript',
            'css' => 'text/css',
            'img' => 'image/png',
            'svg' => 'image/svg+xml',
            'gif' => 'image/gif',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'mp4' => 'video/mp4',
            'mp3' => 'audio/mpeg',
        ];
    
        // Determine the MIME type using the mapping
        $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';
    
        return response()->file($fullPath, [
            'Content-Type' => $mimeType
        ]);
    }
}