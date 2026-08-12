<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use GeoSot\EnvEditor\Controllers\EnvController;
use GeoSot\EnvEditor\Exceptions\EnvException;
use GeoSot\EnvEditor\Helpers\EnvFileContentManager;
use GeoSot\EnvEditor\Helpers\EnvFilesManager;
use GeoSot\EnvEditor\Helpers\EnvKeysManager;
use GeoSot\EnvEditor\Facades\EnvEditor;
use GeoSot\EnvEditor\ServiceProvider;

use Auth;
use Exception;
use ZipArchive;
use Carbon\Carbon;

use App\Models\User;
use App\Models\Admin;
use App\Models\Button;
use App\Models\Link;
use App\Models\Page;
use App\Models\UserData;

class AdminController extends Controller
{
  //Statistics of the number of clicks and links
  public function index()
  {
    $userId = Auth::user()->id;
    $littlelink_name = Auth::user()->littlelink_name;
    $links = Link::where("user_id", $userId)->select("link")->count();
    $clicks = Link::where("user_id", $userId)->sum("click_number");

    $userNumber = User::count();
    $siteLinks = Link::count();
    $siteClicks = Link::sum("click_number");

    $users = User::select(
      "id",
      "name",
      "email",
      "created_at",
      "updated_at",
    )->get();
    $lastMonthCount = $users
      ->where("created_at", ">=", Carbon::now()->subDays(30))
      ->count();
    $lastWeekCount = $users
      ->where("created_at", ">=", Carbon::now()->subDays(7))
      ->count();
    $last24HrsCount = $users
      ->where("created_at", ">=", Carbon::now()->subHours(24))
      ->count();
    $updatedLast30DaysCount = $users
      ->where("updated_at", ">=", Carbon::now()->subDays(30))
      ->count();
    $updatedLast7DaysCount = $users
      ->where("updated_at", ">=", Carbon::now()->subDays(7))
      ->count();
    $updatedLast24HrsCount = $users
      ->where("updated_at", ">=", Carbon::now()->subHours(24))
      ->count();

    $links = Link::where("user_id", $userId)->select("link")->count();
    $clicks = Link::where("user_id", $userId)->sum("click_number");
    $topLinks = Link::where("user_id", $userId)
      ->orderby("click_number", "desc")
      ->whereNotNull("link")
      ->where("link", "<>", "")
      ->take(5)
      ->get();

    $pageStats = [
      "visitors" => [
        "all" => visits("App\Models\User", $littlelink_name)->count(),
        "day" => visits("App\Models\User", $littlelink_name)
          ->period("day")
          ->count(),
        "week" => visits("App\Models\User", $littlelink_name)
          ->period("week")
          ->count(),
        "month" => visits("App\Models\User", $littlelink_name)
          ->period("month")
          ->count(),
        "year" => visits("App\Models\User", $littlelink_name)
          ->period("year")
          ->count(),
      ],
      "os" => visits("App\Models\User", $littlelink_name)->operatingSystems(),
      "referers" => visits("App\Models\User", $littlelink_name)->refs(),
      "countries" => visits("App\Models\User", $littlelink_name)->countries(),
    ];

    return view("panel/index", [
      "lastMonthCount" => $lastMonthCount,
      "lastWeekCount" => $lastWeekCount,
      "last24HrsCount" => $last24HrsCount,
      "updatedLast30DaysCount" => $updatedLast30DaysCount,
      "updatedLast7DaysCount" => $updatedLast7DaysCount,
      "updatedLast24HrsCount" => $updatedLast24HrsCount,
      "toplinks" => $topLinks,
      "links" => $links,
      "clicks" => $clicks,
      "pageStats" => $pageStats,
      "littlelink_name" => $littlelink_name,
      "links" => $links,
      "clicks" => $clicks,
      "siteLinks" => $siteLinks,
      "siteClicks" => $siteClicks,
      "userNumber" => $userNumber,
    ]);
  }

  // Users page
  public function users()
  {
    return view("panel/users");
  }

  // Send test mail
  public function SendTestMail(Request $request)
  {
    try {
      $userId = auth()->id();
      $user = User::findOrFail($userId);

      Mail::send("auth.test", ["user" => $user], function ($message) use (
        $user,
      ) {
        $message->to($user->email)->subject("Test Email");
      });

      return redirect()
        ->route("showConfig")
        ->with("success", "Test email sent successfully!");
    } catch (\Exception $e) {
      return redirect()
        ->route("showConfig")
        ->with("fail", "Failed to send test email.");
    }
  }

