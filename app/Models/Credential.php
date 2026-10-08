<?php

namespace App\Models;

use App\Casts\SealedSecretCast;
use App\Enums\CredentialKind;
use Database\Factories\CredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['site_id', 'label', 'kind', 'username', 'secret', 'url', 'notes'])]
#[Hidden(['secret'])]
class Credential extends Model
{
    /** @use HasFactory<CredentialFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CredentialKind::class,
            'secret' => SealedSecretCast::class,
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
