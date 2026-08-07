<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Cohensive\OEmbed\Facades\OEmbed;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Response;
use JeroenDesloovere\VCard\VCard;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use App\Mail\ReportSubmissionMail;
use GeoSot\EnvEditor\Facades\EnvEditor;

use Auth;
use DB;
use ZipArchive;
use File;

use App\Models\User;
use App\Models\Button;
use App\Models\Link;
use App\Models\LinkType;
use App\Models\UserData;


//Function tests if string starts with certain string (used to test for illegal strings)
function stringStartsWith($haystack, $needle, $case = true)
{
    if ($case) {
        return strpos($haystack, $needle, 0) === 0;
    }
    return stripos($haystack, $needle, 0) === 0;
}

//Function tests if string ends with certain string (used to test for illegal strings)
function stringEndsWith($haystack, $needle, $case = true)
{
    $expectedPosition = strlen($haystack) - strlen($needle);
    if ($case) {
        return strrpos($haystack, $needle, 0) === $expectedPosition;
    }
    return strripos($haystack, $needle, 0) === $expectedPosition;
}

class UserController extends Controller
{

    //Statistics of the number of clicks and links
    public function index()
    {
        $userId = Auth::user()->id;

        $littlelink_name = Auth::user()->littlelink_name;
        $userinfo = User::find($userId);

        $links = Link::where('user_id', $userId)->select('link')->count();
        $clicks = Link::where('user_id', $userId)->sum('click_number');
        $topLinks = Link::where('user_id', $userId)->orderby('click_number', 'desc')
            ->whereNotNull('link')->where('link', '<>', '')
            ->take(5)->get();

        $pageStats = [
            'visitors' => [
                'all' => visits('App\Models\User', $littlelink_name)->count(),
                'day' => visits('App\Models\User', $littlelink_name)->period('day')->count(),
                'week' => visits('App\Models\User', $littlelink_name)->period('week')->count(),
                'month' => visits('App\Models\User', $littlelink_name)->period('month')->count(),
                'year' => visits('App\Models\User', $littlelink_name)->period('year')->count(),
            ],
            'os' => visits('App\Models\User', $littlelink_name)->operatingSystems(),
            'referers' => visits('App\Models\User', $littlelink_name)->refs(),
            'countries' => visits('App\Models\User', $littlelink_name)->countries(),
        ];



        return view('studio/index', ['greeting' => $userinfo->name, 'toplinks' => $topLinks, 'links' => $links, 'clicks' => $clicks, 'pageStats' => $pageStats]);
    }

    //Show littlelink page. example => http://127.0.0.1:8000/+admin
    public function littlelink(request $request)
    {
        if(isset($request->useif)){
            $littlelink_name = User::select('littlelink_name')->where('id', $request->littlelink)->value('littlelink_name');
            $id = $request->littlelink;
        } else {
            $littlelink_name = $request->littlelink;
            $id = User::select('id')->where('littlelink_name', $littlelink_name)->value('id');
        }

        if (empty($id)) {
            return abort(404);
        }

        // Draft/publish: public visitors get the PUBLISHED snapshot; the
        // owner's ?preview=1 and users with no snapshot yet fall through
        // to the live (draft) render below. See DRAFT-PUBLISH-PLAN.md.
        $published = $this->maybePublishedView($id, $request, $littlelink_name);
        if ($published !== null) {
            return $published;
        }

        $userinfo = User::select('id', 'name', 'littlelink_name', 'littlelink_description', 'theme', 'role', 'block', 'google_analytics_id', 'theme_customization')->where('id', $id)->first();
        $information = User::select('name', 'littlelink_name', 'littlelink_description', 'theme')->where('id', $id)->get();
        
        if ($userinfo->block == 'yes') {
            return abort(404);
        }

        $links = DB::table('links')
        ->join('buttons', 'buttons.id', '=', 'links.button_id')
        ->select('links.*', 'buttons.name') // Assuming 'links.*' to fetch all columns including 'type_params'
        ->where('user_id', $id)
        ->orderBy('up_link', 'asc')
        ->orderBy('order', 'asc')
        ->get();

        // Loop through each link to decode 'type_params' and merge it into the link object
        foreach ($links as $link) {
            if (!empty($link->type_params)) {
                // Decode the JSON string into an associative array
                $typeParams = json_decode($link->type_params, true);
                if (is_array($typeParams)) {
                    // Merge the associative array into the link object
                    foreach ($typeParams as $key => $value) {
                        $link->$key = $value;
                    }
                }
            }
        }

        return view('linkstack.linkstack', ['userinfo' => $userinfo, 'information' => $information, 'links' => $links, 'littlelink_name' => $littlelink_name]);
    }

    // Temporary-redirect feature removed — superseded by Mail Minted's
    // OpenSRS URL forwarding (registrar-level), which is the correct
    // layer and avoids fighting the bio-page apex A record. The
    // redirect_enabled / redirect_url columns remain in the DB but are
    // no longer read or written.

    //Show littlelink page as home page if set in config
    public function littlelinkhome(request $request)
    {
        $littlelink_name = env('HOME_URL');
        $id = User::select('id')->where('littlelink_name', $littlelink_name)->value('id');

        if (empty($id)) {
            return abort(404);
        }

        // Draft/publish (see littlelink() + DRAFT-PUBLISH-PLAN.md).
        $published = $this->maybePublishedView($id, $request, $littlelink_name);
        if ($published !== null) {
            return $published;
        }

        $userinfo = User::select('id', 'name', 'littlelink_name', 'littlelink_description', 'theme', 'role', 'block', 'google_analytics_id', 'theme_customization')->where('id', $id)->first();
        $information = User::select('name', 'littlelink_name', 'littlelink_description', 'theme')->where('id', $id)->get();

        $links = DB::table('links')
        ->join('buttons', 'buttons.id', '=', 'links.button_id')
        ->select('links.*', 'buttons.name') // Assuming 'links.*' to fetch all columns including 'type_params'
        ->where('user_id', $id)
        ->orderBy('up_link', 'asc')
        ->orderBy('order', 'asc')
        ->get();

        // Loop through each link to decode 'type_params' and merge it into the link object
        foreach ($links as $link) {
            if (!empty($link->type_params)) {
                // Decode the JSON string into an associative array
                $typeParams = json_decode($link->type_params, true);
                if (is_array($typeParams)) {
                    // Merge the associative array into the link object
                    foreach ($typeParams as $key => $value) {
                        $link->$key = $value;
                    }
                }
            }
        }

        return view('linkstack.linkstack', ['userinfo' => $userinfo, 'information' => $information, 'links' => $links, 'littlelink_name' => $littlelink_name]);
    }

    /**
     * Draft/publish decision for a bio page. Returns the PUBLISHED-
     * snapshot view for public visitors, or null to let the caller fall
     * through to its live (draft) render. The `block` (admin-disabled)
     * flag is a live property and 404s immediately. Only the
     * authenticated owner may see the live draft via ?preview=1 — any
     * other ?preview=1 gets the published snapshot, so drafts can't leak.
     */
    private function maybePublishedView($id, request $request, $littlelink_name)
    {
        $owner = User::select('id', 'block', 'published_snapshot')->where('id', $id)->first();
        if (!$owner) {
            return null; // nonexistent — let the live path handle it
        }
        if ($owner->block == 'yes') {
            abort(404); // page disabled by admin — immediate, not drafted
        }

        $isOwnerPreview = $request->boolean('preview') && Auth::check() && Auth::id() == $owner->id;
        if ($isOwnerPreview || empty($owner->published_snapshot)) {
            return null; // owner previewing the draft, or never published -> render live
        }

        $snap = json_decode($owner->published_snapshot, true);
        if (!is_array($snap)) {
            return null; // malformed snapshot -> fail safe to the live render
        }

        [$userinfo, $information, $links] = \App\Services\PublishedPage::hydrate($snap);
        $images = $snap['images'] ?? [];
        return view('linkstack.linkstack', [
            'userinfo' => $userinfo,
            'information' => $information,
            'links' => $links,
            'littlelink_name' => $littlelink_name,
            // Published image copies so avatar/background honor draft/publish.
            'avatarOverride' => $images['avatar'] ?? null,
            'backgroundOverride' => $images['background'] ?? null,
        ]);
    }