  //Block user
  public function blockUser(request $request)
  {
    $id = $request->id;
    $status = $request->block;

    if ($status == "yes") {
      $block = "no";
    } elseif ($status == "no") {
      $block = "yes";
    }

    User::where("id", $id)->update(["block" => $block]);

    return redirect("admin/users/all");
  }

  //Verify user
  public function verifyCheckUser(request $request)
  {
    $id = $request->id;
    $status = $request->verify;

    if ($status == "vip") {
      $verify = "vip";
      UserData::saveData($id, "checkmark", true);
    } elseif ($status == "user") {
      $verify = "user";
    }

    User::where("id", $id)->update(["role" => $verify]);

    return redirect(url("u") . "/" . $id);
  }

  //Verify or un-verify users emails
  public function verifyUser(request $request)
  {
    $id = $request->id;
    $status = $request->verify;

    if ($status == "true") {
      $verify = "0000-00-00 00:00:00";
    } else {
      $verify = null;
    }

    User::where("id", $id)->update(["email_verified_at" => $verify]);
  }

  //Create new user from the Admin Panel
  public function createNewUser()
  {
    function random_str(
      int $length = 64,
      string $keyspace = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ",
    ): string {
      if ($length < 1) {
        throw new \RangeException("Length must be a positive integer");
      }
      $pieces = [];
      $max = mb_strlen($keyspace, "8bit") - 1;
      for ($i = 0; $i < $length; ++$i) {
        $pieces[] = $keyspace[random_int(0, $max)];
      }
      return implode("", $pieces);
    }

    $names = User::pluck("name")->toArray();

    $adminCreatedNames = array_filter($names, function ($name) {
      return strpos($name, "Admin-Created-") === 0;
    });

    $numbers = array_map(function ($name) {
      return (int) str_replace("Admin-Created-", "", $name);
    }, $adminCreatedNames);

    $maxNumber = !empty($numbers) ? max($numbers) : 0;
    $newNumber = $maxNumber + 1;

    $domain = parse_url(url(""), PHP_URL_HOST);
    $domain = $domain == "localhost" ? "example.com" : $domain;

    $user = User::create([
      "name" => "Admin-Created-" . $newNumber,
      "email" => strtolower(random_str(8)) . "@" . $domain,
      "password" => Hash::make(random_str(32)),
      "role" => "user",
      "block" => "no",
    ]);

    return redirect("admin/edit-user/" . $user->id);
  }

  //Delete existing user
  public function deleteUser(request $request)
  {
    $id = $request->id;

    Link::where("user_id", $id)->delete();

    Schema::disableForeignKeyConstraints();

    $user = User::find($id);
    $user->forceDelete();

    Schema::enableForeignKeyConstraints();

    // Remove the user's uploaded avatar + background so deleted
    // accounts don't leave orphaned image files on disk.
    purge_user_uploads($id);

    return redirect("admin/users/all");
  }

  //Delete existing user with POST request
  public function deleteTableUser(request $request)
  {
    $id = $request->id;

    Link::where("user_id", $id)->delete();

    Schema::disableForeignKeyConstraints();

    $user = User::find($id);
    $user->forceDelete();

    Schema::enableForeignKeyConstraints();

    // Remove the user's uploaded avatar + background (see deleteUser).
    purge_user_uploads($id);
  }

  //Show user to edit
  public function showUser(request $request)
  {
    $id = $request->id;

    $data["user"] = User::where("id", $id)->get();

    return view("panel/edit-user", $data);
  }

  //Show link, click number, up link in links page
  public function showLinksUser(request $request)
  {
    $id = $request->id;

    $data["user"] = User::where("id", $id)->get();

    $data["links"] = Link::select(
      "id",
      "link",
      "title",
      "order",
      "click_number",
      "up_link",
      "links.button_id",
    )
      ->where("user_id", $id)
      ->orderBy("up_link", "asc")
      ->orderBy("order", "asc")
      ->paginate(10);
    return view("panel/links", $data);
  }

