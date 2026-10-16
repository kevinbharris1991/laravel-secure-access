<?php

namespace SecureAccess\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use SecureAccess\Authorization\AccessScope;

class CampRecord extends Model implements AccessScope
{
    protected $table = 'secure_access_camp_records';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'is_public' => 'boolean'];
    }

    public function isOwnedBy(Authenticatable $user): bool
    {
        return $user instanceof Model
            && $this->owner_type === $user->getMorphClass()
            && (string) $this->owner_id === (string) $user->getKey();
    }

    public function isAssignedTo(Authenticatable $user): bool
    {
        return $user instanceof Model && $this->camper_id !== null
            && CampAssignment::query()->where('user_type', $user->getMorphClass())
                ->where('user_id', (string) $user->getKey())->where('camper_id', $this->camper_id)->exists();
    }
}
