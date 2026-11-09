<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MenuResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'          => $this->id,
            'parent_id'   => $this->parent_id,
            'type'        => $this->type,
            'name'        => $this->name,
            'path'        => $this->path,
            'component'   => $this->component,
            'redirect'    => $this->redirect,
            'title'       => $this->title,
            'icon'        => $this->icon,
            'permission'  => $this->permission,
            'sort'        => $this->sort,
            'hidden'      => (bool) $this->hidden,
            'keep_alive'  => (bool) $this->keep_alive,
            'affix_tab'   => (bool) $this->affix_tab,
            'iframe_src'  => $this->iframe_src,
            'link'        => $this->link,
            'status'      => (bool) $this->status,
            'created_at'  => $this->created_at?->toDateTimeString(),
            'updated_at'  => $this->updated_at?->toDateTimeString(),
        ];
    }
}