    //Redirect to user page
    public function userRedirect(request $request)
    {
        $id = $request->id;
        $user = User::select('littlelink_name')->where('id', $id)->value('littlelink_name');

        if (empty($id)) {
            return abort(404);
        }
     
        if (empty($user)) {
            return abort(404);
        }

        return redirect(url('@'.$user));
    }

    //Show add/update form
    public function AddUpdateLink($id = 0)
    {
        $linkData = $id ? Link::find($id) : new Link(['typename' => 'link', 'id' => '0']);
    
        $data = [
            'LinkTypes' => LinkType::get(),
            'LinkData' => $linkData,
            'LinkID' => $id,
            'linkTypeID' => "predefined",
            'title' => "Predefined Site",
        ];

        $data['typename'] = $linkData->type ?? 'predefined';

        // Embed mode: the unified /studio/edit Blocks tab loads this page
        // in an in-panel iframe (?embed=1). The view hides the dashboard
        // chrome and the forms carry an embed flag so saveLink knows to
        // return into the panel rather than the standalone page.
        $data['embed'] = (bool) request()->boolean('embed');

        return view('studio/edit-link', $data);
    }

    //Save add link
    public function saveLink(Request $request)
    {
        // Step 1: Validate Request
        // $request->validate([
        //     'link' => 'sometimes|url',
        // ]);
    
        // Step 2: Determine Link Type and Title
        $linkType = LinkType::findByTypename($request->typename);
        $LinkTitle = $request->title;
        $LinkURL = $request->link;

        // Step 3: Load Link Type Logic
        if($request->typename == 'predefined' || $request->typename == 'link') {
            // Determine button id based on whether a custom or predefined button is used
            $button_id = ($request->typename == 'link') ? ($request->GetSiteIcon == 1 ? 2 : 1) : null;
            $button = ($request->typename != 'link') ? Button::where('name', $request->button)->first() : null;

            $linkData = [
                'link' => $LinkURL,
                'title' => $LinkTitle ?? $button?->alt,
                'user_id' => Auth::user()->id,
                'button_id' => $button?->id ?? $button_id,
                'type' => $request->typename // Save the link type
            ];
        } else {
            // Namespaced loader, NOT a bare include: every handler.php
            // declares the same global function, which fatals on the
            // second save in one process (tests / queue workers).
            $handler = \App\Services\BlockHandler::for($linkType->typename);
            if ($handler !== null) {
                $result = $handler($request, $linkType);

                // Extract rules and linkData from the result
                $rules = $result['rules'];
                $linkData = $result['linkData'];
            
                // Validate the request
                $validator = Validator::make($request->all(), $rules);

                // Check if validation fails. Redirect explicitly back to the
                // block editor (not back(), which can resolve to /dashboard and
                // swallow the errors) so the user actually sees WHY the block
                // didn't save — otherwise a failed save looks like the block
                // silently never appeared.
                if ($validator->fails()) {
                    $embed  = $request->boolean('embed') ? '?embed=1' : '';
                    $target = empty($request->linkid)
                        ? url('studio/add-link' . $embed)
                        : url('studio/edit-link/' . (int) $request->linkid . $embed);
                    return redirect($target)->withErrors($validator)->withInput();
                }

                $linkData['button_id'] = $linkData['button_id'] ?? 1; // Set 'button_id' unless overwritten by handleLinkType
                $linkData['type'] = $linkType->typename; // Ensure 'type' is included in $linkData
            } else {
                abort(404, "Link type logic not found.");
            }
        }   

        // Step 4: Per-block Appearance fields (Pass 3).
        //
        // The unified /studio/edit-link page posts these fields with
        // the rest of the form. `custom_css` and `custom_icon` map to
        // real columns on `links`; the `appearance_*` keys describe
        // the visual-control state (which preset, which colors,
        // which shape, etc.) and auto-route to the `type_params`
        // JSON column via array_diff_key further down — but only if
        // we put them in $linkData here first, since $linkData was
        // built above with an explicit hardcoded key list.
        //
        // We only merge if the field is *present* in the request
        // (using has() rather than input()) so block types whose
        // forms don't include these fields won't accidentally wipe
        // existing values to empty on save.
        foreach (['custom_css', 'custom_icon',
                  'appearance_preset', 'appearance_primary',
                  'appearance_text', 'appearance_secondary',
                  'appearance_shape', 'appearance_hover',
                  'appearance_advanced', 'appearance_heading',
                  'appearance_color'] as $appKey) {
            if ($request->has($appKey)) {
                $linkData[$appKey] = $request->input($appKey);
            }
        }

        // ConvertEmptyStringsToNull turns a posted empty icon into
        // null, but links.custom_icon is NOT NULL in the migration
        // schema — inserting a new block with the field present-but-
        // empty would 500. Coerce to '' (custom_css gets the same
        // treatment in the sparse-discipline block below).
        if (array_key_exists('custom_icon', $linkData) && $linkData['custom_icon'] === null) {
            $linkData['custom_icon'] = '';
        }

        // Sanitize per-block CSS at rest (defense in depth) — neutralize
        // at-rules / legacy script-in-CSS / script url() schemes before
        // it's stored. See mm_sanitize_block_css().
        if (array_key_exists('custom_css', $linkData)) {
            $linkData['custom_css'] = mm_sanitize_block_css($linkData['custom_css']);
        }

        // Sparse discipline (Phase 5): an empty custom_css means "this
        // block's BUTTON follows the theme." Drop the button-channel
        // appearance_* state too so the editor re-hydrates from the
        // theme's values next time — otherwise stale state would
        // resurrect old choices after a reset-to-theme or theme switch.
        if (array_key_exists('custom_css', $linkData) && trim((string) $linkData['custom_css']) === '') {
            // ConvertEmptyStringsToNull turned the posted '' into null,
            // and the update path drops nulls (so partial forms don't
            // wipe columns). Coerce back to '' so returning a block to
            // the theme actually CLEARS a previously saved style.
            $linkData['custom_css'] = '';
            foreach (['appearance_preset', 'appearance_primary',
                      'appearance_text', 'appearance_secondary',
                      'appearance_shape', 'appearance_hover',
                      'appearance_advanced'] as $appKey) {
                unset($linkData[$appKey]);
            }
        }

        // Heading / text color are channels of their own (Phase 6) —
        // they render via block_appearance_style, independent of the
        // button styling. Empty = follow the theme = store nothing.
        foreach (['appearance_heading', 'appearance_color'] as $appKey) {
            if (array_key_exists($appKey, $linkData) && trim((string) $linkData[$appKey]) === '') {
                unset($linkData[$appKey]);
            }
        }

        // Step 5: User and Button Information
        $userId = Auth::user()->id;
        $button = Button::where('name', $request->button)->first();
        if ($button && empty($LinkTitle)) $LinkTitle = $button->alt;

        // Step 6: Prepare Link Data
        // (Handled by the included file)

        // Step 7: Save or Update Link
        //
        // Scope the update target to the caller's OWN links. This POST
        // endpoint (route addLink) has no {id} route param, so the
        // link-id middleware never runs here — the target id arrives in
        // the request body. Without this owner check any authenticated
        // user could overwrite anyone's block (content, link URL,
        // custom_css) by posting that block's id as `linkid`.
        //
        // linkid 0 / empty is the "new block" sentinel (add flow posts
        // $LinkID = 0), so it falls through to the create path below. A
        // non-empty id that doesn't belong to the caller is refused
        // rather than silently creating a new block.
        $OrigLink = null;
        if (!empty($request->linkid)) {
            $OrigLink = Link::where('id', $request->linkid)
                ->where('user_id', $userId)
                ->first();
            if (!$OrigLink) {
                abort(403);
            }
        }
        $linkColumns = Schema::getColumnListing('links'); // Get all column names of links table
        $filteredLinkData = array_intersect_key($linkData, array_flip($linkColumns)); // Filter $linkData to only include keys that are columns in the links table

        // Combine remaining variables into one array and convert to JSON for the type_params column
        $customParams = array_diff_key($linkData, $filteredLinkData);

            // Check if $linkType->custom_html is defined and not null
            if (isset($linkType->custom_html)) {
                // Add $linkType->custom_html to the $customParams array
                $customParams['custom_html'] = $linkType->custom_html;
            }

            // Check if $linkType->ignore_container is defined and not null
            if (isset($linkType->ignore_container)) {
                // Add $linkType->ignore_container to the $customParams array
                $customParams['ignore_container'] = $linkType->ignore_container;
            }

            // Check if $linkType->include_libraries is defined and not null
            if (isset($linkType->include_libraries)) {
                // Add $linkType->include_libraries to the $customParams array
                $customParams['include_libraries'] = $linkType->include_libraries;
            }
        
        $filteredLinkData['type_params'] = json_encode($customParams);

        if ($OrigLink) {
            $currentValues = $OrigLink->getAttributes();
            $nonNullFilteredLinkData = array_filter($filteredLinkData, function($value) {return !is_null($value);});
            $updatedValues = array_merge($currentValues, $nonNullFilteredLinkData);
            $OrigLink->update($updatedValues);
            $message = "Link updated";
        } else {
            $link = new Link($filteredLinkData);
            $link->user_id = $userId;
            $link->save();
            $message = "Link added";
        }

        // Step 8: Redirect
        // Where to go after saving a block:
        // - 'add_more' keeps the operator in the add flow for another block.
        //   In embed mode (the Blocks-tab panel) we keep ?embed=1 so the
        //   reloaded form stays chrome-less inside the panel.
        // - a normal save returns to the Blocks tab; in embed mode the
        //   panel's parent watches for this navigation and refreshes the
        //   list + live preview.
        $embed = $request->boolean('embed');
        if ($request->input('param') == 'add_more') {
            $redirectUrl = $embed ? 'studio/add-link?embed=1' : 'studio/add-link';
        } else {
            $redirectUrl = '/studio/edit#blocks';
        }
        return Redirect($redirectUrl)->with('success', $message);
    }

