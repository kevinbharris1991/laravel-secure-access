<?php

namespace SecureAccess\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SecureAccess\Models\UserEmail;
use SecureAccess\SecureAccess;

/**
 * Finds a user by username, primary email address or any verified secondary email address.
 */
class UserResolver
{
    public function find(string $identifier): ?Model
    {
        $identifier = mb_strtolower(trim($identifier));

        if ($identifier === '') {
            return null;
        }

        $username = $this->usernameColumn();

        if ($username && ($user = $this->whereLower($username, $identifier)->first())) {
            return $user;
        }

        if (! str_contains($identifier, '@')) {
            return null;
        }

        if ($user = $this->whereLower(config('secure-access.email_column', 'email'), $identifier)->first()) {
            return $user;
        }

        $email = UserEmail::query()
            ->where('email', $identifier)
            ->whereNotNull('verified_at')
            ->first();

        return $email ? SecureAccess::newUserQuery()->find($email->user_id) : null;
    }

    /**
     * Determine whether the email address is already used by any account
     * (as a primary address or as a verified additional address).
     */
    public function emailInUse(string $email): bool
    {
        $email = mb_strtolower(trim($email));

        return UserEmail::query()->where('email', $email)->whereNotNull('verified_at')->exists()
            || $this->whereLower(config('secure-access.email_column', 'email'), $email)->exists();
    }

    public function usernameColumn(): ?string
    {
        $column = config('secure-access.username_column', 'username');
        $model = SecureAccess::newUserQuery()->getModel();

        return $column && $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $column)
            ? $column
            : null;
    }

    protected function whereLower(string $column, string $value): Builder
    {
        $query = SecureAccess::newUserQuery();
        $wrapped = $query->getQuery()->getGrammar()->wrap($query->getModel()->qualifyColumn($column));

        return $query->whereRaw("lower({$wrapped}) = ?", [$value]);
    }
}
