<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('remix_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedSmallInteger('target_duration');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('output_path')->nullable();
            $table->string('error')->nullable();
            $table->json('analysis')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('remix_jobs'); }
};
