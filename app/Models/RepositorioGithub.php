<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RepositorioGithub extends Model
{
    protected $table = 'repositorio_github';
    protected $primaryKey = 'id_repositorio_github';

    protected $fillable = [
        'id_proyecto_repositorio',
        'github_repo_id',
        'github_owner',
        'github_repo_name',
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
        'repo_created_at',
        'repo_updated_at',
        'readme_resumen',
        'sync_status',
        'sync_error',
        'last_sync_at',
    ];

    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'is_fork' => 'boolean',
            'is_archived' => 'boolean',
            'last_commit_date' => 'datetime',
            'last_push_at' => 'datetime',
            'repo_created_at' => 'datetime',
            'repo_updated_at' => 'datetime',
            'last_sync_at' => 'datetime',
        ];
    }
}
