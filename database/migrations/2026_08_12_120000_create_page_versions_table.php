<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Instant-live + version history (supersedes draft/publish).
 *
 * The public page now always renders the live DB rows; safety comes
 * from restorable point-in-time versions instead of a staged draft.
 * Each row is one snapshot in the PageVersions::serialize() shape.
 *
 * Seeds one version per user from their old published_snapshot so the
 * last published state stays restorable. The now-dead users columns
 * (published_snapshot, has_unpublished_changes) are left in place —
 * dropping columns on SQLite under Laravel 9 needs doctrine/dbal;
 * remove them in the Laravel 12 upgrade.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('page_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->longText('snapshot');
            $table->string('cause', 32)->default('edit');
            $table->timestamp('created_at')->nullable();
        });

        // Preserve each user's published page as their first version.
        // Its image paths point at the existing published/ copies, which
        // restore reads from directly — no file moves needed here.
        DB::table('users')
            ->whereNotNull('published_snapshot')
            ->orderBy('id')
            ->chunkById(100, function ($users) {
                foreach ($users as $u) {
                    DB::table('page_versions')->insert([
                        'user_id'    => $u->id,
                        'snapshot'   => $u->published_snapshot,
                        'cause'      => 'published',
                        'created_at' => now(),
                    ]);
                }
            });
    }

    public function down()
    {
        Schema::dropIfExists('page_versions');
    }
};
