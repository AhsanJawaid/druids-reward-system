<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GraphqlLog extends Model
{
    protected $fillable = [
        'operation',
        'mocked',
        'query',
        'variables',
        'response',
        'http_status',
    ];

    protected function casts(): array
    {
        return [
            'mocked' => 'boolean',
            'variables' => 'array',
            'response' => 'array',
        ];
    }
}