    /**
     * Render a single block from the block editor's CURRENT (unsaved) form
     * state, so the studio's live preview can reflect edits as they're typed
     * — congruent with the Basics/Appearance tabs. Nothing is persisted.
     *
     * Mirrors saveLink's field -> linkData -> (columns | type_params) mapping
     * (the per-block handler.php is a pure function), then renders the block's
     * display template the same way the public bio page does. Only custom-HTML
     * block types render via blocks::{type}.display; button / social /
     * predefined types render inline in buttons.blade and return null here
     * (the caller keeps their existing in-editor sample).
     */
    public function blockPreview(Request $request)
    {
        $userId = Auth::id();
        $typename = (string) $request->input('typename');
        if ($typename === '') {
            return response()->json(['html' => null]);
        }

        $linkType = LinkType::findByTypename($typename);
        if (!$linkType || empty($linkType->custom_html)) {
            return response()->json(['html' => null]);
        }

        // Same namespaced loader saveLink uses (no bare include — the
        // global handleLinkType() redeclares in long-lived processes).
        $handler = \App\Services\BlockHandler::for($linkType->typename);
        if ($handler === null) {
            return response()->json(['html' => null]);
        }
        try {
            $result = $handler($request, $linkType);
        } catch (\Throwable $e) {
            // A half-typed form can trip a handler; just skip this frame.
            return response()->json(['html' => null]);
        }
        $linkData = $result['linkData'] ?? [];

        // Per-block appearance fields (present-only), matching saveLink.
        foreach (['custom_css', 'custom_icon', 'appearance_preset',
                  'appearance_primary', 'appearance_text', 'appearance_secondary',
                  'appearance_shape', 'appearance_hover', 'appearance_advanced',
                  'appearance_heading', 'appearance_color'] as $appKey) {
            if ($request->has($appKey)) {
                $linkData[$appKey] = $request->input($appKey);
            }
        }
        if (array_key_exists('custom_css', $linkData)) {
            $linkData['custom_css'] = mm_sanitize_block_css($linkData['custom_css']);
        }
        foreach (['appearance_heading', 'appearance_color'] as $appKey) {
            if (array_key_exists($appKey, $linkData) && trim((string) $linkData[$appKey]) === '') {
                unset($linkData[$appKey]);
            }
        }

        // Split columns vs type_params, exactly like saveLink.
        $linkColumns = Schema::getColumnListing('links');
        $filtered = array_intersect_key($linkData, array_flip($linkColumns));
        $customParams = array_diff_key($linkData, $filtered);
        if (isset($linkType->custom_html))       $customParams['custom_html'] = $linkType->custom_html;
        if (isset($linkType->ignore_container))  $customParams['ignore_container'] = $linkType->ignore_container;
        if (isset($linkType->include_libraries)) $customParams['include_libraries'] = $linkType->include_libraries;
        $filtered['type_params'] = json_encode($customParams);

        // Temporary, UNSAVED link. Use the real id for an existing block so
        // the rendered wrapper id matches the element already in the preview.
        $link = new Link();
        $link->forceFill($filtered);
        $link->id = (int) ($request->input('linkid') ?: 0);
        $link->user_id = $userId;
        $link->type = $typename;
        // Templates read type_params keys as properties (e.g. $link->alignment).
        foreach ($customParams as $k => $v) {
            $link->{$k} = $v;
        }

        if (function_exists('setBlockAssetContext')) {
            setBlockAssetContext($typename);
        }
        try {
            $html = view('blocks::' . $typename . '.display', [
                'link' => $link,
                'initial' => 0,
                'userinfo' => User::find($userId),
            ])->render();
        } catch (\Throwable $e) {
            return response()->json(['html' => null]);
        }

        return response()->json(['html' => $html]);
    }
    
    public function sortLinks(Request $request)
    {
        $linkOrders  = $request->input("linkOrders", []);
        $currentPage = $request->input("currentPage", 1);
        $perPage     = $request->input("perPage", 0);

        if ($perPage == 0) {
            $currentPage = 1;
        }

        $linkOrders = array_unique(array_filter($linkOrders));
        if (!$linkOrders || $currentPage < 1) {
            return response()->json([
                'status' => 'ERROR',
            ]);
        }

        $newOrder = $perPage * ($currentPage - 1);
        $linkNewOrders = [];
        foreach ($linkOrders as $linkId) {
            if ($linkId < 0) {
                continue;
            }

            // Scoped to the caller's own links — without this, any
            // signed-in user could reorder anyone's page by posting
            // foreign link ids. Foreign/unknown ids update nothing and
            // stay out of the response map.
            $updated = Link::where("id", $linkId)
                ->where('user_id', Auth::id())
                ->update([
                    'order' => $newOrder
                ]);
            if ($updated) {
                $linkNewOrders[$linkId] = $newOrder;
                $newOrder++;
            }
        }

        return response()->json([
            'status' => 'OK',
            'linkOrders' => $linkNewOrders,
        ]);
    }