  //Delete link
  public function deleteLinkUser(request $request)
  {
    $linkId = $request->id;

    Link::where("id", $linkId)->delete();

    return back();
  }

  //Save user edit
  public function editUser(request $request)
  {
    // The two uploads had NO rules at all — any file, any size. Same
    // safety net as the customer paths (the admin page resizes in the
    // browser first, so a >2MB arrival means that was bypassed).
    $request->validate([
      "name" => "",
      "email" => "",
      "password" => "",
      "littlelink_name" => "",
      "image"      => ["nullable", "image", "mimes:jpeg,jpg,png,webp", "max:2048"],
      "background" => ["nullable", "image", "mimes:jpeg,jpg,png,webp", "max:2048"],
    ], [
      "image.image"      => __("messages.The selected file must be an image"),
      "image.mimes"      => __("messages.The image must be") . " JPEG, JPG, PNG, webP.",
      "image.max"        => __("messages.The image size should not exceed 2MB"),
      "background.image" => __("messages.The selected file must be an image"),
      "background.mimes" => __("messages.The image must be") . " JPEG, JPG, PNG, webP.",
      "background.max"   => __("messages.The image size should not exceed 2MB"),
    ]);

    $id = $request->id;
    $name = $request->name;
    $email = $request->email;
    $password = Hash::make($request->password);
    $profilePhoto = $request->file("image");
    $littlelink_name = $request->littlelink_name;
    $littlelink_description = $request->littlelink_description;
    $role = $request->role;
    $customBackground = $request->file("background");
    // Same constraint as UserController::editTheme -- users.theme reaches
    // an include() path, so it must name an installed theme.
    $theme = (string) $request->input("theme", "");
    if (!mm_is_valid_theme($theme)) {
      return Redirect("/admin/edit-user/" . $id)->with("error", "Unknown theme.");
    }

    if (User::where("id", $id)->get("role")->first()->role = !$role) {
      if ($role == "vip") {
        UserData::saveData($id, "checkmark", true);
      }
    }

    if ($request->password == "") {
      User::where("id", $id)->update([
        "name" => $name,
        "email" => $email,
        "littlelink_name" => $littlelink_name,
        "littlelink_description" => $littlelink_description,
        "role" => $role,
        "theme" => $theme,
      ]);
    } else {
      User::where("id", $id)->update([
        "name" => $name,
        "email" => $email,
        "password" => $password,
        "littlelink_name" => $littlelink_name,
        "littlelink_description" => $littlelink_description,
        "role" => $role,
        "theme" => $theme,
      ]);
    }
    if (!empty($profilePhoto)) {
      $profilePhoto->move(base_path("assets/img"), $id . "_" . time() . ".png");
    }
    if (!empty($customBackground)) {
      // Both-or-neither invariant (same as the customer uploader): the
      // bio renderer keys off FILE existence, the Appearance UI off the
      // sparse blob — writing only the file left the customer's own
      // Background pill claiming "theme's own" with no Remove button.
      // Clean every old file (legacy {id}.ext and {id}_time.ext names),
      // write the new one, and record the override in the blob.
      \App\Http\Controllers\AppearanceController::removeBackgroundFileIfPresent($id);

      $fileName = $id . "_" . time() . "." . $customBackground->extension();
      $customBackground->move(base_path("assets/img/background-img/"), $fileName);

      $targetUser = User::find($id);
      if ($targetUser) {
        $sparse = \App\Http\Controllers\AppearanceController::sparseForUser($targetUser);
        $sparse["background"] = [
          "type"      => "image",
          "image_url" => "/assets/img/background-img/" . $fileName,
        ];
        $targetUser->theme_customization = json_encode($sparse, JSON_UNESCAPED_SLASHES);
        $targetUser->save();
      }
    }

    return redirect("admin/users/all");
  }

  //Show site pages to edit
  public function showSitePage()
  {
    $data["pages"] = Page::select(
      "terms",
      "privacy",
      "contact",
      "register",
    )->get();
    return view("panel/pages", $data);
  }

  //Save site pages
  public function editSitePage(request $request)
  {
    $terms = $request->terms;
    $privacy = $request->privacy;
    $contact = $request->contact;
    $register = $request->register;

    Page::first()->update([
      "terms" => $terms,
      "privacy" => $privacy,
      "contact" => $contact,
      "register" => $register,
    ]);

    return back();
  }

