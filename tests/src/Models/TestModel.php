<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Tests\Mockup\Models;

use Lsr\Core\Models\Attributes\PrimaryKey;
use Lsr\Core\Models\Model;

#[PrimaryKey('id_model')]
class TestModel extends Model
{
    public const TABLE = 'test';

    public string $name = '';

}
