<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A declarative provisioning plugin (JSON field map). See JsonProvisioner. */
class ProvisionerDefinition extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['enabled' => 'boolean'];
}
