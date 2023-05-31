<?php

namespace App\Services\Excursion;

use App\Models\Excursion;
use App\Models\ExcursionTimePoint;

class ExcursionTimeService extends \App\Services\BaseService
{
    /**
     * Сохранение времени и точек сборов к ним
     * @param array $times
     */
    public function storeExcTimes(Excursion $excursion, array $times)
    {
        $model = $excursion;
        if ($times) {
            $timeSaveId = [];
            foreach ($times as $time) {
                $timeId = $time['id'] ?? null;
                $timeEntry = $model->times()->firstOrCreate(['id' => $timeId], $time);
                // Если сеанс существует, обновляем его
                if ($timeEntry->id == $timeId) {
                    $timeEntry->fill($time);
                    $timeEntry->save();
                }
                array_push($timeSaveId, $timeEntry->id);

                // Записываем точки, если они переданы
                $points = $time['points'] ?? null;
                if ($points){
                    $pointsEntry = (new ExcursionTimePointService())->storeTimePoints($timeEntry, $points);
                }
            }

            if (count($timeSaveId)) {
                $model->times()->whereNotIn('id', $timeSaveId)->delete();
//                get()->each(function ($item) {
//                    $item->delete();
//                });
            }
        }
    }
}