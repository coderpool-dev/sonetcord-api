<?php

namespace App\Models\Conversations;

use App\Enums\ChannelType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ChannelType $status тип канала: колонка исторически называется status
 * @property int $unread_count
 * @property int $missed_calls
 * @property string|null $messages_max_created_at
 */
class Channel extends Model
{
    use HasFactory;

    protected $table = 'channels';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'status',
        'avatar',
    ];

    protected $casts = [
        'status' => ChannelType::class,
    ];

    /** @return HasMany<ChannelMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ChannelMember::class, 'channels_id');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'channels_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'channels_members', 'channels_id', 'users_id');
    }
}
