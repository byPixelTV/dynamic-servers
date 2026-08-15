<?php

namespace ByPixelTV\Dynamicservers\Policies;

use App\Models\User;
use ByPixelTV\Dynamicservers\Models\DynamicTemplate;

class DynamicTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view dynamicTemplate');
    }

    public function view(User $user, DynamicTemplate $template): bool
    {
        return $user->can('view dynamicTemplate');
    }

    public function create(User $user): bool
    {
        return $user->can('create dynamicTemplate');
    }

    public function update(User $user, DynamicTemplate $template): bool
    {
        return $user->can('update dynamicTemplate');
    }

    public function delete(User $user, DynamicTemplate $template): bool
    {
        return $user->can('delete dynamicTemplate');
    }
}
