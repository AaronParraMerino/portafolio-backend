<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProyectoGithub extends Model
{
    protected $table = 'proyecto_github';

    protected $primaryKey = 'id_proyecto_github';

    protected $fillable = [
        'id_proyecto',
        'github_repo_id',
        'github_owner',
        'github_repo_name',
        'github_url',
        'github_description',
        'github_homepage',
        'default_branch',
        'is_private',
        'is_fork',
        'is_archived',
        'stars_count',
        'forks_count',
        'open_issues_count',
        'commits_count',
        'contributors_count',
        'last_commit_message',
        'last_commit_date',
        'last_push_at',
        'github_created_at',
        'github_updated_at',
        'readme_resumen',
        'sync_status',
        'sync_error',
        'last_sync_at',
    ];

    protected $casts = [
        'is_private' => 'boolean',
        'is_fork' => 'boolean',
        'is_archived' => 'boolean',
        'last_commit_date' => 'datetime',
        'last_push_at' => 'datetime',
        'github_created_at' => 'datetime',
        'github_updated_at' => 'datetime',
        'last_sync_at' => 'datetime',
    ];

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class, 'id_proyecto', 'id_proyecto');
    }
}