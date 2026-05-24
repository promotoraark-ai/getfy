<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'username',
        'avatar',
        'password',
        'role',
        'tenant_id',
        'team_role_id',
    ];

    public const ROLE_ADMIN = 'admin';
    public const ROLE_INFOPRODUTOR = 'infoprodutor';
    public const ROLE_ALUNO = 'aluno';
    public const ROLE_TEAM = 'team';

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isInfoprodutor(): bool
    {
        return $this->role === self::ROLE_INFOPRODUTOR;
    }

    public function isAluno(): bool
    {
        return $this->role === self::ROLE_ALUNO;
    }

    public function isTeam(): bool
    {
        return $this->role === self::ROLE_TEAM;
    }

    public function canAccessPanel(): bool
    {
        return $this->isAdmin() || $this->isInfoprodutor() || $this->isTeam();
    }

    public function teamRole(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(TeamRole::class, 'team_role_id');
    }

    public function products(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_user')->withTimestamps();
    }

    public function subscriptions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function savedPaymentMethods(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SavedPaymentMethod::class);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
