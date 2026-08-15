<?php

namespace ByPixelTV\Dynamicservers\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $dynamic_template_id
 * @property int $server_id
 */
class DynamicTemplateServer extends Model
{
    protected $fillable = [
        'dynamic_template_id',
        'server_id',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(
            DynamicTemplate::class,
            'dynamic_template_id'
        );
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
