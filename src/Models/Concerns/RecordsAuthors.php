<?php

namespace Nit\AgraniHr\Models\Concerns;

use Nit\Master\Models\Concerns\HasOwner;

/**
 * created_by (nit-master's HasOwner) and updated_by: soft references to users.id without a foreign key,
 * because nit-master force-deletes trashed users.
 */
trait RecordsAuthors
{
    use HasOwner;

    public static function bootRecordsAuthors(): void
    {
        static::saving(function (self $record): void {
            if (auth()->check() && $record->isDirty() && ! $record->isDirty('updated_by')) {
                $record->updated_by = auth()->id();
            }
        });
    }
}