    //Count the number of clicks and redirect to link
    public function clickNumber(request $request)
    {
        $linkId = $request->id;

        if (substr($linkId, -1) == '+') {
            $linkWithoutPlus = str_replace('+', '', $linkId);
            return redirect(url('info/'.$linkWithoutPlus));
        }
    
        $link = Link::find($linkId);

        if (empty($link)) {
            return abort(404);
        }

        $link = $link->link;

        if (empty($linkId)) {
            return abort(404);
        }

        Link::where('id', $linkId)->increment('click_number', 1);

        $response = redirect()->away($link);
        $response->header('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    //Download Vcard
    public function vcard(request $request)
    {
        $linkId = $request->id;

        // Find the link with the specified ID
        $link = Link::findOrFail($linkId);

        $json = $link->link;

        // Decode the JSON to a PHP array
        $data = json_decode($json, true);
        
        // Create a new vCard object
        $vcard = new VCard();
        
        // Set the vCard properties from the $data array
        $vcard->addName($data['last_name'], $data['first_name'], $data['middle_name'], $data['prefix'], $data['suffix']);
        $vcard->addCompany($data['organization']);
        $vcard->addJobtitle($data['vtitle']);
        $vcard->addRole($data['role']);
        $vcard->addEmail($data['email']);
        $vcard->addEmail($data['work_email'], 'WORK');
        $vcard->addURL($data['work_url'], 'WORK');
        $vcard->addPhoneNumber($data['home_phone'], 'HOME');
        $vcard->addPhoneNumber($data['work_phone'], 'WORK');
        $vcard->addPhoneNumber($data['cell_phone'], 'CELL');
        $vcard->addAddress($data['home_address_street'], '', $data['home_address_city'], $data['home_address_state'], $data['home_address_zip'], $data['home_address_country'], 'HOME');
        $vcard->addAddress($data['work_address_street'], '', $data['work_address_city'], $data['work_address_state'], $data['work_address_zip'], $data['work_address_country'], 'WORK');
        

        // $vcard->addPhoto(base_path('img/1.png'));
        
        // Generate the vCard file contents
        $file_contents = $vcard->getOutput();
        
        // Set the file headers for download
        $headers = [
            'Content-Type' => 'text/x-vcard',
            'Content-Disposition' => 'attachment; filename="contact.vcf"'
        ];
        
        Link::where('id', $linkId)->increment('click_number', 1);

        // Return the file download response
        return response()->make($file_contents, 200, $headers);

    }

    // showLinks() and showSocialIcons() removed 2026-07-05: their
    // standalone pages became tabs of the unified /studio/edit editor
    // (studio/partials/edit/blocks + .social), and the old GET routes
    // are now redirect closures. The reorderSocialIcons endpoint below
    // is still live — the Social tab's drag-reorder posts to it.

    /**
     * Receives a new ordering of the user's social icons from the
     * drag-and-drop UI on /studio/social-icons. POST body must have
     * `order` as an array of link IDs in their new visible order.
     * Each row's `order` column is rewritten to its index in that
     * array, scoped to the current user and the icon button type so
     * a malicious caller can't shuffle anyone else's rows.
     */
    public function reorderSocialIcons(Request $request)
    {
        $request->validate([
            'order'   => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $userId = Auth::id();
        $position = 0;
        foreach ($request->input('order', []) as $linkId) {
            DB::table('links')
                ->where('user_id', $userId)
                ->where('button_id', 94)
                ->where('id', (int) $linkId)
                ->update(['order' => $position]);
            $position++;
        }

        return response()->json(['ok' => true]);
    }

    //Delete link
    public function deleteLink(request $request)
    {
        $linkId = $request->id;

        Link::where('id', $linkId)->delete();

        $directory = base_path("assets/favicon/icons");
        $files = scandir($directory);
        foreach($files as $file) {
        if (strpos($file, $linkId.".") !== false) {
        $pathinfo = pathinfo($file, PATHINFO_EXTENSION);}}
        if (isset($pathinfo)) {
        try{File::delete(base_path("assets/favicon/icons")."/".$linkId.".".$pathinfo);} catch (exception $e) {}
        }

        return redirect('/studio/links');
    }

    //Delete icon
    public function clearIcon(request $request)
    {
        $linkId = $request->id;

        $directory = base_path("assets/favicon/icons");
        $files = scandir($directory);
        foreach($files as $file) {
        if (strpos($file, $linkId.".") !== false) {
        $pathinfo = pathinfo($file, PATHINFO_EXTENSION);}}
        if (isset($pathinfo)) {
        try{File::delete(base_path("assets/favicon/icons")."/".$linkId.".".$pathinfo);} catch (exception $e) {}
        }

        return redirect('/studio/links');
    }

    //Raise link on the littlelink page
    public function upLink(request $request)
    {
        $linkId = $request->id;
        $upLink = $request->up;

        if ($upLink == 'yes') {
            $up = 'no';
        } elseif ($upLink == 'no') {
            $up = 'yes';
        }

        Link::where('id', $linkId)->update(['up_link' => $up]);

        return back();
    }

    //Show link to edit
    public function showLink(request $request)
    {
        $linkId = $request->id;

        $link = Link::where('id', $linkId)->value('link');
        $title = Link::where('id', $linkId)->value('title');
        $order = Link::where('id', $linkId)->value('order');
        $custom_css = Link::where('id', $linkId)->value('custom_css');
        $buttonId = Link::where('id', $linkId)->value('button_id');
        $buttonName = Button::where('id', $buttonId)->value('name');

        $buttons = Button::select('id', 'name')->orderBy('name', 'asc')->get();

        return view('studio/edit-link', ['custom_css' => $custom_css, 'buttonId' => $buttonId, 'buttons' => $buttons, 'link' => $link, 'title' => $title, 'order' => $order, 'id' => $linkId, 'buttonName' => $buttonName]);
    }

    //Show custom CSS + custom icon
    //
    // The legacy /studio/button-editor/{id} page has been retired as
    // part of Pass 3 (UI-PASS-PLAN.md). Per-block CSS + icon editing
    // now lives inside the Appearance section of /studio/edit-link/{id}.
    // Redirect any bookmark / muscle-memory traffic to the new home
    // (the `appearance` URL fragment scrolls the new section into
    // view on arrival).
    //Save edit link
    public function editLink(request $request)
    {
        $request->validate([
            'link' => 'required|exturl',
            'title' => 'required',
            'button' => 'required',
        ]);

        if (stringStartsWith($request->link, 'http://') == 'true' or stringStartsWith($request->link, 'https://') == 'true' or stringStartsWith($request->link, 'mailto:') == 'true')
            $link1 = $request->link;
        else
            $link1 = 'https://' . $request->link;
        if (stringEndsWith($request->link, '/') == 'true')
            $link = rtrim($link1, "/ ");
        else
        $link = $link1;
        $title = $request->title;
        $order = $request->order;
        $button = $request->button;
        $linkId = $request->id;

        $buttonId = Button::select('id')->where('name', $button)->value('id');

        Link::where('id', $linkId)->update(['link' => $link, 'title' => $title, 'order' => $order, 'button_id' => $buttonId]);

        return redirect('/studio/links');
    }

    /**
     * Unified studio editor — /studio/edit. Consolidates what used to be
     * four separate pages (page / appearance / social-icons / links)
     * into one tabbed page with a shared live preview. This method just
     * assembles the union of view-data the four ported tab partials
     * need; the forms inside them still post to their original
     * controller endpoints unchanged (per-tab save, no rewrite).
     *
     *   Basics tab     -> $pages                       (was showPage)
     *   Appearance tab -> $user, $saved, $fonts        (was AppearanceController::show)
     *   Social tab     -> $configuredIcons             (was showSocialIcons)
     *   Blocks tab     -> $links, $pagePage            (was showLinks)
     */
    public function showEditor()
    {
        $userId = Auth::id();

        // Draft/publish: onboard this user to the snapshot model if the
        // backfill missed them (or they're brand new), so their public
        // page is snapshot-backed and edits become draft. No-op once set.
        \App\Services\PublishedPage::ensureSnapshot($userId);
        // Drives the "unpublished changes" banner: does the draft differ
        // from what's published?
        $isDirty = \App\Services\PublishedPage::isDirty($userId);

        $user   = User::find($userId);

        // Basics
        $pages = User::where('id', $userId)
            ->select('littlelink_name', 'littlelink_description', 'image', 'name')
            ->get();

        // Appearance — controls hydrate from the theme manifest merged
        // with the user's sparse overrides, so the knobs always show
        // what the page actually renders (Phase 2, sparse model). The
        // raw sparse blob feeds the "edited" indicators (Phase 3).
        $saved = AppearanceController::effectiveForUser($user);
        $sparseAppearance = AppearanceController::sparseForUser($user);
        $fonts = AppearanceController::GOOGLE_FONTS;

        // Social icons — links rows using the special "icon" button (94)
        $configuredIcons = DB::table('links')
            ->where('user_id', $userId)
            ->where('button_id', 94)
            ->orderBy('order', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // Blocks list (mirrors showLinks)
        $pagePage = 10;
        $links = Link::select()
            ->where('user_id', $userId)
            ->orderBy('up_link', 'asc')
            ->orderBy('order', 'asc')
            ->paginate(99999);

        return view('studio.edit', compact(
            'user', 'pages', 'saved', 'sparseAppearance', 'fonts', 'configuredIcons', 'pagePage', 'links', 'isDirty'
        ));
    }

    /**
     * Publish: promote the current draft (live DB) to the published
     * snapshot the public /@handle renders from. See DRAFT-PUBLISH-PLAN.
     */
    public function publish(request $request)
    {
        \App\Services\PublishedPage::publishFor(Auth::id());
        return redirect('/studio/edit')->with('success', 'Your page is now live.');
    }

    /**
     * Discard: revert the draft (live DB + images) back to the published
     * snapshot. See DRAFT-PUBLISH-PLAN.md.
     */
    public function discard(request $request)
    {
        \App\Services\PublishedPage::discard(Auth::id());
        return redirect('/studio/edit')->with('success', 'Your unpublished changes were discarded.');
    }

    //Save littlelink page (name, description, logo)
    public function editPage(Request $request)
    {
        $userId = Auth::user()->id;
        $littlelink_name = Auth::user()->littlelink_name;
    
        $validator = Validator::make($request->all(), [
            'littlelink_name' => [
                'sometimes',
                'max:255',
                'string',
                'isunique:users,id,'.$userId,
            ],
            'name' => 'sometimes|max:255|string',
            // pageDescription accepts up to 250 chars of source text;
            // little_link_description is VARCHAR(255), and the 5-char
            // budget covers the small HTML overhead from the trimmed
            // CKEditor (a single <a> or <strong> wrapper).
            'pageDescription' => 'sometimes|nullable|string|max:500',
            'image' => 'sometimes|image|mimes:jpeg,jpg,png,webp|max:2048', // Max file size: 2MB
        ], [
            'littlelink_name.unique' => __('messages.That handle has already been taken'),
            'image.image' => __('messages.The selected file must be an image'),
            'image.mimes' => __('messages.The image must be') . ' JPEG, JPG, PNG, webP.',
            'image.max' => __('messages.The image size should not exceed 2MB'),
        ]);
    
        if ($validator->fails()) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['ok' => false, 'errors' => $validator->errors()], 422);
            }
            return redirect('/studio/edit#basics')->withErrors($validator)->withInput();
        }
    
        $profilePhoto = $request->file('image');
        $pageName = $request->littlelink_name;
        // The page description is a plain-text tagline (the Basics editor is a
        // plain textarea, not a rich-text editor). Strip any tags so no markup
        // is ever stored or rendered, and trim stray whitespace.
        $pageDescription = trim(strip_tags((string) $request->pageDescription));
        $name = $request->name;
        $checkmark = $request->checkmark;
        $sharebtn = $request->sharebtn;
        $tablinks = $request->tablinks;

        // Only touch littlelink_name (the handle) if the input was
        // actually submitted. The Studio UI no longer exposes that
        // field — handles are managed by Mail Minted provisioning and
        // the admin panel. Skipping the update protects against an
        // empty submit blanking the column to null.
        $updates = [
            'littlelink_description' => $pageDescription,
            'name'                   => $name,
        ];
        if ($request->filled('littlelink_name')) {
            $updates['littlelink_name'] = $pageName;
            if(env('HOME_URL') !== '' && $pageName != $littlelink_name && $littlelink_name == env('HOME_URL')){
                EnvEditor::editKey('HOME_URL', $pageName);
            }
        }

        User::where('id', $userId)->update($updates);
    
        if ($request->hasFile('image')) {

            // Delete the user's current avatar if it exists
            while (findAvatar($userId) !== "error.error") {
                $avatarName = findAvatar($userId);
                unlink(base_path($avatarName));
            }
            
            $fileName = $userId . '_' . time() . "." . $profilePhoto->extension();
            $profilePhoto->move(base_path('assets/img'), $fileName);
        }
    
        if ($checkmark == "on") {
            UserData::saveData($userId, 'checkmark', true);
        } else {
            UserData::saveData($userId, 'checkmark', false);
        }
    
        if ($sharebtn == "on") {
            UserData::saveData($userId, 'disable-sharebtn', false);
        } else {
            UserData::saveData($userId, 'disable-sharebtn', true);
        }

        if ($tablinks == "on") {
            UserData::saveData($userId, 'links-new-tab', true);
        } else {
            UserData::saveData($userId, 'links-new-tab', false);
        }
    
        // Auto-save wants a fast, tiny reply, not a redirect it follows and
        // downloads the whole editor page for.
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return Redirect('/studio/edit#basics');
    }

    //Upload custom theme background image
    public function themeBackground(Request $request)
    {
        $userId = Auth::user()->id;
        $littlelink_name = Auth::user()->littlelink_name;
    
        $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png,webp,gif|max:2048', // Max file size: 2MB
        ], [
            'image.required' => __('messages.Please select an image'),
            'image.image' => __('messages.The selected file must be an image'),
            'image.mimes' => __('messages.The image must be') . ' JPEG, JPG, PNG, webP, GIF.',
            'image.max' => __('messages.The image size should not exceed 2MB'),
        ]);
    
        $customBackground = $request->file('image');
    
        if ($customBackground) {
            $directory = base_path('assets/img/background-img/');
            $files = scandir($directory);
            $pathinfo = "error.error";
            foreach ($files as $file) {
                if (strpos($file, $userId . '.') !== false) {
                    $pathinfo = $userId . "." . pathinfo($file, PATHINFO_EXTENSION);
                }
            }
    
            // Delete the user's current background image if it exists
            while (findBackground($userId) !== "error.error") {
                $avatarName = "assets/img/background-img/" . findBackground(Auth::id());
                unlink(base_path($avatarName));
            }
                
            $fileName = $userId . '_' . time() . "." . $customBackground->extension();
            $customBackground->move(base_path('assets/img/background-img/'), $fileName);
    
            if (extension_loaded('imagick')) {
                $imagePath = base_path('assets/img/background-img/') . $fileName;
                $image = new \Imagick($imagePath);
                $image->stripImage();
                $image->writeImage($imagePath);
            }
    
            return redirect('/studio/theme');
        }
    
        return redirect('/studio/theme')->with('error', 'Please select a valid image file.');
    }

