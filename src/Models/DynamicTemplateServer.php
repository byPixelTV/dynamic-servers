<?php

namespace ByPixelTV\Dynamicservers\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $dynamic_template_id
 * @property int $server_id
 * @property-read DynamicTemplate|null $template
 * @property-read Server|null $server
 */
class DynamicTemplateServer extends Model
{
    protected $fillable = [
        'dynamic_template_id',
        'server_id',
    ];

    /**
     * @return BelongsTo<DynamicTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(
            DynamicTemplate::class,
            'dynamic_template_id'
        );
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(
            Server::class,
            'server_id'
        );
    }
}
