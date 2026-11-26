<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a template document adds to piece 2's table (documents design §6.1,
 * §11.2):
 * - `card_floor`: where the signature cards stop above the letterhead's
 *   footer, in mm from the page's foot — 32 for a template printed with a
 *   footer image, null for every other document (piece 2's 6 mm). Stored, so
 *   a rebuild of the printed copy draws the same picture;
 * - `content_bottom`: where the text of the original's last page ends, in mm
 *   from that page's top (ruling R17), so the cards take a new page rather
 *   than cover it — null for an upload, whose text end is unknown. Stored
 *   for the same reason;
 * - an index on `template_id`, which has no foreign key (a template is
 *   soft-deleted, and a document keeps it): whether a template is in use is
 *   asked of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedTinyInteger('card_floor')->nullable()->after('printed_path');
            $table->decimal('content_bottom', 6, 2)->nullable()->after('card_floor');
            $table->index('template_id');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['template_id']);
            $table->dropColumn(['card_floor', 'content_bottom']);
        });
    }
};