    //Delete custom background image
    public function removeBackground()
    {
        $userId = Auth::user()->id;

        // Delete the user's current background image if it exists
        while (findBackground($userId) !== "error.error") {
            $avatarName = "assets/img/background-img/" . findBackground(Auth::id());
            unlink(base_path($avatarName));
        }

        \App\Services\PublishedPage::markImageDirty($userId);
        return back();
    }


    //Show custom theme
    public function showTheme(request $request)
    {
        $userId = Auth::user()->id;

        $data['pages'] = User::where('id', $userId)->select('littlelink_name', 'theme')->get();

        return view('/studio/theme', $data);
    }

    //Save custom theme
    public function editTheme(request $request)
    {
        $request->validate([
            'zip' => 'sometimes|mimes:zip',
        ]);

        $userId = Auth::user()->id;

        $zipfile = $request->file('zip');

        // users.theme ends up inside an include() path and a Blade view
        // name, so it has to name a theme that is actually installed.
        // POST /studio/theme is reachable by every customer -- only the
        // zip-upload branch below is admin-gated.
        $theme = (string) $request->input('theme', '');
        if (!mm_is_valid_theme($theme)) {
            return Redirect('/studio/edit#appearance')->with('error', 'Unknown theme.');
        }
        $message = "";

        // Switching themes clears appearance overrides — picking a
        // theme means "give me that look," not "that look filtered
        // through tweaks made against a different theme." Re-selecting
        // the current theme leaves overrides alone.
        $update = ['theme' => $theme];
        if ($theme !== User::where('id', $userId)->value('theme')) {
            $update['theme_customization'] = null;
            // The uploaded background is a FILE the bio renderer keys
            // off directly (linkstack/modules/theme.blade.php checks
            // file existence, not the blob) — clearing only the blob
            // left the old photo rendering over the new theme, with
            // the Remove button hidden because the blob said there was
            // nothing to remove.
            \App\Http\Controllers\AppearanceController::removeBackgroundFileIfPresent($userId);
            \App\Services\PublishedPage::markImageDirty($userId);
        }
        User::where('id', $userId)->update($update);



        if (!empty($zipfile) && Auth::user()->role == 'admin') {

            $themesPath = base_path('themes');
            $tmpPath = base_path() . '/themes/temp.zip';
            $zipfile->move($themesPath, "temp.zip");

            $zip = new ZipArchive;
            if ($zip->open($tmpPath) !== true) {
                @unlink($tmpPath);
                return Redirect('/studio/edit#appearance')->with('error', 'Could not read the theme archive.');
            }

            // Zip Slip guard: reject the archive if ANY entry would land
            // outside themes/ (absolute path or ../ traversal) BEFORE
            // extracting anything. Without this, a crafted theme zip —
            // e.g. a community theme download — could drop a .php file
            // into a servable/autoloaded path (../../public/…, ../../
            // routes/…) which is arbitrary code execution. Legitimate
            // themes contain only "ThemeName/…" relative entries, which
            // pass untouched. Backslashes are normalized first so a
            // Windows-crafted "..\..\" can't slip past.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                if ($entry === '') {
                    continue;
                }
                if ($entry[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $entry)) {
                    $zip->close();
                    @unlink($tmpPath);
                    return Redirect('/studio/edit#appearance')
                        ->with('error', 'Theme archive contains unsafe file paths and was rejected.');
                }
            }

