<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Portal identities live in the existing JSP user table.
 *
 * @property int $Id
 * @property string $username
 * @property int $IsSA
 * @property string $truename
 * @property int $departmentId
 */
class User extends Authenticatable
{
    protected $connection = 'legacy';

    protected $table = 'user';

    protected $primaryKey = 'Id';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['psw', 'IDNumber', 'Resume', 'telephone', 'address'];

    public function getNameAttribute(): string
    {
        return (string) ($this->attributes['truename'] ?? $this->attributes['username'] ?? '');
    }

    public function getEmailAttribute(): ?string
    {
        return $this->attributes['email'] ?? null;
    }
}
