<?php

namespace ByPixelTV\Dynamicservers\Models;

use App\Models\Egg;
use App\Models\Node;
use App\Models\User;
use ByPixelTV\Dynamicservers\Jobs\AutoScaleDynamicTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $egg_id
 * @property int|null $node_id
 * @property int|null $owner_id
 * @property int $memory
 * @property int $disk
 * @property int $cpu
 * @property int $port_range_start
 * @property int $port_range_end
 * @property int $min_servers
 * @property bool $auto_creation
 * @property string|null $image
 * @property string|null $startup
 */
class DynamicTemplate extends Model
{
    protected $fillable = [
        'name',
        'egg_id',
        'node_id',
        'owner_id',
        'startup_variables',
        'memory',
        'disk',
        'cpu',
        'port_range_start',
        'port_range_end',
        'min_servers',
        'auto_creation',
        'files',
        'image',
        'startup',
    ];

    protected $casts = [
        'startup_variables' => 'array',
        'files' => 'array',
        'auto_creation' => 'boolean',
    ];

    public function egg(): BelongsTo
    {
        return $this->belongsTo(Egg::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    protected static function booted(): void
    {
        static::saved(function (DynamicTemplate $template): void {
            if (!$template->auto_creation) {
                return;
            }

            if (
                !$template->wasRecentlyCreated
                && !$template->wasChanged([
                    'auto_creation',
                    'min_servers',
                    'node_id',
                    'egg_id',
                    'owner_id',
                    'port_range_start',
                    'port_range_end',
                ])
            ) {
                return;
            }

            AutoScaleDynamicTemplate::dispatch(
                $template->getKey()
            )->delay(
                now()->addSecond()
            );
        });
    }
}
