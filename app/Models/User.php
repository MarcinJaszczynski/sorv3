<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
    ];

    protected $casts = [
        'status' => 'string',
    ];

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

    /**
     * Rozmowy użytkownika
     */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
                    ->withPivot(['joined_at', 'last_read_at'])
                    ->withTimestamps();
    }

    /**
     * Wiadomości użytkownika
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Rozmowy utworzone przez użytkownika
     */
    public function createdConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'created_by');
    }

    /**
     * Sprawdź czy użytkownik jest online (można rozszerzyć o cache/redis)
     */
    public function isOnline(): bool
    {
        // Placeholder - można zaimplementować przez cache lub ostatnią aktywność
        return $this->updated_at?->diffInMinutes() < 5;
    }
}
