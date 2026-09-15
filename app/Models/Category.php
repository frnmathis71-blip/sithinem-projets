<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property string $name */
class Category extends Model
{
    public const LABELS = ['starter' => 'Entrée', 'main' => 'Plat', 'chef_main' => 'Plat du chef', 'side' => 'Accompagnement', 'dessert' => 'Dessert', 'drink' => 'Boisson'];

    protected $guarded = [];
}
