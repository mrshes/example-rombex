<?php


namespace App\Services\Excursion;


use App\Services\BaseRepository;
use App\Models\Excursion;
use App\Models\ExcursionTime;
use App\Models\ExcursionTimePoint;
use App\Models\File;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use phpDocumentor\Reflection\Types\Collection;

class ExcursionRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Excursion::class;
    }



}
