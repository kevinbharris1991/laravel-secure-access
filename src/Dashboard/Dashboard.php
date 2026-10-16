<?php

namespace SecureAccess\Dashboard;

use Illuminate\Contracts\Auth\Authenticatable;

class Dashboard
{
    /**
     * @param iterable<string, DashboardItem> $items
     */
    public function resolveFor(?Authenticatable $user, iterable $items): array
    {
        $visible = [];

        foreach ($items as $key => $item) {
            if ($item->visibleTo($user)) {
                $visible[$key] = $item->resolveFor($user);
            }
        }

        return $visible;
    }
}