            $zip->extractTo($themesPath);
            $zip->close();
            unlink($tmpPath);

            // Removes version numbers from folder.

            $regex = '/[0-9.-]/';
            $files = scandir($themesPath);
            $files = array_diff($files, array('.', '..'));

            foreach ($files as $file) {

                $basename = basename($file);
                $filePath = $themesPath . '/' . $basename;

                if (!is_dir($filePath)) {
                        
                    try {
                        File::delete($filePath);
                    } catch (exception $e) {}

                }

                if (preg_match($regex, $basename)) {

                    $newBasename = preg_replace($regex, '', $basename);
                    $newPath = $themesPath . '/' . $newBasename;
                    File::copyDirectory($filePath, $newPath);
                    File::deleteDirectory($filePath);

                }

            }

        }


        // Both the customer theme-select and the admin zip-upload land on
        // the unified editor's Themes tab, where the chosen/new theme is
        // immediately visible in the grid. (item 12a)
        return Redirect('/studio/edit#appearance')->with("success", $message);
    }

    //Show user (name, email, password)
    public function showProfile(request $request)
    {
        $userId = Auth::user()->id;

        $data['profile'] = User::where('id', $userId)
            ->select('id', 'name', 'email', 'role', 'littlelink_name',
                     'stripe_account_id', 'google_analytics_id')
            ->get();

        return view('/studio/profile', $data);
    }

    //Save user (name, email, password)
    public function editProfile(request $request)
    {
        $request->validate([
            'name' => 'sometimes|required|unique:users',
            'email' => 'sometimes|required|email|unique:users',
            'password' => 'sometimes|min:8',
        ]);

        $userId = Auth::user()->id;

        $name = $request->name;
        $email = $request->email;
        $password = Hash::make($request->password);

        if ($request->name != '') {
            User::where('id', $userId)->update(['name' => $name]);
        } elseif ($request->email != '') {
            User::where('id', $userId)->update(['email' => $email]);
        } elseif ($request->password != '') {
            User::where('id', $userId)->update(['password' => $password]);
            Auth::logout();
        }
        return back();
    }

    /**
     * Save per-user Google Analytics measurement ID. Empty string
     * clears it. GA4 format only (G-XXXXXXXXXX); value is uppercased
     * on save. See resources/views/studio/partials/integration-analytics.blade.php.
     */
    public function editAnalytics(request $request)
    {
        $request->validate([
            'google_analytics_id' => ['nullable', 'string', 'max:30', 'regex:/^G-[A-Z0-9]+$/i'],
        ]);
        $gaId = trim((string) $request->input('google_analytics_id', ''));
        $gaId = $gaId === '' ? null : strtoupper($gaId);
        User::where('id', Auth::id())->update(['google_analytics_id' => $gaId]);
        return back();
    }

    //Show user theme credit page
    public function theme(request $request)
    {
        $littlelink_name = $request->littlelink;
        $id = User::select('id')->where('littlelink_name', $littlelink_name)->value('id');

        if (empty($id)) {
            return abort(404);
        }

        $userinfo = User::select('name', 'littlelink_name', 'littlelink_description', 'theme')->where('id', $id)->first();
        $information = User::select('name', 'littlelink_name', 'littlelink_description', 'theme')->where('id', $id)->get();

        $links = DB::table('links')->join('buttons', 'buttons.id', '=', 'links.button_id')->select('links.link', 'links.id', 'links.button_id', 'links.title', 'links.custom_css', 'links.custom_icon', 'buttons.name')->where('user_id', $id)->orderBy('up_link', 'asc')->orderBy('order', 'asc')->get();

        return view('components/theme', ['userinfo' => $userinfo, 'information' => $information, 'links' => $links, 'littlelink_name' => $littlelink_name]);
    }

    //Delete existing user
    // Self-serve account deletion removed: account lifecycle is owned by
    // Mail Minted billing (create on purchase, deprovision on cancel via
    // the admin API, which purges uploads). A customer self-deleting their
    // LinkStack row here would desync from billing and break SSO. The
    // /studio/delete-user route is gone; admin deletion lives in
    // AdminController::deleteUser.

    //Delete profile picture
    public function delProfilePicture()
    {
        $this->removeAvatarFileIfPresent(Auth::id());
        \App\Services\PublishedPage::markImageDirty(Auth::id());
        return back();
    }

    /**
     * Unlink the user's current avatar file(s) if they exist on disk.
     * findAvatar() consults a 1-hour directory cache, so a stale
     * reference to an already-missing file would otherwise have spun
     * the previous while-loop forever (only saved by unlink throwing
     * ErrorException). Iterate on *actual disk contents* instead.
     */
    private function removeAvatarFileIfPresent($userId): void
    {
        $dir = base_path('assets/img');
        if (!is_dir($dir)) return;
        $prefix = $userId . '_';
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            // Match files like "1_1776830642.jpg" for this user id.
            if (strpos($entry, $prefix) !== 0) continue;
            $full = $dir . '/' . $entry;
            if (is_file($full)) {
                @unlink($full);
            }
        }
    }

    /**
     * Standalone avatar upload — dedicated endpoint so the
     * Appearance editor's photo form doesn't have to carry name /
     * handle / description just to avoid clobbering them in
     * editPage(). Replaces any existing avatar.
     */
    public function editProfilePicture(Request $request)
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
        ], [
            'image.image' => __('messages.The selected file must be an image'),
            'image.mimes' => __('messages.The image must be') . ' JPEG, JPG, PNG, webP.',
            'image.max'   => __('messages.The image size should not exceed 2MB'),
        ]);

        $userId = Auth::id();
        $profilePhoto = $request->file('image');

        $this->removeAvatarFileIfPresent($userId);

        $fileName = $userId . '_' . time() . '.' . $profilePhoto->extension();
        $profilePhoto->move(base_path('assets/img'), $fileName);
        \App\Services\PublishedPage::markImageDirty($userId);

        return back()->with('success', 'Profile photo updated.');
    }

    /**
     * Upload a custom browser-tab icon (favicon) for the user's public
     * page — white-label: visitors on the customer's domain see the
     * customer's icon, not ours. Takes effect immediately (account-level
     * branding, not part of the draft/publish snapshot). Replaces any
     * existing favicon.
     */
    public function editFavicon(Request $request)
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpeg,jpg,png,webp,ico', 'max:2048'],
        ], [
            'image.mimes' => __('messages.The image must be') . ' JPEG, JPG, PNG, webP, ICO.',
            'image.max'   => __('messages.The image size should not exceed 2MB'),
        ]);

        $userId = Auth::id();
        $favicon = $request->file('image');

        $dir = base_path('assets/img/favicon-img');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $this->removeFaviconFileIfPresent($userId);

        // extension() can come back null for .ico on hosts whose MIME map
        // lacks it — fall back to the (already mimes-validated) client name.
        $ext = $favicon->extension() ?: strtolower($favicon->getClientOriginalExtension());
        $fileName = $userId . '_' . time() . '.' . $ext;
        $favicon->move($dir, $fileName);

        return back()->with('success', 'Browser tab icon updated.');
    }

    //Remove the user's custom browser-tab icon; page falls back to the site favicon
    public function removeFavicon()
    {
        $this->removeFaviconFileIfPresent(Auth::id());
        return back();
    }

    /**
     * Unlink the user's current favicon file(s) if they exist on disk.
     * Iterates actual disk contents, not findFavicon()'s cached listing
     * (see removeAvatarFileIfPresent for the stale-cache rationale).
     */
    private function removeFaviconFileIfPresent($userId): void
    {
        $dir = base_path('assets/img/favicon-img');
        if (!is_dir($dir)) return;
        $prefix = $userId . '_';
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (strpos($entry, $prefix) !== 0) continue;
            $full = $dir . '/' . $entry;
            if (is_file($full)) {
                @unlink($full);
            }
        }
    }

    //Export user links
    public function exportLinks(request $request)
    {
        $userId = Auth::id();
        $user = User::find($userId);
        $links = Link::where('user_id', $userId)->get();
        
        if (!$user) {
            // handle the case where the user is null
            return response()->json(['message' => 'User not found'], 404);
        }

        $userData['links'] = $links->toArray();

        $domain = $_SERVER['HTTP_HOST'];
        $date = date('Y-m-d_H-i-s');
        $fileName = "links-$domain-$date.json";
        $headers = [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ];
        return response()->json($userData, 200, $headers);

        return back();
    }

    //Export all user data
    public function exportAll(Request $request)
    {
        $userId = Auth::id();
        $user = User::find($userId);
        $links = Link::where('user_id', $userId)->get();
    
        if (!$user) {
            // handle the case where the user is null
            return response()->json(['message' => 'User not found'], 404);
        }
    
        $userData = $user->toArray();
        $userData['links'] = $links->toArray();

        if (file_exists(base_path(findAvatar($userId)))){
            $imagePath = base_path(findAvatar($userId));
            $imageData = base64_encode(file_get_contents($imagePath));
            $userData['image_data'] = $imageData;
    
            $imageExtension = pathinfo($imagePath, PATHINFO_EXTENSION);
            $userData['image_extension'] = $imageExtension;
        }
    
        $domain = $_SERVER['HTTP_HOST'];
        $date = date('Y-m-d_H-i-s');
        $fileName = "user_data-$domain-$date.json";
        $headers = [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ];
        return response()->json($userData, 200, $headers);
    
        return back();
    }    

    public function importData(Request $request)
    {
        try {
            // Get the JSON data from the uploaded file
            if (!$request->hasFile('import') || !$request->file('import')->isValid()) {
                throw new \Exception('File not uploaded or is faulty');
            }
            $file = $request->file('import');
            $jsonString = $file->get();
            $userData = json_decode($jsonString, true);

            if (!is_array($userData)) {
                throw new \Exception('Import file is not valid JSON');
            }

            // Update the authenticated user's profile data if defined in the JSON file
            $user = auth()->user();
            if (isset($userData['name'])) {
                // Plain text only: name is interpolated into markup in
                // places that don't escape it, and the column is 255 wide.
                $user->name = mb_substr(trim(strip_tags((string) $userData['name'])), 0, 255);
            }

            if (isset($userData['littlelink_description'])) {
                $user->littlelink_description = purify_user_html($userData['littlelink_description']);
            }

            if (isset($userData['image_data'])) {

                $allowedExtensions = array('jpeg', 'jpg', 'png', 'webp');
                $userExtension = strtolower((string) ($userData['image_extension'] ?? ''));

                if (in_array($userExtension, $allowedExtensions, true)) {
                // Decode the image data from Base64
                $imageData = base64_decode((string) $userData['image_data'], true);

                // The extension was allowlisted but the CONTENT never was,
                // so any bytes at all could be written under assets/img/ —
                // which the web server hands out directly, without passing
                // through the middleware that sets nosniff. That makes an
                // uploaded "image" usable as a same-origin script source.
                // Require the bytes to really decode as the claimed type.
                if ($imageData === false || strlen($imageData) > 2 * 1024 * 1024) {
                    throw new \Exception('Invalid image data');
                }
                $imageInfo = @getimagesizefromstring($imageData);
                $mimeExtensions = [
                    'image/jpeg' => ['jpeg', 'jpg'],
                    'image/png'  => ['png'],
                    'image/webp' => ['webp'],
                ];
                if ($imageInfo === false
                    || !isset($mimeExtensions[$imageInfo['mime']])
                    || !in_array($userExtension, $mimeExtensions[$imageInfo['mime']], true)) {
                    throw new \Exception('Invalid image data');
                }

                // Delete the user's current avatar if it exists
                while (findAvatar(Auth::id()) !== "error.error") {
                    $avatarName = findAvatar(Auth::id());
                    unlink(base_path($avatarName));
                }
                
                // Save the image to the correct path with the correct file name and extension
                $filename = $user->id . '.' . $userExtension;
                file_put_contents(base_path('assets/img/' . $filename), $imageData);
                
                // Update the user's image field with the correct file name
                $user->image = $filename;
                }
            }

            $links = $userData['links'] ?? [];
            if (!is_array($links)) {
                throw new \Exception('Import file has no valid links list');
            }

            // Real rows carry an empty type for legacy / predefined blocks,
            // so '' stays allowed; anything else has to name a real block.
            $allowedTypes = LinkType::get()->pluck('typename')->filter()->all();

            // The old flow saved the user, deleted every link, and only then
            // started importing — so anything that threw part-way through
            // (an invalid URL, a duplicate name) left the account with its
            // links already gone. Commit or roll back as one unit.
            DB::transaction(function () use ($user, $links, $allowedTypes) {

            $user->save();

            // Delete all links for the authenticated user
            Link::where('user_id', $user->id)->delete();

            // Loop through each link in $userData and create a new link for the user
            foreach ($links as $linkData) {

                if (!is_array($linkData)) {
                    throw new \Exception('Invalid link');
                }

                $validatedData = Validator::make($linkData, [
                    'link' => 'nullable|exturl',
                ]);

                if ($validatedData->fails()) {
                    throw new \Exception('Invalid link');
                }

                $newLink = new Link();

                // The template a block renders through is chosen by `type`
                // (see linkstack/elements/buttons.blade.php), so `type` has
                // to be a real block — an import must not be able to name
                // an arbitrary one.
                $type = (string) ($linkData['type'] ?? '');
                if ($type !== '' && !in_array($type, $allowedTypes, true)) {
                    throw new \Exception('Invalid block type');
                }

                // Copy over the link data from $linkData to $newLink
                $newLink->button_id = (int) ($linkData['button_id'] ?? 1);
                $newLink->link = $linkData['link'] ?? '';

                // Sanitize the title. This gate keys off the SAME thing that
                // selects the template: blocks/text/display.blade.php is the
                // one that echoes the title unescaped (under ALLOW_USER_HTML),
                // and it is reached via type "text". Keying off button_id
                // instead — as this did — meant an import declaring
                // button_id != 93 with type "text" skipped the purifier and
                // landed raw HTML on a public page. button_id 93 is still
                // honoured because that is what the text handler writes.
                $isTextBlock = ($type === 'text') || ((int) ($linkData['button_id'] ?? 0) === 93);
                $newLink->title = $isTextBlock
                    ? purify_user_html((string) ($linkData['title'] ?? ''))
                    : strip_tags((string) ($linkData['title'] ?? ''));

                $newLink->order = (int) ($linkData['order'] ?? 0);
                $newLink->click_number = 0;
                $newLink->up_link = (int) ($linkData['up_link'] ?? 0);
                // Same sanitizers the normal save path applies (saveLink);
                // the import used to store both verbatim, so a crafted
                // custom_css could close the generated rule and @import an
                // attacker stylesheet onto the public page.
                $newLink->custom_css = mm_sanitize_block_css((string) ($linkData['custom_css'] ?? ''));
                $newLink->custom_icon = preg_replace(
                    '/[^a-zA-Z0-9 _-]/', '', (string) ($linkData['custom_icon'] ?? '')
                );
                $newLink->type = $type;

                // type_params carries the render-control flags, and
                // custom_html is what makes buttons.blade.php dispatch to
                // blocks::<type>.display at all. Those are properties of the
                // block type, not of the imported file — re-derive them.
                $typeParams = json_decode((string) ($linkData['type_params'] ?? '{}'), true);
                $typeParams = is_array($typeParams) ? $typeParams : [];
                unset(
                    $typeParams['custom_html'],
                    $typeParams['ignore_container'],
                    $typeParams['include_libraries']
                );
                if ($linkType = LinkType::findByTypename($type)) {
                    $typeParams['custom_html']       = $linkType->custom_html;
                    $typeParams['ignore_container']  = $linkType->ignore_container;
                    $typeParams['include_libraries'] = $linkType->include_libraries;
                }
                $newLink->type_params = json_encode($typeParams);
    
                // Set the user ID to the current user's ID
                $newLink->user_id = $user->id;

                // Save the new link to the database
                $newLink->save();
            }

            });

            return redirect('studio/profile')->with('success', __('messages.Profile updated successfully!'));
        } catch (\Exception $e) {
            return redirect('studio/profile')->with('error', __('messages.An error occurred while updating your profile.'));
        }
    }
    

    // Hanle reports
    function report(Request $request)
    {
        $formData = $request->all();
    
        try {
            Mail::to(env('ADMIN_EMAIL'))->send(new ReportSubmissionMail($formData));
            
            return redirect('report')->with('success', __('messages.report_success'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('messages.report_error'));
        }
    }

    //Edit/save page icons
    /**
     * Brand → URL-prefix map for the Social Icons page. When a user
     * types just a handle ("jameskoch") into one of these brand
     * inputs, we prepend the base URL on save. Brands NOT in this
     * map keep the legacy "paste a full URL" behaviour — used for
     * brands with non-standard identifier formats (Mastodon =
     * federated, WhatsApp = phone, Discord = invite code, Bluesky =
     * handle-is-a-domain).
     */
    private function iconBaseUrls(): array
    {
        return [
            'facebook'  => 'https://facebook.com/',
            'instagram' => 'https://instagram.com/',
            'x-twitter' => 'https://x.com/',
            'github'    => 'https://github.com/',
            'twitch'    => 'https://twitch.tv/',
            'linkedin'  => 'https://linkedin.com/in/',
            'tiktok'    => 'https://tiktok.com/@',
            'youtube'   => 'https://youtube.com/@',
            'threads'   => 'https://threads.net/@',
            'pinterest' => 'https://pinterest.com/',
            'snapchat'  => 'https://snapchat.com/add/',
            'reddit'    => 'https://reddit.com/user/',
            'telegram'  => 'https://t.me/',
            'behance'   => 'https://behance.net/',
            'dribbble'  => 'https://dribbble.com/',
        ];
    }

    /**
     * Normalizes whatever the user typed into a Social Icons input
     * to a full URL. Three input shapes accepted:
     *   - empty                  → empty (no save)
     *   - "https://…"            → save as-is (escape hatch for
     *                              non-standard profile URLs)
     *   - "jameskoch" / "@jameskoch" / "user/jameskoch" etc. → prepend
     *                              the brand's base URL from iconBaseUrls()
     * Brands not in the map and not starting with http(s):// pass
     * through unchanged (legacy paste-anything behaviour).
     */
    private function normalizeIconUrl(string $platform, ?string $value): string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }
        // Strip a leading @ that users habitually add (TikTok, YouTube, …).
        $value = ltrim($value, '@');
        if ($value === '') {
            return '';
        }
        $bases = $this->iconBaseUrls();
        if (isset($bases[$platform])) {
            return $bases[$platform] . $value;
        }
        return $value;
    }

    public function editIcons(Request $request)
    {
        $inputKeys = array_keys($request->except('_token'));

        // Validate length only at this stage — exturl can't apply since
        // some inputs may now arrive as bare handles. Server-side
        // normalization (normalizeIconUrl) converts to full URL before
        // saving; the database stores only valid URLs as a result.
        $validationRules = [];
        foreach ($inputKeys as $platform) {
            $validationRules[$platform] = 'nullable|string|max:255';
        }
        $request->validate($validationRules);

        foreach ($inputKeys as $platform) {
            $link = $this->normalizeIconUrl($platform, $request->input($platform));

            if ($link !== '') {
                $iconId = $this->searchIcon($platform);
                if (!is_null($iconId)) {
                    $this->updateIcon($platform, $link);
                } else {
                    $this->addIcon($platform, $link);
                }
            } else {
                // Submitted blank for an existing icon → delete the
                // row so the public page stops rendering it.
                $iconId = $this->searchIcon($platform);
                if (!is_null($iconId)) {
                    Link::where('id', $iconId)->delete();
                }
            }
        }

        return redirect('/studio/edit#social')->with('success', 'Social icons updated.');
    }

    private function searchIcon($icon)
    {
        return DB::table('links')
            ->where('user_id', Auth::id())
            ->where('title', $icon)
            ->where('button_id', 94)
            ->value('id');
    }

    private function addIcon($icon, $link)
    {
        $userId = Auth::user()->id;
        $links = new Link;
        $links->link = $link;
        $links->user_id = $userId;
        $links->title = $icon;
        $links->button_id = '94';
        $links->save();
        $links->order = ($links->id - 1);
        $links->save();
    }

    private function updateIcon($icon, $link)
    {
        Link::where('id', $this->searchIcon($icon))->update([
            'button_id' => 94,
            'link' => $link,
            'title' => $icon
        ]);
    }
}