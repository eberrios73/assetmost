<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One @-mention edge. See the object_refs migration for the idea. */
class ObjectRef extends Model
{
    protected $guarded = ['id'];
}
