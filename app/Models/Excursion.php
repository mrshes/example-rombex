<?php

namespace App\Models;

use App\Models\Traits\getAttributeTrait;
use App\Scopes\WithoutBlockedUserScope;
use App\Services\Excursion\ExcursionService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class Excursion extends Model
{
    use SoftDeletes, getAttributeTrait;

    protected $casts = [
        'props' => 'array',
        'video' => 'array',
        'price_adult' => 'integer',
        'price_children' => 'integer',
    ];

    protected $subtypes = [
        'group',
        'individual',
        'personal'
    ];

    protected $types = [
        'exc',
        'tour',
        'vip',
    ];

    protected $appends = [
        'rating',
    ];


    protected $hidden = ['created_at', 'updated_at', 'deleted_at'];


    protected $statuses = [
        'ACTIVE',
        'DISABLED',
    ];

    /**
     * @return array
     */


    /*********************************************RELATIONS****************************************************/

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable', 'rel_type', 'rel_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function files()
    {
        return $this->morphMany(File::class, 'fileable', 'rel_type', 'rel_id');
    }

    public function images()
    {
        return $this->morphMany(File::class, 'fileable', 'rel_type', 'rel_id')->whereType('images')->orderBy('sort');
    }

    public function maps()
    {
        return $this->morphMany(Map::class, 'mapable', 'rel_type', 'rel_id');
    }

    public function times()
    {
        return $this->hasMany(ExcursionTime::class)->orderBy('time');
    }

    public function points(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ExcursionTimePoint::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function qrCode()
    {
        return $this->morphOne(QrCode::class, 'qr_codeable');
    }

    /*********************************************Scoped****************************************************/


    /**
     * Фильтр для глобального ограничения отображения экскурсий
     * @param $query
     * @return mixed
     */
    public function scopeFilterDisplay($query)
    {
        return $query->withoutBlockedUser()
            ->where('status', 'ACTIVE');
    }

    /**
     * Показать экскурсии только активных пользователей
     * @param $query
     * @return mixed
     */
    public function scopeWithoutBlockedUser($query)
    {
        return $query->whereDoesntHave('user', function ($q) {
            $q->isBlocked();
        });
    }

    /**
     * @param $query
     * @param int $user_id
     * @return mixed
     */
    public function scopeWhereUser($query, int $user_id)
    {
        return $query->where('user_id', $user_id);
    }



    /*********************************************MUTATIONS****************************************************/

    /**
     * @return int
     */
    public function getRatingAttribute()
    {
        $comments = $this->comments()->avg('like');
        return (int)$comments;
    }


    public function getDurationAttribute($value)
    {
        return Carbon::parse($value)->format('H:i');
    }


    /*********************************************METHODS****************************************************/

    /**
     * @return array
     */
    public function getStatuses(): array
    {
        return $this->statuses;
    }

    public function setStatus($id)
    {
        $this->status = $this->getStatuses()[$id];
        $this->save();
        return $this;
    }

    /**
     * @return string[]
     */
    public function getSubtypes(): array
    {
        return $this->subtypes;
    }

    /**
     * @return string[]
     */
    public function getTypes(): array
    {
        return $this->types;
    }



    /**
     * @param $value
     * @return mixed|null
     */
    public function getDurationTime($value)
    {
        $times = [
            '00:30:00',
            '1:00:00',
            '1:30:00',
            '2:00:00',
            '6:00:00',
            '12:00:00',
            '24:00:00',
        ];
        if (array_key_exists($value, $times)) return $times[$value];
        return null;
    }

    /**
     * @return ExcursionService
     */
    public function getService(): ExcursionService
    {
        return new ExcursionService($this);
    }

}