  //Show home message for edit
  public function showSite()
  {
    $message = Page::select("home_message")->first();
    return view("panel/site", $message);
  }

  //Save home message, logo and favicon
  public function editSite(request $request)
  {
    // These two uploads had NO rules at all, unlike every other uploader
    // in the app. The stored extension comes from UploadedFile::extension(),
    // which guesses from content -- so HTML content yielded a .html file and
    // SVG content a .svg, written into assets/linkstack/images/ and served
    // straight off disk as same-origin markup, bypassing the CSP and nosniff
    // headers that only apply to responses Laravel renders.
    $request->validate([
      "message" => ["nullable", "string"],
      "image"   => ["nullable", "image", "mimes:jpeg,jpg,png,webp", "max:2048"],
      // 'file' not 'image' for the icon: Laravel's image rule rejects ICO,
      // which is a legitimate favicon format. Matches editFavicon.
      "icon"    => ["nullable", "file", "mimes:jpeg,jpg,png,webp,ico", "max:1024"],
    ]);

    $message = $request->message;
    $logo = $request->file("image");
    $icon = $request->file("icon");

    Page::first()->update(["home_message" => $message]);

    if (!empty($logo)) {
      // Delete existing image
      $path = findFile("avatar");
      $path = base_path("/assets/linkstack/images/" . $path);

      // Delete existing image
      if (File::exists($path)) {
        File::delete($path);
      }

      $logo->move(
        base_path("/assets/linkstack/images/"),
        "avatar" . "_" . time() . "." . $request->file("image")->extension(),
      );
    }

    if (!empty($icon)) {
      // Delete existing image
      $path = findFile("favicon");
      $path = base_path("/assets/linkstack/images/" . $path);

      // Delete existing image
      if (File::exists($path)) {
        File::delete($path);
      }

      $icon->move(
        base_path("/assets/linkstack/images/"),
        "favicon" . "_" . time() . "." . $request->file("icon")->extension(),
      );
    }
    return back();
  }

  //Delete avatar
  public function delAvatar()
  {
    $path = findFile("avatar");
    $path = base_path("/assets/linkstack/images/" . $path);

    // Delete existing image
    if (File::exists($path)) {
      File::delete($path);
    }

    return back();
  }

  //Delete favicon
  public function delFavicon()
  {
    // Delete existing image
    $path = findFile("favicon");
    $path = base_path("/assets/linkstack/images/" . $path);

    // Delete existing image
    if (File::exists($path)) {
      File::delete($path);
    }

    return back();
  }

  //View footer page: terms
  public function pagesTerms(Request $request)
  {
    $name = "terms";

    try {
      $data["page"] = Page::select($name)->first();
    } catch (Exception $e) {
      return abort(404);
    }

    return view("pages", ["data" => $data, "name" => $name]);
  }

  //View footer page: privacy
  public function pagesPrivacy(Request $request)
  {
    $name = "privacy";

    try {
      $data["page"] = Page::select($name)->first();
    } catch (Exception $e) {
      return abort(404);
    }

    return view("pages", ["data" => $data, "name" => $name]);
  }

  //View footer page: contact
  public function pagesContact(Request $request)
  {
    $name = "contact";

    try {
      $data["page"] = Page::select($name)->first();
    } catch (Exception $e) {
      return abort(404);
    }

    return view("pages", ["data" => $data, "name" => $name]);
  }

  //Statistics of the number of clicks and links
  public function phpinfo()
  {
    return view("panel/phpinfo");
  }

  //Shows config file editor page
  public function showFileEditor(request $request)
  {
    return redirect("/admin/config");
  }

  //Saves advanced config
  public function editAC(request $request)
  {
    if ($request->ResetAdvancedConfig == "RESET_DEFAULTS") {
      copy(
        base_path("storage/templates/advanced-config.php"),
        base_path("config/advanced-config.php"),
      );
    } else {
      file_put_contents("config/advanced-config.php", $request->AdvancedConfig);
    }

    return redirect("/admin/config#2");
  }

  //Saves .env config
  public function editENV(request $request)
  {
    $config = $request->altConfig;

    file_put_contents(".env", $config);

    return Redirect("/admin/config?alternative-config");
  }

