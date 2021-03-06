<?php

namespace App;

use Auth;
use App\Helpers\DateHelper;
use App\Events\Task\TaskCreated;
use App\Events\Task\TaskDeleted;
use App\Events\Task\TaskUpdated;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $dates = ['completed_at'];

    protected $events = [
        'created' => TaskCreated::class,
        'updated' => TaskUpdated::class,
        'deleted' => TaskDeleted::class,
    ];

    public function getTitle()
    {
        if (is_null($this->title)) {
            return null;
        }

        return decrypt($this->title);
    }

    public function getDescription()
    {
        if (is_null($this->description)) {
            return null;
        }

        return decrypt($this->description);
    }

    public function getCreatedAt()
    {
        return $this->created_at;
    }

    /**
     * Change the status of the task from in progress to complete, or the other
     * way around.
     */
    public function toggle()
    {
        if ($this->status == 'completed') {
            $this->status = 'inprogress';
        } else {
            $this->status = 'completed';
        }
        $this->save();
    }
}
