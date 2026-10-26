<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('remix_jobs', function (Blueprint $table) {
            $table->string('variant', 1)->default('a')->after('media_id');
            $table->index(['media_id', 'variant', 'status']);
        });
    }
    public function down(): void {
        Schema::table('remix_jobs', function (Blueprint $table) {
            $table->dropIndex(['media_id', 'variant', 'status']);
            $table->dropColumn('variant');
        });
    }
};