  //Shows config file editor page
  public function showBackups(request $request)
  {
    return view("/panel/backups");
  }

  //Delete custom theme
  public function deleteTheme(request $request)
  {
    $del = $request->deltheme;

    if (empty($del)) {
      echo '<script type="text/javascript">';
      echo 'alert("No themes to delete!");';
      echo 'window.location.href = "../studio/theme";';
      echo "</script>";
    } else {
      // basename + containment check: $del went straight into the path, so
      // "../app", "../storage" or ".." recursively deleted that tree
      // instead of a theme. assets/img in particular is the only copy of
      // every customer's uploads on the persistent volume.
      $del = basename((string) $del);
      $themesRoot = realpath(base_path("themes"));
      $folderName = base_path("themes/" . $del);
      $resolved = realpath($folderName);

      if (
        $del === "" || $del === "." || $del === ".." ||
        $themesRoot === false || $resolved === false ||
        strpos($resolved, $themesRoot . DIRECTORY_SEPARATOR) !== 0 ||
        !is_dir($resolved)
      ) {
        return Redirect("/admin/theme")->with("error", "Invalid theme.");
      }

      File::deleteDirectory($resolved);

      return Redirect("/admin/theme");
    }
  }

  // Update themes
  public function updateThemes()
  {
    if ($handle = opendir("themes")) {
      while (false !== ($entry = readdir($handle))) {
        if (file_exists(base_path("themes") . "/" . $entry . "/readme.md")) {
          $text = file_get_contents(
            base_path("themes") . "/" . $entry . "/readme.md",
          );
          $pattern = "/Theme Version:.*/";
          preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
          if (!count($matches)) {
            continue;
          }
          $verNr = substr($matches[0][0], 15);
        }

        $themeVe = null;

        if ($entry != "." && $entry != "..") {
          if (file_exists(base_path("themes") . "/" . $entry . "/readme.md")) {
            if (
              !strpos(
                file_get_contents(
                  base_path("themes") . "/" . $entry . "/readme.md",
                ),
                "Source code:",
              )
            ) {
              $hasSource = false;
            } else {
              $hasSource = true;

              $text = file_get_contents(
                base_path("themes") . "/" . $entry . "/readme.md",
              );
              $pattern = "/Source code:.*/";
              preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
              $sourceURL = substr($matches[0][0], 13);

              // readme.md ships with the theme, so this URL is untrusted
              // input. mm_theme_source_repo() parses the host rather than
              // substring-matching it, and only accepts an https github.com
              // owner/repo URL.
              $repoUrl = mm_theme_source_repo($sourceURL);

              if ($repoUrl !== null) {
                $replaced = str_replace(
                  "https://github.com/",
                  "https://raw.githubusercontent.com/",
                  $repoUrl,
                ) . "/main/readme.md";

                try {
                  $textGit = Http::timeout(15)->get($replaced)->body();
                  $patternGit = "/Theme Version:.*/";
                  preg_match(
                    $patternGit,
                    $textGit,
                    $matches,
                    PREG_OFFSET_CAPTURE,
                  );
                  $sourceURLGit = substr($matches[0][0], 15);
                  $Vgitt = "v" . $sourceURLGit;
                  $verNrv = "v" . $verNr;
                } catch (\Throwable $ex) {
                  $themeVe = "error";
                  $Vgitt = null;
                  $verNrv = null;
                }

                // version_compare, not a string comparison: "v9" > "v10"
                // lexically, so a 10.x release never looked like an update.
                // The tag also has to be a plausible version so it can't
                // steer the archive path.
                $remoteTag = trim((string) $Vgitt);
                $localTag = trim((string) $verNrv);
                $tagIsSane = (bool) preg_match('/^v[0-9][0-9A-Za-z.\-_]*$/', $remoteTag);

                if (
                  $tagIsSane &&
                  version_compare(ltrim($remoteTag, "v"), ltrim($localTag, "v"), ">")
                ) {
                  $fileUrl = $repoUrl . "/archive/refs/tags/" . $remoteTag . ".zip";
                  $zipPath = base_path("themes/theme.zip");

                  $download = Http::timeout(60)->get($fileUrl);
                  if (!$download->successful()) {
                    continue;
                  }
                  file_put_contents($zipPath, $download->body());

                  $zip = new ZipArchive();
                  if ($zip->open($zipPath) !== true) {
                    @unlink($zipPath);
                    continue;
                  }

                  // Zip Slip guard. Without it a crafted archive could
                  // write outside themes/ -- into a servable or autoloaded
                  // path -- which is arbitrary code execution.
                  if (!mm_zip_entries_are_safe($zip)) {
                    $zip->close();
                    @unlink($zipPath);
                    Log::warning('Theme update rejected: unsafe archive paths', [
                      'theme' => $entry,
                      'url'   => $fileUrl,
                    ]);
                    continue;
                  }

                  $zip->extractTo(base_path("themes"));
                  $zip->close();
                  unlink($zipPath);

                  $folder = base_path("themes");
                  $regex = "/[0-9.-]/";
                  $files = scandir($folder);

                  foreach ($files as $file) {
                    if ($file !== "." && $file !== "..") {
                      if (preg_match($regex, $file)) {
                        $new_file = preg_replace($regex, "", $file);
                        File::copyDirectory(
                          $folder . "/" . $file,
                          $folder . "/" . $new_file,
                        );
                        $dirname = $folder . "/" . $file;
                        if (strtoupper(substr(PHP_OS, 0, 3)) === "WIN") {
                          system(
                            "rmdir " . escapeshellarg($dirname) . " /s /q",
                          );
                        } else {
                          system("rm -rf " . escapeshellarg($dirname));
                        }
                      }
                    }
                  }
                }
              }
            }
          }
        }
      }
    }

    return Redirect("/studio/theme");
  }

