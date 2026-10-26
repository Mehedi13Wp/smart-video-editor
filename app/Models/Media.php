<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Media extends Model
{
    protected $table = 'media';
    protected $guarded = ['id'];
    protected $hidden = ['path'];
    protected function casts(): array { return ['duration' => 'float', 'trim_start' => 'float', 'trim_end' => 'float']; }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function isInboxSource(): bool { return str_starts_with($this->path, 'incoming/'); }
}
