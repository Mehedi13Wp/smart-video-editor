<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Project extends Model
{
    protected $fillable = ['name', 'description'];
    public function media(): HasMany { return $this->hasMany(Media::class); }
}