  //Shows config file editor page
  public function showConfig(request $request)
  {
    return view("/panel/config-editor");
  }

  //Shows config file editor page
  public function editConfig(request $request)
  {
    $type = $request->type;
    $entry = $request->entry;
    $value = $request->value;

    if ($type === "toggle") {
      if ($request->toggle != "") {
        $value = "true";
      } else {
        $value = "false";
      }
      if (EnvEditor::keyExists($entry)) {
        EnvEditor::editKey($entry, $value);
      }
    } elseif ($type === "toggle2") {
      if ($request->toggle != "") {
        $value = "verified";
      } else {
        $value = "auth";
      }
      if (EnvEditor::keyExists($entry)) {
        EnvEditor::editKey($entry, $value);
      }
    } elseif ($type === "text") {
      if (EnvEditor::keyExists($entry)) {
        EnvEditor::editKey($entry, '"' . $value . '"');
      } else {
        EnvEditor::addKey($entry, '"' . $value . '"');
      }
    } elseif ($type === "debug") {
      if ($request->toggle != "") {
        if (EnvEditor::keyExists("APP_DEBUG")) {
          EnvEditor::editKey("APP_DEBUG", "true");
        }
        if (EnvEditor::keyExists("APP_ENV")) {
          EnvEditor::editKey("APP_ENV", "local");
        }
        if (EnvEditor::keyExists("LOG_LEVEL")) {
          EnvEditor::editKey("LOG_LEVEL", "debug");
        }
      } else {
        if (EnvEditor::keyExists("APP_DEBUG")) {
          EnvEditor::editKey("APP_DEBUG", "false");
        }
        if (EnvEditor::keyExists("APP_ENV")) {
          EnvEditor::editKey("APP_ENV", "production");
        }
        if (EnvEditor::keyExists("LOG_LEVEL")) {
          EnvEditor::editKey("LOG_LEVEL", "error");
        }
      }
    } elseif ($type === "register") {
      if ($request->toggle != "") {
        $register = "true";
      } else {
        $register = "false";
      }
      Page::first()->update(["register" => $register]);
    } elseif ($type === "smtp") {
      if ($request->toggle != "") {
        $value = "built-in";
      } else {
        $value = "smtp";
      }
      if (EnvEditor::keyExists("MAIL_MAILER")) {
        EnvEditor::editKey("MAIL_MAILER", $value);
      }

      if (EnvEditor::keyExists("MAIL_HOST")) {
        EnvEditor::editKey("MAIL_HOST", $request->MAIL_HOST);
      }
      if (EnvEditor::keyExists("MAIL_PORT")) {
        EnvEditor::editKey("MAIL_PORT", $request->MAIL_PORT);
      }
      if (EnvEditor::keyExists("MAIL_USERNAME")) {
        EnvEditor::editKey(
          "MAIL_USERNAME",
          '"' . $request->MAIL_USERNAME . '"',
        );
      }
      if (EnvEditor::keyExists("MAIL_PASSWORD")) {
        EnvEditor::editKey(
          "MAIL_PASSWORD",
          '"' . $request->MAIL_PASSWORD . '"',
        );
      }
      if (EnvEditor::keyExists("MAIL_ENCRYPTION")) {
        EnvEditor::editKey("MAIL_ENCRYPTION", $request->MAIL_ENCRYPTION);
      }
      if (EnvEditor::keyExists("MAIL_FROM_ADDRESS")) {
        EnvEditor::editKey("MAIL_FROM_ADDRESS", $request->MAIL_FROM_ADDRESS);
      }
    } elseif ($type === "homeurl") {
      if ($request->value == "default") {
        $value = "";
      } else {
        $value = '"' . $request->value . '"';
      }
      if (EnvEditor::keyExists($entry)) {
        EnvEditor::editKey($entry, $value);
      }
    } elseif ($type === "maintenance") {
      if ($request->toggle != "") {
        $value = "true";
      } else {
        $value = "false";
      }
      if (file_exists(base_path("storage/MAINTENANCE"))) {
        unlink(base_path("storage/MAINTENANCE"));
      }
      if (EnvEditor::keyExists($entry)) {
        EnvEditor::editKey($entry, $value);
      }
    } else {
      if (EnvEditor::keyExists($entry)) {
        EnvEditor::editKey($entry, $value);
      }
    }

    return Redirect("/admin/config");
  }

