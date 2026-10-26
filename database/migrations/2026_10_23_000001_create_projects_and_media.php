<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id(); $table->string('name', 120); $table->text('description')->nullable(); $table->timestamps();
        });
        Schema::create('media', function (Blueprint $table) {
            $table->id(); $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('original_name'); $table->string('path'); $table->string('mime_type');
            $table->unsignedBigInteger('size'); $table->double('duration');
            $table->unsignedInteger('width'); $table->unsignedInteger('height'); $table->string('codec');
            $table->double('trim_start')->default(0); $table->double('trim_end'); $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('media'); Schema::dropIfExists('projects'); }
};
