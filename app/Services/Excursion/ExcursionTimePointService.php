<?php

namespace App\Services\Excursion;

use App\Models\ExcursionTime;
use App\Models\ExcursionTimePoint;

class ExcursionTimePointService extends \App\Services\BaseService
{
    public function storeTimePoints(ExcursionTime $timeEntry, array $points)
    {
        // Сохраняем точкии сбора принадлежащие этому времени
        $pointsIds = [];
        foreach ($points as $point) {
            $map = $point['map'];
            $point['excursion_id'] = $timeEntry->excursion_id;
            /** @var ExcursionTimePoint $recordPoint */
            $recordPoint = $timeEntry->points()->updateOrCreate(['id' => $point['id'] ?? null], $point);

            // Обновляем данные карты
            // Эту странную конструкцию скопировал из map()->store();
            // $map['items'] = $map;
            $recordPoint->map()->updateOrCreate(['rel_type' => $map['rel_type'] ?? get_class($recordPoint), 'rel_id' => $map['rel_id'] ?? $recordPoint->id], $map);

            array_push($pointsIds, $recordPoint->id);

        }

        if (count($pointsIds)) {
            $timeEntry->points()->whereNotIn('id', $pointsIds)->get()->each(function ($item) {
                // Удаляем данные точки с карты
                $item->map()->forceDelete();
                // Удаляем точку
                $item->delete();
            });
        }
    }
}