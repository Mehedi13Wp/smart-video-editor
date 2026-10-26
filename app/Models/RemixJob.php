<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemixJob extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['output_path'];
    protected function casts(): array { return ['analysis' => 'array', 'progress' => 'integer', 'target_duration' => 'integer']; }
    public function media(): BelongsTo { return $this->belongsTo(Media::class); }
    public function directory(): string { return "projects/{$this->project_id}/remixes/{$this->id}"; }
    public function resultPath(): string { return $this->directory().'/highlights.mp4'; }
}
