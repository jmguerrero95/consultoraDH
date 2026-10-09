<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DocumentTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentType extends Model
{
    /** @use HasFactory<DocumentTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'retention_days',
        'client_visible_default',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'retention_days' => 'integer',
            'client_visible_default' => 'boolean',
            'active' => 'boolean',
        ];
    }
}
