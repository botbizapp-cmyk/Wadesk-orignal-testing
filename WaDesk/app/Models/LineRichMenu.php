<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A LINE rich menu — the tappable image panel pinned to the bottom of a chat.
 * `size`/`areas` hold the exact LINE payload; `rich_menu_id` is LINE's id once
 * the menu is registered. One OA can have many menus but only one default.
 */
class LineRichMenu extends Model
{
    protected $fillable = [
        'workspace_id', 'line_channel_id', 'created_by', 'rich_menu_id', 'name',
        'chat_bar_text', 'layout', 'size', 'areas', 'image_path', 'is_default',
        'active', 'last_error',
    ];

    protected $casts = [
        'size'       => 'array',
        'areas'      => 'array',
        'is_default' => 'boolean',
        'active'     => 'boolean',
    ];

    public function channel()
    {
        return $this->belongsTo(LineChannel::class, 'line_channel_id');
    }

    /**
     * The standard LINE layout presets (bounds are absolute px within the menu
     * image). Large canvas = 2500×1686, compact = 2500×843. Each cell gets one
     * action assigned in the composer.
     *
     * @return array<string, array{label:string, w:int, h:int, cells:array<int, array{x:int,y:int,width:int,height:int}>}>
     */
    public static function layouts(): array
    {
        $W = 2500; $L = 1686; $C = 843;

        return [
            'full'        => ['label' => 'Full image (1 tap)',     'w' => $W, 'h' => $L, 'cells' => [['x'=>0,'y'=>0,'width'=>$W,'height'=>$L]]],
            'large-2col'  => ['label' => 'Large · 2 columns',      'w' => $W, 'h' => $L, 'cells' => [['x'=>0,'y'=>0,'width'=>1250,'height'=>$L],['x'=>1250,'y'=>0,'width'=>1250,'height'=>$L]]],
            'large-3col'  => ['label' => 'Large · 3 columns',      'w' => $W, 'h' => $L, 'cells' => [['x'=>0,'y'=>0,'width'=>833,'height'=>$L],['x'=>833,'y'=>0,'width'=>834,'height'=>$L],['x'=>1667,'y'=>0,'width'=>833,'height'=>$L]]],
            'large-2x2'   => ['label' => 'Large · 2×2 grid',       'w' => $W, 'h' => $L, 'cells' => [['x'=>0,'y'=>0,'width'=>1250,'height'=>843],['x'=>1250,'y'=>0,'width'=>1250,'height'=>843],['x'=>0,'y'=>843,'width'=>1250,'height'=>843],['x'=>1250,'y'=>843,'width'=>1250,'height'=>843]]],
            'large-2x3'   => ['label' => 'Large · 2×3 grid',       'w' => $W, 'h' => $L, 'cells' => [['x'=>0,'y'=>0,'width'=>833,'height'=>843],['x'=>833,'y'=>0,'width'=>834,'height'=>843],['x'=>1667,'y'=>0,'width'=>833,'height'=>843],['x'=>0,'y'=>843,'width'=>833,'height'=>843],['x'=>833,'y'=>843,'width'=>834,'height'=>843],['x'=>1667,'y'=>843,'width'=>833,'height'=>843]]],
            'compact-1'   => ['label' => 'Compact · full (1 tap)', 'w' => $W, 'h' => $C, 'cells' => [['x'=>0,'y'=>0,'width'=>$W,'height'=>$C]]],
            'compact-2col'=> ['label' => 'Compact · 2 columns',    'w' => $W, 'h' => $C, 'cells' => [['x'=>0,'y'=>0,'width'=>1250,'height'=>$C],['x'=>1250,'y'=>0,'width'=>1250,'height'=>$C]]],
            'compact-3col'=> ['label' => 'Compact · 3 columns',    'w' => $W, 'h' => $C, 'cells' => [['x'=>0,'y'=>0,'width'=>833,'height'=>$C],['x'=>833,'y'=>0,'width'=>834,'height'=>$C],['x'=>1667,'y'=>0,'width'=>833,'height'=>$C]]],
        ];
    }
}
