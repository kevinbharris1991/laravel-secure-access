<?php

namespace SecureAccess\Camp;

use Illuminate\Support\Collection;

class GuestMeals
{
    public static function present(Collection $meals, Collection $substitutions): Collection
    {
        $periods = ['breakfast' => 0, 'lunch' => 1, 'dinner' => 2];
        $specials = $substitutions
            ->filter(fn ($record) => ($record->data['date'] ?? '') !== '' && isset($periods[$record->data['period'] ?? '']))
            ->groupBy(fn ($record) => self::slot($record->data));

        return $meals
            ->filter(fn ($record) => isset($periods[$record->data['period'] ?? '']))
            ->sortBy(fn ($record) => ($record->data['date'] ?? '').$periods[$record->data['period']])
            ->map(function ($record) use ($specials) {
                $data = $record->data;
                $matches = $specials->get(self::slot($data), collect())
                    ->filter(fn ($special) => in_array($special->data['incompatible'] ?? false, [true, 1, '1'], true));

                return [
                    'date' => $data['date'] ?? '',
                    'period' => $data['period'],
                    'name' => $data['name'] ?? '',
                    'allergens' => is_array($data['allergens'] ?? null)
                        ? implode(', ', $data['allergens'])
                        : ($data['allergens'] ?? ''),
                    'incompatible' => $matches->isNotEmpty(),
                    'substitutions' => $matches->map(fn ($special) => $special->data['name'] ?? '')->filter()->values(),
                ];
            })->values();
    }

    private static function slot(array $data): string
    {
        return ($data['date'] ?? '').'|'.($data['period'] ?? '');
    }
}