  //Shows theme editor page
  public function showThemes(request $request)
  {
    return view("/panel/theme");
  }

  //Removes impersonation if authenticated
  public function authAs(Request $request)
  {
    // Who to return to comes from the SESSION, never from the request. The
    // old version took an id and a token out of the request body and, on a
    // match, called Auth::loginUsingId() on that id -- an arbitrary-identity
    // login whose only guard was a hand-rolled comparison in this method.
    $impersonatorId = $request->session()->get("impersonator_id");
    $impersonator = $impersonatorId ? User::find($impersonatorId) : null;

    if (!$impersonator || $impersonator->role !== "admin") {
      // Nothing to return to. Drop the claim and the session with it.
      $request->session()->forget("impersonator_id");
      Auth::logout();
      $request->session()->invalidate();
      $request->session()->regenerateToken();
      return redirect("/login");
    }

    Auth::login($impersonator);
    $request->session()->regenerate();
    $request->session()->forget("impersonator_id");

    return redirect("/admin/users/all");
  }

  //Add impersonation
  public function authAsID(request $request)
  {
    $admin = Auth::user();
    $target = User::find($request->id);

    // Previously gated on "does ANY admin row have auth_as set", which made
    // impersonation a single global slot: one admin using it locked every
    // other admin out of the feature. It is per-session now, so several
    // admins can impersonate at once without interfering.
    if (!$target || (int) $target->id === (int) $admin->id || $target->role === "admin") {
      return redirect("admin/users/all");
    }

    Auth::login($target);
    // New session id on an identity change, then record who to come back
    // to. regenerate() carries session data across, so the order is safe.
    $request->session()->regenerate();
    $request->session()->put("impersonator_id", $admin->id);

    return redirect("dashboard");
  }

  //Show info about link
  public function redirectInfo(request $request)
  {
    $linkId = $request->id;

    if (empty($linkId)) {
      return abort(404);
    }

    $linkData = Link::find($linkId);
    $clicks = $linkData->click_number;

    if (empty($linkData)) {
      return abort(404);
    }

    function isValidLink($url)
    {
      $validPrefixes = ["http", "https", "ftp", "mailto", "tel", "news"];

      $pattern = "/^(" . implode("|", $validPrefixes) . "):/i";

      if (preg_match($pattern, $url) && strlen($url) <= 155) {
        return $url;
      } else {
        return "N/A";
      }
    }

    $link = isValidLink($linkData->link);

    $userID = $linkData->user_id;
    $userData = User::find($userID);

    return view("linkinfo", [
      "clicks" => $clicks,
      "linkID" => $linkId,
      "link" => $link,
      "id" => $userID,
      "userData" => $userData,
    ]);
  }
}
